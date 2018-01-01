<?php

namespace App\Http\Controllers;

use App\Models\ArchivedProgram;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Program;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Nathanmac\Utilities\Parser\Parser;

class TvGuideController extends Controller
{
    public function ajaxTable($request, $items, $timeLinePosition)
    {

        if ($request->get('old-date') == $request->get('date') && !Input::has('refresh-page')) {

            $channels = view('tv-guide.ajax._ajax-channels', compact('items'))->render();
            $programs = view('tv-guide.ajax._ajax-programs', compact('items'))->render();

            return response()->json([
                'channels' => $channels,
                'programs' => $programs
            ]);

        } else {

            $html = view('tv-guide.ajax._ajax-full-table', compact('items', 'timeLinePosition'))->render();

            return response()->json([
                'html' => $html
            ]);

        }
    }

    public function index(Request $request)
    {

        $provider = ($request->segment(2) == 'usa') ? 'usa' : 'sky';

        $items = Channel::byProvider($provider);

        if (Input::has('channels'))
            $items = $items->whereIn('id', Input::get('channels'));

        if (Input::has('categories')) {
            $items = $items->whereHas('datePrograms', function ($query) {
                $query->fromCategories(Input::get('categories'));
            })->with(['datePrograms' => function ($query) {
                $query->fromCategories(Input::get('categories'));
            }])->with('datePrograms.archived');
        } else {
            $items = $items
                ->with('dateYesterdayPrograms.archived')
                ->with('datePrograms.archived');
        }

        if (Route::currentRouteName() == 'guide.usa.archived' || Route::currentRouteName() == 'guide.sky.archived')
            $items = $items->archived();

        $items = $items->paginate(10);

        /**
         * Add red time line if it's today
         */

        $date = $request->get('date') ? Carbon::createFromTimestamp(strtotime($request->get('date'))) : Carbon::today();

        if (Carbon::today() == $date) {
            $timeLinePosition = intval(config('constants.tv-table.1min-width') * Carbon::now()
                    ->setTimezone(Auth::user()->timezone())->secondsSinceMidnight() / 60);
        } else {
            $timeLinePosition = false;
        }

        /**
         * Ajax queries (scroll, dates, search)
         */
        if ($request->ajax()) {
            return ($items->isEmpty())
                ? response()->json(['empty' => true])
                : $this->ajaxTable($request, $items, $timeLinePosition);
        }

        $categories = Category::orderBy('title', 'ASC')->pluck('title', 'id');
        $channels = Channel::byProvider($provider)->pluck('title', 'id');

        $timezoneList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

        return view('tv-guide.table.index', compact('items', 'categories', 'timeLinePosition', 'timezoneList', 'channels'));
    }

    /**
     * @return int offset in px for table wrappers
     */
    public static function getTimeLineOffset()
    {
        $hours = intval(floor(Carbon::now()->setTimezone(Auth::user()->timezone())->secondsSinceMidnight() / 60 / 60));

        return $hours ? intval(config('constants.tv-table.1min-width') * $hours * 60) : 0;
    }

    public static function getDate()
    {
        $date = Carbon::now()->setTimezone(Auth::user()->timezone());

        if (Input::has('date')) {
            $dateParse = explode('-', Input::get('date'));
            if (count($dateParse) != 3) abort(404);
            $date = $date->setDate($dateParse[0], $dateParse[1], $dateParse[2]);
        }

        return $date;
    }

    public function updateChannelArchivedStatus($id, $val)
    {
        $channel = Channel::findOrFail($id);
        $channel->archived = $val;
        $channel->save();

        return route('guide.updateChannelStatus', [$id, $val ? 0 : 1]);
    }

    public function updateProgramArchivedStatus($id, $val)
    {
        $program = Program::findOrFail($id);

        if ($val)
            $program->createVodXmlFile();

        $ap = ArchivedProgram::firstOrNew(['program_id' => $program->id]);
        $ap->path = $program->createEndVideoPath();
        $ap->status = $val;
        $ap->save();

        return route('guide.updateProgramStatus', [$id, $val ? 0 : 1]);
    }

    public function setChannelsUrl(Request $request)
    {

        $items = Channel::archived()->orderBy('url')->get();

        if ($request->isMethod('get')) {
            return view('tv-guide.set-channels-url', compact('items'));
        } else {

            $ids = $request->get('id');
            $categories = $request->get('category');
            $additionalCategories = $request->get('additional_category');

            foreach ($items as $item) {

                if (isset($ids[$item->id])) {
                    $item->url = $ids[$item->id];

                    $settings['category'] = $categories[$item->id];
                    $settings['additional_category'] = $additionalCategories[$item->id];

                    $item->settings = $settings;

                    $item->save();
                }

            }

            return redirect()->back();
        }

    }

    public function setProgramsUrl(Request $request)
    {

        $items = ArchivedProgram::active()->with('program.channel')->orderBy('updated_at', 'DESC')->get();

        if ($request->isMethod('get')) {
            return view('tv-guide.set-programs-url', compact('items'));
        } else {


            /*
             * Upload Vod Files
             */

            $uploadIds = $request->has('upload') ? $request->get('upload') : false;

            if ($uploadIds) {

                foreach ($uploadIds as $id => $on) {

                    VodController::uploadVodXml($id);

                }

            }

            /*
             * Delete Vod Files
             */

            $deleteIds = $request->has('delete') ? $request->get('delete') : false;

            if ($deleteIds) {

                foreach ($deleteIds as $id => $on) {

                    VodController::deleteVod($id);

                }

            }

            return redirect()->route('guide.setProgramsUrl');
        }

    }
}
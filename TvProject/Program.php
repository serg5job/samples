<?php

namespace App\Models;

use App\Http\Controllers\TvGuideController;
use App\Http\Controllers\VodController;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * App\Models\Program
 *
 * @mixin \Eloquent
 * @property integer $id
 * @property integer $channel_id
 * @property integer $category_id
 * @property integer $length
 * @property string $title
 * @property string $start_at
 * @property string $end_at
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereId($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereChannelId($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereCategoryId($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereLength($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereTitle($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereStartAt($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereEndAt($value)
 * @property-read \App\Models\Category $category
 * @property-read \App\Models\Channel $channel
 * @property boolean $archived
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereArchived($value)
 * @property string $info
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Program whereInfo($value)
 */
class Program extends Model
{

    const VOD_XML_PATTERN = '
<vod>
<settings check_if_exists="0" clear_database_before="0"/>
<video category=":_category_:" additional_categories=":_additional_category_:" url=":_url_:" protection_type="akamai" title=":_title_:" production_company="" poster=":_poster_:" duration=":_duration_:" language="" year=":_year_:" month=":_month_:" day=":_day_:" description=":_desc_:" director="" acting="" details="" price="0.0" tags="" parent_folder_id="3"/>
</vod>';

    public $timestamps = false;

    protected $dates = [
        'start_at',
        'end_at'
    ];

    protected $casts = [
        'info' => 'array'
    ];

    protected $fillable = ['channel_id', 'start_at'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function getTitleAttribute($val)
    {
        return trim(str_replace('&amp;apos;', "'", $val));
    }

    public function getStartAtAttribute($val)
    {
        return Carbon::parse($val, 'utc')->setTimezone(Auth::user()->timezone());
    }

    public function getEndAtAttribute($val)
    {
        return Carbon::parse($val, 'utc')->setTimezone(Auth::user()->timezone());
    }

    public static function availableDates()
    {
        return Program::distinct()
            ->selectRaw('DATE_FORMAT(start_at,\'%Y-%m-%d\') AS date')
            ->orderBy('date')->get();
    }

    public static function scopeFromCategories($query, $categories = false)
    {
        if ($categories)
            return $query->whereIn('category_id', $categories);
    }

    public static function scopeByDate($query)
    {
        $date = TvGuideController::getDate();
        /**
         * @var Carbon $date
         */

        $from = $date->setTime(0, 0)->setTimezone('utc')->format('Y-m-d G:i');
        $to = $date->setTimezone(Auth::user()->timezone())->setTime(23, 59)->setTimezone('utc')->format('Y-m-d G:i');


        return $query->whereBetween('start_at', [$from, $to])
            ->orderBy('start_at');
    }

    public static function scopeByPreviousDate($query)
    {
        $date = TvGuideController::getDate();
        /**
         * @var Carbon $date
         */

        $from = $date->setTime(0, 0)->subDay(1)->setTimezone('utc')->format('Y-m-d G:i');
        $to = $date->setTimezone(Auth::user()->timezone())->setTime(23, 59)->setTimezone('utc')->format('Y-m-d G:i');

        return $query->whereBetween('start_at', [$from, $to])
            ->orderBy('start_at');
    }

    public function archived()
    {
        return $this->hasOne(ArchivedProgram::class);
    }


    public function createEndVideoPath()
    {
        if ($this->archived) return $this->archived->path;

        $url = Carbon::parse($this->info['@start'])->timestamp . '-' . (intval($this->info['length']['#text']) * 60 + 300);
        return $url;
    }

    public function createVodXmlFile($inputPath = false)
    {
        Storage::put(ArchivedProgram::VOD_PATH . $this->id . '.xml', $this->generateVodXml($inputPath));
        return true;
    }

    public function generateVodXml($inputPath)
    {
        $replacement = [
            'title' => function () {
                $title = array_get($this->info, 'title.#text');
                $date = Carbon::parse(array_get($this->info, '@start'))->setTimezone('America/New_York')->format('d M Y');
                return $date . ' ' . $title;
            },
            'duration' => 'length.#text',
            'category' => function () {
                return $this->channel->category ? $this->channel->category : VodController::getDefaultValue('category');
            },
            'additional_category' => function () {
                return $this->channel->additional_category ? $this->channel->additional_category : VodController::getDefaultValue('additional_category');
            },
            'desc' => 'desc.#text',
            'poster' => 'icon.@src',
            'url' => function () use ($inputPath) {
                $path = $inputPath ? $inputPath : $this->createEndVideoPath();
                $url = $this->channel->url . $path . ".m3u8";
                return $url;
            },
            'year' => function () {
                return Carbon::parse(array_get($this->info, '@start'))->setTimezone('America/New_York')->format('Y');
            },
            'month' => function () {
                return Carbon::parse(array_get($this->info, '@start'))->setTimezone('America/New_York')->format('m');
            },
            'day' => function () {
                return Carbon::parse(array_get($this->info, '@start'))->setTimezone('America/New_York')->format('d');
            },
        ];

        $xml = preg_replace_callback("~(:_(.+?)_:)~", function ($matches) use ($replacement) {

            if (count($matches) != 3 || !array_key_exists($matches[2], $replacement)) return '';

            $rule = $replacement[$matches[2]];

            if (is_object($rule) && ($rule instanceof \Closure)) {
                return $rule();
            }

            return array_get($this->info, $rule) ? array_get($this->info, $rule) : '';

        }, self::VOD_XML_PATTERN);

        return $xml;
    }
}

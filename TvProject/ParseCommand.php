<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Program;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Nathanmac\Utilities\Parser\Parser;

/**
 * Class Parse
 * @package App\Console\Commands
 */
class Parse extends Command
{

    const USA_PATH = 'http://***.org/public/xml/usa/';
    const SKY_PATH = 'http://***.org/public/xml/sky/';

    protected $lastCsvResourceFileName;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse csv ***.org/epg.csv and its channels';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $csvFile = file_get_contents(config('constants.parse.***.url'));;

        $channelsXmlList = $this->parseChannelsListFromCsv($csvFile);

        $this->downloadAllXmlFiles($channelsXmlList);

        return true;
    }


    public function saveChannelAndProgramsInfo($xmlUrl, $xmlFile, $type)
    {


        $xml = new Parser();
        $xml = $xml->xml($xmlFile);

        $this->info($type . ' - ' . $xml['channel']['display-name']['#text'] . '...');

        /*
         * Skip channels which doesn't have any programs
         */
        if (!isset($xml['programme'])) return;

        $channel = Channel::firstOrNew([
            'xml' => $xmlUrl,
        ]);

        $channel->type = $type;
        $channel->logo = $xml['channel']['channellogo'];
        $channel->title = $xml['channel']['display-name']['#text'];
        $channel->save();
        $programs = [];

        foreach ($xml['programme'] as $k2 => $p) {

//                $this->info($k . ':' . $k2);

            /*
             * If a program has a bad date format then skip it
             */
            if ($p['@start_a']{0} == '-' || $p['@end_a']{0} == '-') {
                continue;
            }

            $category = 0;
            if (!empty($p['category']))
                $category = Category::firstOrCreate(['title' => $p['category']]);

            $program = Program::firstOrNew([
                'channel_id' => $channel->id,
                'start_at' => Carbon::parse($p['@start'])->setTimezone('utc')->toDateTimeString(),
            ]);
            $program->length = $p['length']['#text'];
            $program->title = $p['title']['#text'];
            $program->end_at = Carbon::parse($p['@stop'])->setTimezone('utc')->toDateTimeString();
            $program->info = $p;

            if (!empty($p['category']))
                $program->category_id = $category->id;

            $programs[] = $program;
        }

        $channel->programs()->saveMany($programs);
    }

    /**
     * The method downloads all xml files from the csv file
     * If the cms is placed in the same servers with xml files
     * this method doesn't used
     * @param $channelsXmlList
     */
    public function downloadAllXmlFiles($channelsXmlList)
    {

        $this->info('Start downloading xml files and seeding to db');

        $counter = $this->output->createProgressBar($channelsXmlList->count());

        foreach ($channelsXmlList as $v) {

            $counter->advance();

            try {

                if (config('constants.xml_files_is_local')) {
                    $url = str_replace("http://epg.***.org", "/var/www/tv/public", $v->url);
                    $file = file_get_contents($url);
                } else {
                    $file = file_get_contents($v->url);

                }
                $type = strpos($v->url, self::USA_PATH) !== false ? 'usa' : 'sky';

                $this->saveChannelAndProgramsInfo($v->url, $file, $type);
            } catch (\Exception $e) {
                $this->info("\n" . $e->getMessage());
                continue;
            }

            usleep(config('constants.parse.***.delay_between_xml_query'));

        }

        $counter->finish();

    }

    /**
     * The method is parsed the CSV file and filter them from bad urls
     * @return array|static
     */
    public function parseChannelsListFromCsv($csvFile)
    {
        $channels = explode("\n", $csvFile);

        $channels = collect($channels)->filter(function ($item) {

            return preg_match("~\.xml$~i", $item);

        })->filter(function ($item) {

            $stopList = [
                'gmt-content',
                '200-ok-server',
                '-expires-',
            ];

            return !str_contains($item, $stopList);

        })->unique();

        $channels->transform(function (&$item) {

            $row = explode(',', trim($item));

            if (count($row) != 2) unset($item);

            $params = collect();
            $params->name = trim($row[0], '"');
            $params->url = trim($row[1]);

            return $params;
        })->unique('url');

        return $channels;

    }
}

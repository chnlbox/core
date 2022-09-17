<?php

require_once "vendor/autoload.php";

use FireworkWeb\SMPTE\Timecode;
use FireworkWeb\SMPTE\Validations;

error_reporting(E_ALL & ~E_DEPRECATED);

class ChannelBox
{
    public $configData;
    public $channels;

    public function poll ()
    {
        $this->channels->poll ();
    }
}

class CSV
{
    protected $db;
    protected $evs = [];
    protected $sha1;

    protected $oldCorrection = 0;
    protected $newCorrection = 0;

    protected $updateCorrectionIsRequired = false;

    public function __construct ($file)
    {
        $this->db = $file;
    }

    public function getNextSchedules ($channelId, $timeCorrection, &$resetMarker)
    {

        $handle = fopen ($this->db, "r");

        $time = floor (microtime (true) * 1000);

        $newCorrection = 0;

        if ($timeCorrection >= $this->newCorrection)
        {
            $this->oldCorrection = $this->newCorrection;
            $this->newCorrection = $timeCorrection;

            $newCorrection = $this->newCorrection - $this->oldCorrection;
        }

        if (sizeof ($this->evs) == 1 && $this->evs[0]->time_start <= $time)
            return [];

        if ($this->sha1 != sha1_file ($this->db))
        {
            $this->sha1 = sha1_file ($this->db);
            $this->evs = [];

            $counter = 0;
            $scheduleCount = 2;

            while (($line = fgetcsv ($handle, 0, ";")) !== FALSE)
            { 
                if (intval ($line[0]) >= $time)
                {
                    ++$counter;

                    $ev = new SchedulerEvent;
                    
                    if (boolval ($line[7]) == false)
                    {
                        $ev->time_start = intval ($line[0]) + $timeCorrection;
                        $ev->fixed_time = true;
                    }
                    else
                        $ev->time_start = intval ($line[0]);

                    $ev->name = $line[1];
                    $ev->ev_type = intval ($line[2]);

                    if ($ev->ev_type != 1)
                        $ev->ev_data = $line[3];
                    else
                        $ev->ev_data = json_decode ($line[3]);

                    $ev->take = boolval ($line[4]);

                    if ($resetMarker == false)
                        $ev->restartEvent = boolval ($line[5]);
                    else
                    {
                        $ev->restartEvent = false;
                        $resetMarker = false;
                    }

                    $this->evs[] = $ev;

                    if ($counter > $scheduleCount)
                        break;

                }
            }

            return $this->evs;
        }
        else
        {
            array_walk ($this->evs, function (&$item) use ($newCorrection) {
                if ($item->fixed_time == false)
                    $item->time_start += $newCorrection;
            });

            array_filter ($this->evs, function ($item) use ($time) {
                return $item->time_start >= $time;
            });

            return $this->evs;
        }

    }
}

class Channels
{
    protected $channels;
    public $ccgclient;
    
    public function __construct ($ip, $port, $db)
    {
        $this->ccgclient = new \CosmonovaRnD\CasparCG\Client($ip, $port, 1);
        $this->db = $db;
    }

    public function addChannel ($channel, $useCustom = false)
    {
        if ($useCustom == false)
        {
            $channel->ccgclient = &$this->ccgclient;
            $this->channels[] = $channel;
        }
        else
            $this->channels[] = $channel;

        end ($this->channels);
        return key ($this->channels);
    }

    public function deleteChannel ($id)
    {
        unset ($this->channels[$id]);
    }

    public function listChannel ()
    {
        return $this->channels;
    }

    public function enableChannel ($id)
    {
        $this->channels[$id]->disabled = false;
    }

    public function disableChannel ($id)
    {
        $this->channels[$id]->disabled = true;
    }

    public function &channel ($id)
    {
        return $this->channels[$id];
    }

    public function poll ()
    {
        foreach ($this->channels as $sch)
            $sch->poll ();
    }
}

class Channel
{
    public $start;
    public $events;
    public $nextEvent;
    public $name = 'UNASSIGNED';
    public $disabled = false;
    public $ifRequest = false;
    public $request;
    public $lastTime;
    public $tempCorrection = 0;
    public $restartMarker = false;

    protected $logPath;
    protected $currentCorrectionPath;

    public $ccgclient;
    public $externalId = 1;

    public $timeCorrection = 0;
    public $currentTaken = false;

    protected $db;
    protected $isCheckingSchedules = false;

    public $updateTimeout = 1000;

    public function __construct ($addCaspar = true, $ip = '127.0.0.1', $port = 5250, $logPath, $currentCorrectionPath, $db)
    {
        if ($addCaspar == true)
            $this->ccgclient = new \CosmonovaRnD\CasparCG\Client($ip, $port, 1);

        $this->logPath = $logPath;
        $this->currentCorrectionPath = $currentCorrectionPath;
        $this->db = $db;
    }

    public function updateSchedules ()
    {
        if ($this->db instanceof CSV)
        {
            if ($this->isCheckingSchedules == true)
            {
                return;
            }
    
            $this->isCheckingSchedules = true;
    
            $this->events = $this->db->getNextSchedules ($this->externalId, $this->timeCorrection, $this->restartMarker);
    
            $this->isCheckingSchedules = false;
        }
        else
            return;
    }

    public function poll ()
    {

        if ($this->disabled == true)
            return;

        if ($this->ifRequest == true)
            $this->request->socketPerform ();

        if (floor (microtime (true) * 1000) % $this->updateTimeout == 0)
            $this->updateSchedules ();

        if (empty ($this->events) and empty ($this->nextEvent))
            return;

        if (!empty ($this->events) and $this->events[0]->restartEvent == true)
        {
            $this->timeCorrection = 0;
            $this->events[0]->restartEvent = false;
            $this->restartMarker = true;

            echo "[{$this->name}] Datetime: $time ms. Event \"" . $this->events[0]->name . "\" caused a restart." . PHP_EOL;
            file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$this->events[0]->name} at {$readableTime} caused a restart. Schedule correction: " . $this->timeCorrection . PHP_EOL, FILE_APPEND);
        }

        if (!empty ($this->events) and $this->events[0]->take == true)
        {
            $this->tempCorrection = floor (microtime (true) * 1000) - $this->lastTime;
            echo "[{$this->name}] Datetime: $time ms. Event \"" . $this->events[0]->name . "\" holded already {$this->tempCorrection} ms." . PHP_EOL;

            return;
        }

        if ($this->tempCorrection > 0)
        {
            $this->timeCorrection += $this->tempCorrection;
            $this->tempCorrection = 0;
        }

        if (($this->nextEvent instanceof SchedulerEvent) == false)
            $this->nextEvent = array_shift ($this->events);
            
        $time = floor (microtime (true) * 1000);
        $readableTime = date ('d.m.Y H:i:s');

        echo "[{$this->name}] Datetime: $time ms. Wait " . ($this->nextEvent->time_start - $time) . "ms to the next event \"" . $this->nextEvent->name . "\"." . PHP_EOL;

        if ($time >= $this->nextEvent->time_start)
        {
            $this->lastTime = floor (microtime (true) * 1000);

            $ev = $this->nextEvent;
            $this->nextEvent = array_shift ($this->events);

            echo "[{$this->name}] Event executed." . PHP_EOL;

            switch ($ev->ev_type)
            {
                case 0:
                    $req = $this->ccgclient->send ($ev->ev_data);
                    file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$ev->name} at {$readableTime} executed: {$req->getStatus()}. Schedule correction: " . $this->timeCorrection . PHP_EOL, FILE_APPEND);
                break;
                
                case 1:
                    $counter = -1;

                    foreach ($ev->ev_data as $cmd)
                    {
                        $counter++;
                        $req = $this->ccgclient->send ($cmd);
                        file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Multiple event [{$counter}] {$ev->name} at {$readableTime} executed: {$req->getStatus()}. Schedule correction: " . $this->timeCorrection . PHP_EOL, FILE_APPEND);
                    }
                break;

                case 2:
                    $this->request = new \cURL\Request ($ev->ev_data);
                    $this->request->getOptions ()->set (CURLOPT_SSL_VERIFYPEER, false)
                                                 ->set (CURLOPT_SSL_VERIFYSTATUS, false)
                                                 ->set (CURLOPT_FAILONERROR, false);

                    $this->ifRequest = true;
                    $class = $this;

                    $this->request->addListener ('complete', function (\cURL\Event $event) use ($class) {
                        $class->ifRequest = false;
                    });

                    $this->request->socketPerform ();

                    file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$ev->name} at {$readableTime} executed (HTTP requests without status). Schedule correction: " . $this->timeCorrection . PHP_EOL, FILE_APPEND);

                break;
            }
        }
    }
}

class SchedulerEvent
{
    public $time_start;
    public $ev_type = 0; // Event type: 0 - CasparCG AMCP Command; 1 - CasparCG Multi-AMCP Commands; 2 - HTTP GET;
    public $ev_data;
    public $name;
    public $take = false; // False - Go to next event; True - If the event ends then take time.
    public $restartEvent = false; // If $restartEvent is true, ChannelBox will be reset a correction time for schedules;
    public $fixed_time = false; // Fixed time
}

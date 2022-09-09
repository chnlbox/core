<?php

require_once "vendor/autoload.php";

class ChannelBox
{
    public $configData;
    public $channels;

    public function poll ()
    {
        $this->channels->poll ();
    }
}

class SleekDBEngine
{
    protected $db;

    public function __construct ($dir)
    {
        $this->db = new \SleekDB\Store ("schedules", $dir);
    }

    public function getNextSchedules ($channelId, $timeCorrection, &$resetMarker)
    {
        $query = $db->findBy ([
            ["startTime", "=>", floor (microtime (true) * 1000)], "AND", ["channelId", "=", $channelId]
        ], null, 2);

        $evs = [];
        $ev = null;

        foreach ($query as $aswr)
        {
            $ev = new SchedulerEvent;

            if ($aswr->fixed_time == false)
                $ev->time_start = $aswr->time_start + $timeCorrection;
            else
                $ev->time_start = $aswr->time_start;

            $ev->name = $aswr->name;
            $ev->ev_type = $aswr->ev_type;

            switch ($aswr->ev_type)
            {
                case 0:
                case 2:
                    $ev->ev_data = $aswr->ev_data;
                break;

                case 1:
                    $ev->ev_data = json_decode ($aswr->ev_data);
                break;
            }

            $ev->take = $aswr->take;

            if ($resetMarker == false)
                $ev->restartEvent = $aswr->restartEvent;
            else
            {
                $ev->restartEvent = false;
                $resetMarker = false;
            }

            $evs[] = $ev;
            
        }

        return $evs;
    }
}

class Channels
{
    protected $channels;
    public $ccgclient;
    protected $db;
    protected $isCheckingSchedules = false;

    public $updateTimeout = 1000;
    
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

    public function updateSchedules ()
    {
        if ($this->db instanceof SleekDBEngine)
        {
            if ($this->isCheckingSchedules == true)
            {
                return;
            }
    
            $this->isCheckingSchedules = true;
    
            $channels = $this->listChannel ();
    
            foreach ($channels as $channel)
            {
                $pointer = key ($channels);
                $this->channel ($pointer)->events = $this->db->getSchedules ($externalId, $channel->timeCorrection, $channel->restartMarker);
            }
    
            $this->isCheckingSchedules = false;
        }
        else
            return;
    }

    public function poll ()
    {
        if (floor (microtime (true) * 1000) % $this->updateTimeout == 0)
            $this->updateSchedules ();

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

    public function __construct ($addCaspar = true, $ip = '127.0.0.1', $port = 5250, $logPath, $currentCorrectionPath)
    {
        if ($addCaspar == true)
            $this->ccgclient = new \CosmonovaRnD\CasparCG\Client($ip, $port, 1);

        $this->logPath = $logPath;
        $this->currentCorrectionPath = $currentCorrectionPath;
    }

    public function poll ()
    {
        if ($this->disabled == true)
            return;

        if (empty ($this->events) and empty ($this->nextEvent))
            return;

        if ($this->ifRequest == true)
            $this->request->socketPerform ();

        if ($this->events[0]->restartEvent == true)
        {
            $this->timeCorrection = 0;
            $this->events[0]->restartEvent = false;
            $this->restartMarker = true;

            echo "[{$this->name}] Datetime: $time ms. Event \"" . $this->events[0]->name . "\" caused a restart." . PHP_EOL;
            file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$this->events[0]->name} at {$readableTime} caused a restart. Schedule correction: " . $this->timeCorrection, FILE_APPEND);
        }

        if ($this->events[0]->take == true)
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

        # echo "[{$this->name}] Datetime: $time ms. Wait " . ($this->nextEvent->time_start - $time) . "ms to the next event \"" . $this->nextEvent->name . "\"." . PHP_EOL;

        if ($time >= $this->nextEvent->time_start)
        {
            $this->lastTime = floor (microtime (true) * 1000);

            $ev = $this->nextEvent;
            $this->nextEvent = array_shift ($this->events);

            # echo "[{$this->name}] Event executed." . PHP_EOL;

            switch ($ev->ev_type)
            {
                case 0:
                    $req = $this->ccgclient->send ($ev->ev_data);
                    file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$ev->name} at {$readableTime} executed: {$req->getStatus()}. Schedule correction: " . $this->timeCorrection, FILE_APPEND);
                case 1:
                    $counter = -1;

                    foreach ($ev->ev_data as $cmd)
                    {
                        $counter++;
                        $req = $this->ccgclient->send ($cmd);
                        file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Multiple event [{$counter}] {$ev->name} at {$readableTime} executed: {$req->getStatus()}. Schedule correction: " . $this->timeCorrection, FILE_APPEND);
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

                    file_put_contents ($this->logPath, "[" . date ('c') . "] [Channel \"" . $this->name . "\"] Event {$ev->name} at {$readableTime} executed (HTTP requests without status). Schedule correction: " . $this->timeCorrection, FILE_APPEND);

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
}

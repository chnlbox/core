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

class MySQLDriver
{
    protected $db;

    public function __construct ($db, $ip, $user, $pwd)
    {
        try
        {
            $this->db = new PDO("mysql:host={$ip};dbname={$db}", $user, $pwd);
        }
        catch (PDOException $e)
        {
            die ('[CORE] An error has occured. MySQL PDO Error: ' . $e->getMessage ());
        }
    }

    public function getSchedules ($channelId)
    {
        $query = $this->db->prepare ('SELECT * FROM `schedule` WHERE startTime >= FLOOR(UNIX_TIMESTAMP(NOW(3))*1000) AND channel = :cId');
        $query->bindValue (':cId', $channelId, PDO::PARAM_INT);

        $query->execute ();

        $events = [];

        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
        {
            $event = new SchedulerEvent;

            $event->time_start = $row['startTime'];
            $event->ev_type = $row['type'];
            $event->name = $row['name'];

            switch ($row['type'])
            {
                case 0:
                    $event->ev_data = $row['data'];
                break;

                case 1:
                    $event->ev_data = json_decode ($row['data']);
                break;

                case 2:
                    $event->ev_data = $row['data'];
                break;
            }

            $events[] = $event;
        }

        return $events;
    }
}

class Channels
{
    protected $channels;
    public $ccgclient;
    protected $mysql;
    protected $isCheckingSchedules = false;

    public $mysqlUpdateTimeout = 30000;
    
    public function __construct ($ip, $port, $mysql)
    {
        $this->ccgclient = new \CosmonovaRnD\CasparCG\Client($ip, $port, 1);
        $this->mysql = $mysql;
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

    public function updateMySQLSchedules ()
    {
        if ($this->mysql instanceof MySQLDriver)
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
                $this->channel ($pointer)->events = $this->mysql->getSchedules ($externalId);
            }
    
            $this->isCheckingSchedules = false;
        }
        else
            return;
    }

    public function poll ()
    {
        if (floor (microtime (true) * 1000) % $this->mysqlUpdateTimeout == 0)
            $this->updateMySQLSchedules ();

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

    public $ccgclient;
    public $externalId = 1;

    public function __construct ($addCaspar = true, $ip = '127.0.0.1', $port = 5250)
    {
        if ($addCaspar == true)
            $this->ccgclient = new \CosmonovaRnD\CasparCG\Client($ip, $port, 1);
    }

    public function poll ()
    {
        if ($this->disabled == true)
            return;

        if (empty ($this->events) and empty ($this->nextEvent))
            return;

        if (($this->nextEvent instanceof SchedulerEvent) == false)
            $this->nextEvent = array_shift ($this->events);

        if ($this->ifRequest == true)
            $this->request->socketPerform ();
            
        $time = floor (microtime (true) * 1000);
        $readableTime = date ('d.m.Y H:i:s');

        #echo "[{$this->name}] Datetime: $readableTime. Wait " . date ('H:i:s', floor (($this->nextEvent->time_start - $time) / 1000)) . " to the next event \"" . $this->nextEvent->name . "\"." . PHP_EOL;
        echo "[{$this->name}] Datetime: $time ms. Wait " . ($this->nextEvent->time_start - $time) . "ms to the next event \"" . $this->nextEvent->name . "\"." . PHP_EOL;

        if ($time >= $this->nextEvent->time_start)
        {
            $ev = $this->nextEvent;
            $this->nextEvent = array_shift ($this->events);

            echo "[{$this->name}] Event executed." . PHP_EOL;

            switch ($ev->ev_type)
            {
                case 0:
                    $this->ccgclient->send ($ev->ev_data);
                break;

                case 1:
                    foreach ($ev->ev_data as $cmd)
                        $this->ccgclient->send ($cmd);
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
}

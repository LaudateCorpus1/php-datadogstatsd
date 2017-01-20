<?php

class Datadogstatsd
{
    private $host = '127.0.0.1';
    private $port = 8125;
    private $endpoint;
    private $eventUrl = '/api/v1/events';
    private $apiKey;
    private $applicationKey;

    const OK = 0;
    const WARNING = 1;
    const CRITICAL = 2;
    const UNKNOWN = 3;

    /**
     * @param string $host           IP of server running DogStatsD daemon
     * @param int    $port           Port of DogStatsD
     * @param null   $apiKey         (events only)
     * @param null   $applicationKey (events only)
     * @param string $endpoint
     */
    public function __construct($host = '127.0.0.1', $port = 8125, $apiKey = null, $applicationKey = null, $endpoint = 'https://app.datadoghq.com')
    {
        $this->host = $host;
        $this->port = $port;
        $this->apiKey = $apiKey;
        $this->applicationKey = $applicationKey;
        $this->endpoint = $endpoint;
    }

    /**
     * Log timing information.
     *
     * @param string  $stat       The metric to in log timing info for
     * @param float   $time       The ellapsed time (ms) to log
     * @param float|1 $sampleRate the rate (0-1) for sampling
     **/
    public function timing($stat, $time, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$time|ms"), $sampleRate, $tags);
    }

    /**
     * A convenient alias for the timing function when used with microtiming.
     *
     * @param string  $stat       The metric name
     * @param float   $time       The ellapsed time to log, IN SECONDS
     * @param float|1 $sampleRate the rate (0-1) for sampling
     **/
    public function microtiming($stat, $time, $sampleRate = 1, array $tags = null)
    {
        $this->timing($stat, $time * 1000, $sampleRate, $tags);
    }

    /**
     * Gauge.
     *
     * @param string  $stat       The metric
     * @param float   $value      The value
     * @param float|1 $sampleRate the rate (0-1) for sampling
     **/
    public function gauge($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|g"), $sampleRate, $tags);
    }

    /**
     * Histogram.
     *
     * @param string  $stat       The metric
     * @param float   $value      The value
     * @param float|1 $sampleRate the rate (0-1) for sampling
     **/
    public function histogram($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|h"), $sampleRate, $tags);
    }

    /**
     * Set.
     *
     * @param string  $stat       The metric
     * @param float   $value      The value
     * @param float|1 $sampleRate the rate (0-1) for sampling
     **/
    public function set($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|s"), $sampleRate, $tags);
    }

    /**
     * Increments one or more stats counters.
     *
     * @param string|array $stats      The metric(s) to increment
     * @param float|1      $sampleRate the rate (0-1) for sampling
     *
     * @return bool
     **/
    public function increment($stats, $sampleRate = 1, array $tags = null)
    {
        $this->updateStats($stats, 1, $sampleRate, $tags);
    }

    /**
     * Decrements one or more stats counters.
     *
     * @param string|array $stats      The metric(s) to decrement
     * @param float|1      $sampleRate the rate (0-1) for sampling
     *
     * @return bool
     **/
    public function decrement($stats, $sampleRate = 1, array $tags = null)
    {
        $this->updateStats($stats, -1, $sampleRate, $tags);
    }

    /**
     * Updates one or more stats counters by arbitrary amounts.
     *
     * @param string|array $stats      The metric(s) to update. Should be either a string or array of metrics
     * @param int|1        $delta      The amount to increment/decrement each metric by
     * @param float|1      $sampleRate the rate (0-1) for sampling
     * @param array|string $tags       Key Value array of Tag => Value, or single tag as string
     *
     * @return bool
     **/
    public function updateStats($stats, $delta = 1, $sampleRate = 1, array $tags = null)
    {
        if (!is_array($stats)) {
            $stats = array($stats);
        }
        $data = array();
        foreach ($stats as $stat) {
            $data[$stat] = "$delta|c";
        }
        $this->send($data, $sampleRate, $tags);
    }

    /**
     * Squirt the metrics over UDP.
     *
     * @param array        $data       Incoming Data
     * @param float|1      $sampleRate the rate (0-1) for sampling
     * @param array|string $tags       Key Value array of Tag => Value, or single tag as string
     **/
    public function send($data, $sampleRate = 1, array $tags = null)
    {
        // sampling
        $sampledData = array();
        if ($sampleRate < 1) {
            foreach ($data as $stat => $value) {
                if ((mt_rand() / mt_getrandmax()) <= $sampleRate) {
                    $sampledData[$stat] = "$value|@$sampleRate";
                }
            }
        } else {
            $sampledData = $data;
        }

        if (empty($sampledData)) {
            return;
        }

        foreach ($sampledData as $stat => $value) {
            if ($tags !== null && is_array($tags) && count($tags) > 0) {
                $value .= '|';
                foreach ($tags as $tag_key => $tag_val) {
                    $value .= '#'.$tag_key.':'.$tag_val.',';
                }
                $value = substr($value, 0, -1);
            } elseif (isset($tags) && !empty($tags)) {
                $value .= '|#'.$tags;
            }
            $this->report_metric("$stat:$value");
        }
    }
    /**
     * Send a custom service check status over UDP.
     *
     * @param string       $name      service check name
     * @param int          $status    service check status code (see class constants)
     * @param array|string $tags      Key Value array of Tag => Value, or single tag as string
     * @param string       $hostname  hostname to associate with this service check status
     * @param string       $message   message to associate with this service check status
     * @param int          $timestamp timestamp for the service check status (defaults to now)
     **/
    public function service_check($name, $status, array $tags = null,
                                         $hostname = null, $message = null, $timestamp = null)
    {
        $msg = "_sc|$name|$status";

        if ($timestamp !== null) {
            $msg .= sprintf('|d:%s', $timestamp);
        }
        if ($hostname !== null) {
            $msg .= sprintf('|h:%s', $hostname);
        }
        if ($tags !== null && is_array($tags) && count($tags) > 0) {
            $msg .= sprintf('|#%s', implode(',', $tags));
        } elseif (isset($tags) && !empty($tags)) {
            $msg .= sprintf('|#%s', $tags);
        }
        if ($message !== null) {
            $msg .= sprintf('|m:%s', $this->escape_sc_message($message));
        }

        $this->report($msg);
    }

    public function report($udp_message)
    {
        $this->flush($udp_message);
    }

    public function report_metric($udp_message)
    {
        $this->report($udp_message);
    }

    public function flush($udp_message)
    {
        // Non - Blocking UDP I/O - Use IP Addresses!
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_nonblock($socket);
        socket_sendto($socket, $udp_message, strlen($udp_message), 0, $this->host, $this->port);
        socket_close($socket);
    }

    /**
     * Send an event to the Datadog HTTP api. Potentially slow, so avoid
     * making many call in a row if you don't want to stall your app.
     * Requires PHP >= 5.3.0.
     *
     * @param string $title Title of the event
     * @param array  $vals  Optional values of the event. See
     *                      http://api.datadoghq.com/events for the valid keys
     **/
    public function event($title, $vals = array())
    {
        // Assemble the request
        $vals['title'] = $title;
        // Convert a comma-separated string of tags into an array
        if (array_key_exists('tags', $vals) && is_string($vals['tags'])) {
            $tags = explode(',', $vals['tags']);
            $vals['tags'] = array();
            foreach ($tags as $tag) {
                $vals['tags'][] = trim($tag);
            }
        }

        $body = json_encode($vals); // Added in PHP 5.3.0
        $opts = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $body,
            ),
        );

        $context = stream_context_create($opts);

        // Get the url to POST to
        $url = $this->endpoint.$this->eventUrl
             .'?api_key='.$this->apiKey
             .'&application_key='.$this->applicationKey;

        // Send, suppressing and logging any http errors
        try {
            file_get_contents($url, 0, $context);
        } catch (Exception $ex) {
            error_log($ex);
        }
    }

    private function escape_sc_message($msg)
    {
        return str_replace('m:', "m\:", str_replace("\n", '\\n', $msg));
    }
}

class BatchedDatadogstatsd extends Datadogstatsd
{
    private $buffer = array();
    private $length = 0;
    private $max = 50;

    public function setMax($max)
    {
        $this->max = $max;
    }

    public function report($udp_message)
    {
        $this->buffer[] = $udp_message;
        ++$this->length;
        if ($this->length > $this->max) {
            $this->flush_buffer();
        }
    }

    public function report_metric($udp_message)
    {
        $this->report($udp_message);
    }

    public function flush_buffer()
    {
        $this->flush(implode("\n", $this->buffer));
        $this->buffer = array();
        $this->length = 0;
    }
}

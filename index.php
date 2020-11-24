<?php

mb_internal_encoding("UTF-8");

function run() {
    try {
        $dir = dirname(__FILE__) . '/data';

        $method = $_SERVER['REQUEST_METHOD'];
        $name = isset($_GET['name']) ? $_GET['name'] : '';
        $ext = isset($_GET['ext']) ? $_GET['ext'] : 'json';
        $url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : '';
        $secret = isset($_GET['secret']) ? $_GET['secret'] : '';
        $alarms = isset($_GET['alarm']) ? explode(',', implode(',', (array) $_GET['alarm'])) : [];
        $maxAttendees = isset($_GET['max-attendees']) ? (int) $_GET['max-attendees'] : 20;

        $cryptMethod = 'AES-256-CBC';
        $hexIV = isset($_GET['iv']) ? $_GET['iv'] : 'b995ee5e4149975a575973f180192b1c';
        $iv = hex2bin($hexIV);

        if ($name === '') {
            throw new RuntimeException('name required.', 400);
        }
        if (!preg_match('/\A[a-z0-9\-]+\z/', $name)) {
            throw new RuntimeException('name must be alpha, number, and hyphen.', 400);
        }

        if ($method === 'POST' || $method === 'PUT') {
            $data = file_get_contents('php://input');
            if (json_decode($data) === null) {
                throw new RuntimeException('invalid json data', 400);
            }
            if ($secret !== '') {
                $data = openssl_encrypt($data, $cryptMethod, $secret, 0, $iv);
            }
            file_put_contents($dir . '/' . $name . '.json', $data);
            http_response_code(204);
            exit;
        }

        if ($method !== 'GET') {
            throw new RuntimeException('invalid method', 400);
        }

        $file = $dir . '/' . $name . '.json';
        if (!file_exists($file)) {
            throw new RuntimeException('not found', 404);
        }

        $json = file_get_contents($file);
        if ($json === null) {
            throw new RuntimeException('failed to read file');
        }

        if ($secret !== '') {
            $json = trim(openssl_decrypt($json, $cryptMethod, $secret, 0, $iv), "\0");
            if ($json === false) {
                throw new RuntimeException('invalid secret', 400);
            }
        }

        $schedule = new Schedule($json, [
            'baseURL'      => $url,
            'maxAttendees' => $maxAttendees,
            'alarms'       => $alarms,
        ]);

        if ($ext === 'json') {
            header('Content-Type: application/json');
            echo $json;
            exit;
        }

        if ($ext !== 'ics' && $ext !== 'txt') {
            throw new RuntimeException('invalid ext', 400);
        }

        if ($ext === 'txt') {
            header('Content-Type: text/plain');
        } else {
            header('Content-Type: text/calendar');
        }

        echo $schedule->toICal();
    } catch (Exception $e) {
        http_response_code($e->getCode() ?: 500);
        echo $e->getMessage();
    }
}

class Schedule
{
    protected $data = [];

    protected $baseURL = '';

    protected $alarms = [];

    protected $maxAttendees = 5;

    public function __construct(string $json, array $options)
    {
        $this->data = json_decode($json, true);

        foreach ($options as $key => $val) {
            switch ($key) {
                case 'baseURL':
                    $this->baseURL = $val;
                break;
                case 'maxAttendees':
                    $this->maxAttendees = (int) $val ?: 5;
                break;
                case 'alarms':
                    foreach ((array) $val as $v) {
                        $this->alarms[] = abs((int) $v);
                    }
                break;
            }
        }
    }

    public function toJSON()
    {
        return json_encode($this->data);
    }

    public function toICal()
    {
        return implode("\r\n", array_filter(array_merge(
            [
                'BEGIN:VCALENDAR',
                'CALSCALE:GREGORIAN',
                'PRODID:-//kamiaka//garoon-scheudle-server v1.0//JP',
                'METHOD:PUBLISH',
                'VERSION:2.0',
                'X-WR-CALNAME:Garoon Schedule',
                'X-WR-CALDESC:Garoon Schedule',
                'X-WR-TIMEZONE:Asia/Tokyo',
                'BEGIN:VTIMEZONE',
                'TZID:Asia/Tokyo',
                'BEGIN:STANDARD',
                'DTSTART:19390101T000000',
                'TZOFFSETFROM:+0900',
                'TZOFFSETTO:+0900',
                'TZNAME:JST',
                'END:STANDARD',
                'END:VTIMEZONE',
            ],
            $this->toICalEvents($this->data['events'] ?: []),
            [
                'END:VCALENDAR',
            ],
        ))) . "\r\n";
    }

    public function toICalEvents($events)
    {
        $baseURL = $this->baseURL;
        $maxAttendees = $this->maxAttendees;
        $alarms = $this->alarms;

        $data = [];
        foreach ($events as $ev) {
            $summary = ($ev['eventMenu'] ? "{$ev['eventMenu']}: " : '') . $ev['subject'];
            $data = array_merge(
                $data,
                [
                    'BEGIN:VEVENT',
                    static::field('UID', static::uid($ev['id'], $baseURL) . '/' . $ev['start']['dateTime']),
                    static::field("ORGANIZER;CN={$ev['creator']['name']}", $ev['creator']['code']),
                    static::iCalTimeField('CREATED', $ev['createdAt'], 'UTC'),
                    static::iCalTimeField('LAST-MODIFIED', $ev['updatedAt'], 'UTC'),
                    static::iCalTimeField('DTSTAMP', $ev['updatedAt'], 'UTC'),
                    static::iCalTimeField('DTSTART', $ev['start']['dateTime'], $ev['start']['timeZone']),
                    static::iCalTimeField('DTEND', $ev['end']['dateTime'], $ev['end']['timeZone']),
                    static::field('CLASS', 'PRIVATE'),
                    static::field('SUMMARY', $summary),
                    static::field('DESCRIPTION', implode("\n", array_filter([
                        trim($ev['notes']),
                        '- - -',
                        "Created by: {$ev['creator']['name']} ({$ev['createdAt']})",
                        "Updated by: {$ev['updater']['name']} ({$ev['updatedAt']})",
                        "Attendees: " . implode(', ', array_map(function($item) {
                            return $item['name'];
                        }, array_slice($ev['attendees'], 0, $maxAttendees))) . (count($ev['attendees']) > $maxAttendees ? (', ...more ' . (count($ev['attendees']) > $maxAttendees) . ' attendees') : '')
                    ]))),
                    static::field('LOCATION', empty($ev['facilities']) ? '' : implode(', ', array_map(function($item) {
                        return $item['name'];
                    }, $ev['facilities']))),
                    static::field('URL', static::eventURL($ev['id'], $baseURL)),
                ],
                static::iCalAlarms($alarms, $summary),
                [
                    'END:VEVENT',
                ],
            );
        }
        return $data;
    }

    static function field($name, $value)
    {
        if (empty($value)) {
            return '';
        }

        $field = $name . ':' . $value;
        $field = str_replace(["\r", "\n"], ['\r', '\n'], $field);

        return static::splitField($field, 72);
    }

    static function iCalAlarms(array $durations, string $description)
    {
        $data = [];
        foreach ($durations as $duration) {
            $data = array_merge(
                $data,
                static::iCalAlarmFields($duration, $description),
            );
        }
        return $data;
    }

    static function iCalAlarmFields(int $duration, string $description)
    {
        return [
            'BEGIN:VALARM',
            static::field('ACTION', 'DISPLAY'),
            static::field('DESCRIPTION', $description),
            static::field('TRIGGER', sprintf(
                '-P%dDT%dH%dM%dS',
                floor($duration / 86400),
                floor(($duration % 86400) / 3600),
                floor(($duration % 3600) / 60),
                $duration % 60,
            )),
            'END:VALARM',
        ];
    }

    static function iCalTimeField($field, $timeString, $zoneString = 'UTC')
    {
        $time = new DateTime($timeString);
        $zone = new DateTimeZone($zoneString);
        $time->setTimezone($zone);
        if ($zoneString === 'UTC') {
            $time->setTimezone($zone);
            return sprintf('%s:%s', $field, $time->format('Ymd\THis\Z'));
        }
        return sprintf('%s;TZID=%s:%s', $field, $zone->getName() ?: $time->getTimezone()->getName(), $time->format('Ymd\THis'));
    }

    static function splitField($field, $length, $indent = "\t", $break = "\n") {
        $chars = mb_str_split($field);
        $bytes = 0;
        $str = '';
        foreach ($chars as $char) {
            $bytes += strlen($char);
            if ($bytes > $length) {
                $bytes = strlen($indent . $char);
                $str .= $break . $indent;
            }
            $str .= $char;
        }
        return $str;
    }

    static function eventURL($id, $baseURL = null) {
        if (empty($baseURL)) {
            return '';
        }
        return $baseURL . '/schedule/view?event=' . $id;
    }

    static function uid($id, $baseURL = null) {
        $id = 'grn.ev.' . $id;

        if (empty($baseURL)) {
            return $id;
        }

        return $id . '@' . preg_replace('@^https?://([^/]+).*@', '$1', $baseURL);
    }
}

run();

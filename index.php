<?php

mb_internal_encoding("UTF-8");

function run() {
    try {
        $name = Request::query('name');

        if ($name === '') {
            throw new HTTPException('name is required', 400);
        }
        if (!preg_match('/\A[a-z0-9\-]+\z/', $name)) {
            throw new HTTPException('invalid name', 400);
        }

        Store::init([
            'method' => Request::server('AUTH_METHOD'),
            'iv' => Request::server('AUTH_IV'),
            'user' => Request::user(),
            'password' => Request::password(),
        ]);

        switch (Request::method()) {
        case 'POST': /* fall through */
        case 'PUT':
            if (Store::exists($name) && !Store::load($name)) {
                throw new UnauthorizedException();
            }

            if (!($data = Request::json())) {
                throw HTTPException('invalid body', 400);
            }

            Store::save($name, $data);
            http_response_code(204);
            exit;
        case 'GET':
            if (!Store::exists($name)) {
                throw new HTTPException('not found', 404);
            }
            if (!($data = Store::load($name))) {
                throw new UnauthorizedException();
            }
            $schedule = new Schedule($data, [
                'baseURL'      => rtrim(Request::query('url'), '/'),
                'maxAttendees' => (int) Request::query('max-attendees', 20),
                'alarms'       => array_filter(explode(',', implode(',', (array) Request::query('alarm', [])))),
                'skipKeywords' => array_filter(explode(',', implode(',', (array) Request::query('skip-keywords', [])))),
            ]);

            switch (Request::query('ext', 'ics')) {
            case 'ics':
                header('Content-Type: text/calendar');
                echo $schedule->toICal();
                exit;
            case 'txt':
                header('Content-Type: text/plain');
                echo $schedule->toICal();
                exit;
            case 'json':
                header('Content-Type: application/json');
                echo $schedule->toJSON();
                exit;
            default:
                throw new HTTPException('invalid ext', 400);
            }
        default:
            throw new HTTPException('invalid json data', 400);
        }
    } catch (UnauthorizedException $e) {
        header("X-Debug-Unauthorized: " . $e->getLine());
        header('WWW-Authenticate: Basic realm="Schedule user and password"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Unauthorized';
        exit;
    } catch(HTTPException $e) {
        header("X-Debug-Exception: " . $e->getLine());
        http_response_code($e->getCode() ?: 500);
        echo $e->getMessage();
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Internal Error';
        var_dump($e);
        exit;
    }
}

class Store
{
    protected $dir = '';
    protected $method = '';
    protected $iv = '';
    protected $user = '';
    protected $password = '';

    protected static $instance;

    protected function __construct(array $params)
    {
        $params = array_filter($params) + [
            'dir' => dirname(__FILE__) . '/data',
            'method' => 'AES-256-CBC',
            'iv' => 'b995ee5e4149975a575973f180192b1c',
            'user' => 'user',
            'password' => 'password',
        ];

        $this->method = $params['method'];
        $this->iv = hex2bin($params['iv']);
        $this->dir = $params['dir'];
        $this->user = $params['user'];
        $this->password = $params['password'];
    }

    static protected function getInstance(array $params = []): Store
    {
        if (static::$instance === null) {
            static::$instance = new static($params);
        }
        return static::$instance;
    }

    static public function init(array $params)
    {
        if (static::$instance !== null) {
            throw new RuntimeException('already initialized');
        }
        return static::getInstance($params);
    }

    public static function list()
    {
        if (!($dh = opendir(static::getInstance()->dir))) {
            throw new RuntimeException('failed to open dir: ' . static::getInstance()->dir);
        }
        $ls = [];
        while (($file = readdir($dh)) !== false) {
            $ls[] = $file;
        }
        return $ls;
    }

    static public function exists(string $name)
    {
        return file_exists(static::path($name));
    }

    static public function load(string $name)
    {
        $json = json_decode(file_get_contents(static::path($name)), true);
        if (!$json) {
            throw new RuntimeException("failed to load file, name: $name");
        }

        $store = static::getInstance();
        if (
            $json['user'] !== $store->user ||
            ($data = openssl_decrypt($json['data'], $store->method, $store->password, 0, $store->iv)) === false
        ) {
            return null;
        }
        return json_decode(trim($data, "\0"), true);
    }

    static public function save(string $name, $data)
    {
        $store = static::getInstance();

        $data = openssl_encrypt(json_encode($data), $store->method, $store->password, 0, $store->iv);

        if (!file_put_contents(static::path($name), json_encode([
            'user' => $store->user,
            'data' => $data,
        ]))) {
            throw new RuntimeException('failed to store file: ' . static::path($name));
        }
    }

    static public function delete(string $name)
    {
        if (!static::exists($name)) {
            return false;
        }

        if (!unlink(static::path($name))) {
            throw new RuntimeException('failed to unlink file: ' . static::path($name));
        }

        return true;
    }

    static protected function path($name)
    {
        return static::getInstance()->dir . '/' . $name . '.json';
    }
}

class Request
{
    static public function method()
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    static public function server($name, $default = '')
    {
        return isset($_SERVER[$name]) ? $_SERVER[$name] : $default;
    }

    static public function user()
    {
        return static::server('PHP_AUTH_USER');
    }

    static public function password()
    {
        return static::server('PHP_AUTH_PW');
    }

    static public function query($name, $default = '')
    {
        return isset($_GET[$name]) ? $_GET[$name] : $default;
    }

    static public function body()
    {
        return file_get_contents('php://input');
    }

    static public function json()
    {
        return json_decode(static::body());
    }
}

class Schedule
{
    protected $data = [];

    protected $baseURL = '';

    protected $alarms = [];

    protected $maxAttendees = 5;

    protected $skipKeywords = [];

    public function __construct(array $data, array $options = [])
    {
        $this->data = $data;

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
                case 'skipKeywords':
                    foreach ((array) $val as $v) {
                        $this->skipKeywords[] = $v;
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
                'PRODID:-//kamiaka//garoon-scheudle-server v2.0//JP',
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
            if ($this->matchKeywords($summary)) {
                continue;
            }
            $endField = $ev['isStartOnly'] ? 'start' : 'end';
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
                    static::iCalTimeField('DTEND', $ev[$endField]['dateTime'], $ev[$endField]['timeZone']),
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
                    static::field('URL', static::eventURL($ev['id'], $baseURL, $ev['start']['dateTime'], $ev['start']['timeZone'])),
                ],
                static::iCalAlarms($alarms, $summary),
                [
                    'END:VEVENT',
                ],
            );
        }
        return $data;
    }

    protected function matchKeywords($summary)
    {
        foreach ($this->skipKeywords as $keyword) {
            if (stripos($summary, $keyword) !== false) {
                return true;
            }
        }
        return false;
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

    static function eventURL($id, $baseURL = null, $timeString = null, $zoneString = 'UTC') {
        if (empty($baseURL)) {
            return '';
        }
        $url = $baseURL . '/schedule/view?event=' . $id;
        if (!empty($timeString)) {
            $time = new DateTime($timeString);
            $zone = new DateTimeZone($zoneString);
            $time->setTimezone($zone);
            $url .= '&bdate=' . $time->format('Y-m-d');
        }
        return $url;
    }

    static function uid($id, $baseURL = null) {
        $id = 'grn.ev.' . $id;

        if (empty($baseURL)) {
            return $id;
        }

        return $id . '@' . preg_replace('@^https?://([^/]+).*@', '$1', $baseURL);
    }
}

class HTTPException extends RuntimeException {}

class UnauthorizedException extends RuntimeException {}

run();

<?php

mb_internal_encoding("UTF-8");

function run() {
    $dir = dirname(__FILE__) . '/data';

    $method = $_SERVER['REQUEST_METHOD'];
    $name = isset($_GET['name']) ? $_GET['name'] : '';
    $ext = isset($_GET['ext']) ? $_GET['ext'] : 'json';
    $url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : '';
    $secret = isset($_GET['secret']) ? $_GET['secret'] : '';

    $cryptMethod = 'AES-256-CBC';
    $hexIV = isset($_GET['iv']) ? $_GET['iv'] : 'b995ee5e4149975a575973f180192b1c';
    $iv = hex2bin($hexIV);

    if ($name === '') {
        http_response_code(400);
        die('name required.');
    }
    if (!preg_match('/\A[a-z0-9\-]\z/', $name)) {
        http_response_code(400);
        die('name must be alpha, number, and hyphen.');
    }

    if ($method === 'POST' || $method === 'PUT') {
        $data = file_get_contents('php://input');
        if (json_decode($data) === null) {
            http_response_code(400);
            die('invalid json data');
        }
        if ($secret !== '') {
            $data = openssl_encrypt($data, $cryptMethod, $secret, 0, $iv);
        }
        file_put_contents($dir . '/' . $name . '.json', $data);
        http_response_code(204);
        exit;
    }

    if ($method !== 'GET') {
        http_response_code(400);
        die('invalid method');
    }

    $file = $dir . '/' . $name . '.json';
    if (!file_exists($file)) {
        http_response_code(404);
        die('not found');
    }

    $json = file_get_contents($file);
    if ($json === null) {
        http_response_code(500);
        die('failed to read file');
    }

    if ($secret !== '') {
        $json = trim(openssl_decrypt($json, $cryptMethod, $secret, 0, $iv), "\0");
        if ($json === false) {
            http_response_code(500);
            die('invalid secret');
        }
    }

    $data = json_decode($json, true);
    if ($data === null) {
        http_response_code(500);
        die('invalid json data');
    }

    if ($ext === 'json') {
        header('Content-Type: application/json');
        echo $json;
        exit;
    }

    if ($ext !== 'ics' && $ext !== 'txt') {
        http_response_code(400);
        die('invalid ext');
    }

    if ($ext === 'txt') {
        header('Content-Type: text/plain');
    } else {
        header('Content-Type: text/calendar');
    }
    echo toICal($data, $url);
}

function toICal($json, $baseURL = null) {
    $data = array_filter(array_merge(
        [
            'BEGIN:VCALENDAR',
            'CALSCALE:GREGORIAN',
            'PRODID:-//kamiaka//garoon-scheudle-hoster v1.0//JP',
            'METHOD:PUBLISH',
            'VERSION:2.0',
            'X-WR-CALNAME:Garoon Schedule',
            'X-WR-CALDESC:Hosted Garoon Schedule',
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
        toICalEvents($json['events'] ?: [], $baseURL),
        [
            'END:VCALENDAR',
        ],
    ));
    return implode("\r\n", $data) . "\r\n";
}

function toICalEvents($events, $baseURL = null) {
    $data = [];
    $maxAttendees = 20;
    foreach ($events as $ev) {
        $data = array_merge($data, [
            'BEGIN:VEVENT',
            field('UID', uid($ev['id'], $baseURL) . '/' . $ev['start']['dateTime']),
            field("ORGANIZER;CN={$ev['creator']['name']}", $ev['creator']['code']),
            toICalTimeField('CREATED', $ev['createdAt'], 'UTC'),
            toICalTimeField('LAST-MODIFIED', $ev['updatedAt'], 'UTC'),
            toICalTimeField('DTSTART', $ev['start']['dateTime'], $ev['start']['timeZone']),
            toICalTimeField('DTEND', $ev['end']['dateTime'], $ev['end']['timeZone']),
            field('CLASS', 'PRIVATE'),
            field('SUMMARY', ($ev['eventMenu'] ? "{$ev['eventMenu']}: " : '') . $ev['subject']),
            field('DESCRIPTION', implode("\n", array_filter([
                trim($ev['notes']),
                '- - -',
                "Created by: {$ev['creator']['name']} ({$ev['createdAt']})",
                "Updated by: {$ev['updater']['name']} ({$ev['updatedAt']})",
                "Attendees: " . implode(', ', array_map(function($item) {
                    return $item['name'];
                }, array_slice($ev['attendees'], 0, $maxAttendees))) . (count($ev['attendees']) > $maxAttendees ? (', ...more ' . (count($ev['attendees']) > $maxAttendees) . ' attendees') : '')
            ]))),
            field('LOCATION', empty($ev['facilities']) ? '' : implode(', ', array_map(function($item) {
                return $item['name'];
            }, $ev['facilities']))),
            field('URL', generateEventURL($ev['id'], $baseURL)),
            'END:VEVENT',
        ]);
    }
    return $data;
}

function toICalTimeField($field, $timeString, $zoneString = null) {
    $time = new DateTime($timeString);
    $zone = new DateTimeZone($zoneString ?: 'Asia/Tokyo');
    $time->setTimezone($zone);
    if ($zoneString === 'UTC') {
        $time->setTimezone($zone);
        return sprintf('%s:%s', $field, $time->format('Ymd\THis\Z'));
    }
    return sprintf('%s;TZID=%s:%s', $field, $zone->getName() ?: $time->getTimezone()->getName(), $time->format('Ymd\THis'));
}

function field($name, $value) {
    if (empty($value)) {
        return '';
    }

    $field = $name . ':' . $value;
    $field = str_replace(["\r", "\n"], ['\r', '\n'], $field);

    return splitField($field, 72);
}

function splitField($field, $length, $indent = "\t", $break = "\n") {
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

function generateEventURL($id, $baseURL = null) {
    if (empty($baseURL)) {
        return '';
    }
    return $baseURL . '/schedule/view?event=' . $id;
}

function uid($id, $url = null) {
    if ($url) {
        return $id . '@' . preg_replace('@https?://([^/]+)/?.*@', '$1', $url);
    }
    return $id . '@'. $_SERVER['SERVER_NAME'];
}

run();

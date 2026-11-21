<?php

declare(strict_types=1);

/**
 * A minimal FastCGI responder client (one request per connection) for the request-level tests.
 * Returns the CGI status, the headers (names lower-cased, repeated headers joined with ", ")
 * and the body exactly as PHP-FPM sent it.
 *
 * @param array<string,string> $params
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function fcgi_request(string $socket, array $params, float $timeout = 10.0): array
{
    $deadline = microtime(true) + $timeout;
    $fp = @stream_socket_client('unix://' . $socket, $errno, $errstr, $timeout);
    if ($fp === false) {
        throw new RuntimeException("connect {$socket}: {$errstr}");
    }
    try {
        $record = static function (int $type, string $content): string {
            return pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
        };
        $length = static function (string $s): string {
            return strlen($s) < 128 ? chr(strlen($s)) : pack('N', strlen($s) | 0x80000000);
        };
        $pairs = '';
        foreach ($params as $name => $value) {
            $pairs .= $length((string) $name) . $length($value) . $name . $value;
        }
        // BEGIN_REQUEST (role RESPONDER, no keep-alive), PARAMS, empty PARAMS, empty STDIN.
        $request = $record(1, pack('nCx5', 1, 0)) . $record(4, $pairs) . $record(4, '') . $record(5, '');
        if (fwrite($fp, $request) !== strlen($request)) {
            throw new RuntimeException('short write');
        }

        $read = static function (int $n) use ($fp, $deadline): string {
            $data = '';
            while (strlen($data) < $n) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new RuntimeException('timed out reading the response');
                }
                stream_set_timeout($fp, (int) ceil($left));
                $chunk = fread($fp, $n - strlen($data));
                if ($chunk === false || ($chunk === '' && feof($fp))) {
                    throw new RuntimeException('connection closed mid-record');
                }
                $data .= $chunk;
            }
            return $data;
        };

        $stdout = '';
        while (true) {
            $head = unpack('Cversion/Ctype/nid/nlength/Cpadding', $read(8));
            $content = $head['length'] > 0 ? $read($head['length']) : '';
            if ($head['padding'] > 0) {
                $read($head['padding']);
            }
            if ($head['type'] === 6) {
                $stdout .= $content;
            } elseif ($head['type'] === 3) {
                break; // END_REQUEST
            }
        }
    } finally {
        fclose($fp);
    }

    $split = strpos($stdout, "\r\n\r\n");
    if ($split === false) {
        throw new RuntimeException('no header block in: ' . substr($stdout, 0, 200));
    }
    $status = 200;
    $headers = [];
    foreach (explode("\r\n", substr($stdout, 0, $split)) as $line) {
        $pair = explode(':', $line, 2);
        if (count($pair) !== 2) {
            continue;
        }
        $name = strtolower(trim($pair[0]));
        $value = trim($pair[1]);
        if ($name === 'status') {
            $status = (int) $value;
            continue;
        }
        $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
    }

    return ['status' => $status, 'headers' => $headers, 'body' => substr($stdout, $split + 4)];
}

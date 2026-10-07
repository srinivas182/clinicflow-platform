<?php

declare(strict_types=1);

namespace App\Domains\Platform\Security;

use RuntimeException;

/**
 * ClamAV daemon (clamd) over TCP using its INSTREAM command: the file is streamed in
 * length-prefixed chunks, then clamd replies "stream: OK" or "stream: <signature> FOUND".
 */
class ClamdScanner implements VirusScanner
{
    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout = 30) {}

    public function scan(string $path): ?string
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        $file = @fopen($path, 'rb');
        if ($socket === false || $file === false) {
            throw new RuntimeException('The virus scanner is not reachable'.($error !== '' ? ": {$error}" : '.'));
        }
        stream_set_timeout($socket, $this->timeout);
        try {
            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = (string) fread($file, 8192);
                if ($chunk !== '') {
                    fwrite($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            fwrite($socket, pack('N', 0));
            $reply = (string) stream_get_contents($socket);
        } finally {
            fclose($file);
            fclose($socket);
        }

        return self::parse($reply);
    }

    /** Interprets clamd's reply. */
    public static function parse(string $reply): ?string
    {
        $reply = trim($reply, "\0\r\n ");
        if (preg_match('/^stream: OK$/', $reply) === 1) {
            return null;
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1) {
            return $m[1];
        }

        throw new RuntimeException('Unexpected reply from the virus scanner: '.mb_substr($reply, 0, 120));
    }
}

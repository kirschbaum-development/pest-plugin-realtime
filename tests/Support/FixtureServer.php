<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class FixtureServer
{
    private ?Process $process = null;

    private ?int $port = null;

    public function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port for the browser fixture server.');
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($address === false || ($separator = strrpos($address, ':')) === false) {
            throw new RuntimeException('Could not determine the browser fixture server port.');
        }

        $this->port = (int) substr($address, $separator + 1);
        $this->process = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$this->port,
            '-t',
            __DIR__.'/../Fixtures',
        ]);
        $this->process->start();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, timeout: 0.05);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('The browser fixture server did not start.');
    }

    public function stop(): void
    {
        $this->process?->stop();
        $this->process = null;
    }

    public function url(): string
    {
        if ($this->port === null) {
            throw new RuntimeException('The browser fixture server has not started.');
        }

        return 'http://127.0.0.1:'.$this->port;
    }
}

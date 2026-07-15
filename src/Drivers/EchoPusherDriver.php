<?php

declare(strict_types=1);

namespace Pest\Realtime\Drivers;

use JsonException;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Exceptions\RealtimeSimulationException;

final class EchoPusherDriver implements Driver
{
    public function installScript(): string
    {
        $runtime = file_get_contents(dirname(__DIR__, 2).'/resources/echo-pusher.js');

        if ($runtime === false) {
            throw new RealtimeSimulationException('The Echo/Pusher browser runtime could not be loaded.');
        }

        return $runtime;
    }

    public function channelsScript(): string
    {
        return $this->runtimeCall('channels');
    }

    public function statusScript(): string
    {
        return $this->runtimeCall('status');
    }

    public function transitionScript(ConnectionStatus $status): string
    {
        return $this->runtimeCall('transitionTo', [$status->value]);
    }

    public function emitScript(
        string $channel,
        string $event,
        mixed $payload,
        ChannelVisibility $visibility,
    ): string {
        return $this->runtimeCall('emit', [
            $channel,
            $event,
            $payload,
            $visibility->value,
        ]);
    }

    public function channelId(string $channel, ChannelVisibility $visibility): string
    {
        return match ($visibility) {
            ChannelVisibility::Public => $channel,
            ChannelVisibility::Private => 'private-'.$channel,
            ChannelVisibility::Presence => 'presence-'.$channel,
        };
    }

    /**
     * @param  list<mixed>  $arguments
     *
     * @throws JsonException
     */
    private function runtimeCall(string $method, array $arguments = []): string
    {
        $encodedMethod = json_encode($method, JSON_THROW_ON_ERROR);
        $encodedArguments = json_encode(
            $arguments,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT,
        );

        return <<<JS
            (() => {
                const runtime = window.__pestRealtime;

                if (!runtime || runtime.driver !== 'echo-pusher') {
                    throw new Error('Pest Realtime is not installed on this page. Call realtime(\$page)->install() first.');
                }

                return runtime[{$encodedMethod}](...{$encodedArguments});
            })()
        JS;
    }
}

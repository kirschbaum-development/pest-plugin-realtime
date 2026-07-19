<?php

declare(strict_types=1);

namespace Pest\Realtime\Laravel;

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Pest\Realtime\CapturedBroadcast;

final class CapturingBroadcaster extends NullBroadcaster
{
    public function __construct(
        private readonly BroadcastCollector $collector,
        private readonly ?string $connection,
    ) {}

    /**
     * @param  array<array-key, string|\Stringable>  $channels
     * @param  array<array-key, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $socket = isset($payload['socket']) && is_string($payload['socket'])
            ? $payload['socket']
            : null;

        unset($payload['socket']);

        $this->collector->add(new CapturedBroadcast(
            channels: array_values(array_map(
                static fn (string|\Stringable $channel): string => (string) $channel,
                $channels,
            )),
            event: $event,
            payload: $payload,
            connection: $this->connection,
            socket: $socket,
        ));
    }
}

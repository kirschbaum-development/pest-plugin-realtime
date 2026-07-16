<?php

declare(strict_types=1);

namespace Pest\Realtime\Laravel;

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Pest\Realtime\CapturedBroadcast;

final class CapturingBroadcaster extends NullBroadcaster
{
    /** @var list<CapturedBroadcast> */
    private array $broadcasts = [];

    /**
     * @param  array<array-key, string|\Stringable>  $channels
     * @param  array<array-key, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        unset($payload['socket']);

        $this->broadcasts[] = new CapturedBroadcast(
            channels: array_values(array_map(
                static fn (string|\Stringable $channel): string => (string) $channel,
                $channels,
            )),
            event: $event,
            payload: $payload,
        );
    }

    /**
     * @return list<CapturedBroadcast>
     */
    public function broadcasts(): array
    {
        return $this->broadcasts;
    }
}

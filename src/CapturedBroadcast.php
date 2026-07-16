<?php

declare(strict_types=1);

namespace Pest\Realtime;

final readonly class CapturedBroadcast
{
    /**
     * @param  list<string>  $channels
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public array $channels,
        public string $event,
        public array $payload,
    ) {}
}

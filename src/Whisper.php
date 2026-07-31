<?php

declare(strict_types=1);

namespace Pest\Realtime;

/**
 * A client event the page sent through `Echo.private(...).whisper()`.
 */
final readonly class Whisper
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public string $channel,
        public array $payload,
        public bool $connected,
    ) {}
}

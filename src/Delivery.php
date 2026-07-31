<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Pest\Realtime\Support\Channels;

final readonly class Delivery
{
    public function __construct(
        public CapturedBroadcast $broadcast,
        public string $channel,
        public ChannelVisibility $visibility,
        public DeliveryOutcome $outcome,
    ) {}

    /**
     * The wire identifier Echo registers for this delivery's channel.
     */
    public function wireChannel(): string
    {
        return Channels::toWire($this->channel, $this->visibility);
    }

    public function event(): string
    {
        return $this->broadcast->event;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->broadcast->payload;
    }
}

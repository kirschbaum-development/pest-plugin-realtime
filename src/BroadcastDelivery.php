<?php

declare(strict_types=1);

namespace Pest\Realtime;

final readonly class BroadcastDelivery
{
    public function __construct(
        public CapturedBroadcast $broadcast,
        public string $channel,
        public ChannelVisibility $visibility,
        public EventDelivery $outcome,
    ) {}
}

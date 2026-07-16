<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Fixtures;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final readonly class PriceChanged implements ShouldBroadcast
{
    public function __construct(public int $price) {}

    public function broadcastOn(): string
    {
        return 'auctions.1';
    }
}

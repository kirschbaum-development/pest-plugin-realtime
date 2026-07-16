<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Fixtures;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final readonly class NamedPriceChanged implements ShouldBroadcast
{
    public function __construct(
        private int $auctionId,
        private int $price,
    ) {}

    /**
     * @return list<string>
     */
    public function broadcastOn(): array
    {
        return [
            "auctions.{$this->auctionId}",
            'private-buyers.2',
            'presence-room.3',
        ];
    }

    public function broadcastAs(): string
    {
        return 'price.changed';
    }

    /**
     * @return array{price: int}
     */
    public function broadcastWith(): array
    {
        return ['price' => $this->price];
    }
}

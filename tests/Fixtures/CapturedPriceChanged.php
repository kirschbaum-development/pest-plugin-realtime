<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Fixtures;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class CapturedPriceChanged implements ShouldBroadcastNow
{
    public function __construct(
        public int $price,
        public ?string $socket = null,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('auctions.1'),
            new PrivateChannel('buyers.2'),
            new PresenceChannel('room.3'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'price.changed';
    }

    /**
     * @return list<string>
     */
    public function broadcastConnections(): array
    {
        return ['secondary'];
    }

    /**
     * @return array{price: int}
     */
    public function broadcastWith(): array
    {
        return ['price' => $this->price];
    }
}

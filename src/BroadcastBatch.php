<?php

declare(strict_types=1);

namespace Pest\Realtime;

final readonly class BroadcastBatch
{
    /**
     * @param  list<EventDelivery>  $deliveries
     */
    public function __construct(
        private int $capturedCount,
        private array $deliveries,
    ) {}

    public function capturedCount(): int
    {
        return $this->capturedCount;
    }

    public function deliveredCount(): int
    {
        return $this->count(EventDelivery::Delivered);
    }

    public function droppedCount(): int
    {
        return $this->count(EventDelivery::Dropped);
    }

    public function allDelivered(): bool
    {
        return $this->deliveries !== [] && $this->droppedCount() === 0;
    }

    private function count(EventDelivery $delivery): int
    {
        return count(array_filter(
            $this->deliveries,
            static fn (EventDelivery $result): bool => $result === $delivery,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Pest\Realtime;

final readonly class BroadcastBatch
{
    /**
     * @param  list<CapturedBroadcast>  $broadcasts
     * @param  list<BroadcastDelivery>  $deliveries
     */
    public function __construct(
        private array $broadcasts,
        private array $deliveries,
    ) {}

    /**
     * @return list<CapturedBroadcast>
     */
    public function broadcasts(): array
    {
        return $this->broadcasts;
    }

    /**
     * @return list<BroadcastDelivery>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    public function capturedCount(): int
    {
        return count($this->broadcasts);
    }

    public function deliveredCount(): int
    {
        return $this->count(EventDelivery::Delivered);
    }

    public function droppedCount(): int
    {
        return $this->count(EventDelivery::Dropped);
    }

    public function notSubscribedCount(): int
    {
        return $this->count(EventDelivery::NotSubscribed);
    }

    public function allDelivered(): bool
    {
        return $this->deliveries !== []
            && $this->deliveredCount() === count($this->deliveries);
    }

    private function count(EventDelivery $delivery): int
    {
        return count(array_filter(
            $this->deliveries,
            static fn (BroadcastDelivery $result): bool => $result->outcome === $delivery,
        ));
    }
}

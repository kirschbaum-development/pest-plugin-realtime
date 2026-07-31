<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pest\Realtime\Support\Channels;
use Pest\Realtime\Support\EventName;
use PHPUnit\Framework\Assert;

/**
 * Every broadcast this session pushed into the page, and what became of it.
 */
final readonly class CapturedBroadcasts
{
    /**
     * @param  list<Delivery>  $deliveries
     */
    public function __construct(private array $deliveries) {}

    /**
     * @return Collection<int, Delivery>
     */
    public function deliveries(): Collection
    {
        return new Collection($this->deliveries);
    }

    /**
     * The distinct broadcasts behind these deliveries, in the order they were sent.
     *
     * @return Collection<int, CapturedBroadcast>
     */
    public function broadcasts(): Collection
    {
        $broadcasts = [];

        foreach ($this->deliveries as $delivery) {
            $broadcasts[spl_object_id($delivery->broadcast)] = $delivery->broadcast;
        }

        return new Collection(array_values($broadcasts));
    }

    public function capturedCount(): int
    {
        return $this->broadcasts()->count();
    }

    public function deliveredCount(): int
    {
        return $this->countOutcome(DeliveryOutcome::Delivered);
    }

    public function droppedCount(): int
    {
        return $this->countOutcome(DeliveryOutcome::Dropped);
    }

    public function notSubscribedCount(): int
    {
        return $this->countOutcome(DeliveryOutcome::NotSubscribed);
    }

    public function excludedCount(): int
    {
        return $this->countOutcome(DeliveryOutcome::Excluded);
    }

    public function allDelivered(): bool
    {
        return $this->deliveries !== []
            && $this->deliveredCount() === count($this->deliveries);
    }

    /**
     * Asserts the event reached the page on at least one channel.
     */
    public function assertDelivered(string $event, Closure|int|null $callback = null): self
    {
        if (is_int($callback)) {
            return $this->assertDeliveredTimes($event, $callback);
        }

        Assert::assertTrue(
            $this->matching($event, $callback, DeliveryOutcome::Delivered) !== [],
            sprintf(
                "The expected [%s] broadcast was not delivered.\n%s",
                $event,
                $this->summary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event reached the page exactly the given number of times.
     */
    public function assertDeliveredTimes(string $event, int $times = 1): self
    {
        $count = count($this->matching($event, null, DeliveryOutcome::Delivered));

        Assert::assertSame(
            $times,
            $count,
            sprintf(
                'The expected [%s] broadcast was delivered %d %s instead of %d %s.',
                $event,
                $count,
                Str::plural('time', $count),
                $times,
                Str::plural('time', $times),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event reached the page on a specific channel.
     */
    public function assertDeliveredOn(
        Channel|string $channel,
        string $event,
        ?Closure $callback = null,
    ): self {
        [$name, $visibility] = Channels::parse($channel);
        $wire = Channels::toWire($name, $visibility);

        $matching = array_filter(
            $this->matching($event, $callback, DeliveryOutcome::Delivered),
            static fn (Delivery $delivery): bool => $delivery->wireChannel() === $wire,
        );

        Assert::assertTrue(
            $matching !== [],
            sprintf(
                "The expected [%s] broadcast was not delivered on [%s].\n%s",
                $event,
                $wire,
                $this->summary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event did not reach the page.
     */
    public function assertNotDelivered(string $event, ?Closure $callback = null): self
    {
        Assert::assertCount(
            0,
            $this->matching($event, $callback, DeliveryOutcome::Delivered),
            sprintf(
                "The unexpected [%s] broadcast was delivered.\n%s",
                $event,
                $this->summary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event was sent but the page's connection was down.
     */
    public function assertDropped(string $event, Closure|int|null $callback = null): self
    {
        if (is_int($callback)) {
            $count = count($this->matching($event, null, DeliveryOutcome::Dropped));

            Assert::assertSame($callback, $count, sprintf(
                'The expected [%s] broadcast was dropped %d %s instead of %d %s.',
                $event,
                $count,
                Str::plural('time', $count),
                $callback,
                Str::plural('time', $callback),
            ));

            return $this;
        }

        Assert::assertTrue(
            $this->matching($event, $callback, DeliveryOutcome::Dropped) !== [],
            sprintf(
                "The expected [%s] broadcast was not dropped.\n%s",
                $event,
                $this->summary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts every broadcast reached the page.
     */
    public function assertNothingDropped(): self
    {
        Assert::assertCount(
            0,
            $this->withOutcome(DeliveryOutcome::Dropped),
            sprintf("Expected no dropped broadcasts.\n%s", $this->summary()),
        );

        return $this;
    }

    /**
     * Asserts the event was sent, whatever became of it.
     */
    public function assertBroadcast(string $event, ?Closure $callback = null): self
    {
        Assert::assertTrue(
            $this->matching($event, $callback) !== [],
            sprintf(
                "The expected [%s] broadcast was not sent.\n%s",
                $event,
                $this->summary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event was never sent.
     */
    public function assertNotBroadcast(string $event, ?Closure $callback = null): self
    {
        Assert::assertCount(
            0,
            $this->matching($event, $callback),
            sprintf('The unexpected [%s] broadcast was sent.', $event),
        );

        return $this;
    }

    /**
     * Asserts no broadcast was sent at all.
     */
    public function assertNothingBroadcast(): self
    {
        Assert::assertCount(
            0,
            $this->deliveries,
            sprintf("Expected no broadcasts.\n%s", $this->summary()),
        );

        return $this;
    }

    /**
     * @return list<Delivery>
     */
    private function matching(
        string $event,
        ?Closure $callback = null,
        ?DeliveryOutcome $outcome = null,
    ): array {
        $candidates = EventName::candidates($event);

        return array_values(array_filter(
            $this->deliveries,
            static fn (Delivery $delivery): bool => in_array($delivery->broadcast->event, $candidates, true)
                && ($outcome === null || $delivery->outcome === $outcome)
                && ($callback === null || $callback($delivery->broadcast) === true),
        ));
    }

    /**
     * @return list<Delivery>
     */
    private function withOutcome(DeliveryOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->deliveries,
            static fn (Delivery $delivery): bool => $delivery->outcome === $outcome,
        ));
    }

    private function countOutcome(DeliveryOutcome $outcome): int
    {
        return count($this->withOutcome($outcome));
    }

    private function summary(): string
    {
        if ($this->deliveries === []) {
            return 'Broadcasts sent: none.';
        }

        return 'Broadcasts sent: '.implode(', ', array_map(
            static fn (Delivery $delivery): string => sprintf(
                '%s on [%s] (%s)',
                $delivery->broadcast->event,
                $delivery->wireChannel(),
                $delivery->outcome->value,
            ),
            $this->deliveries,
        )).'.';
    }
}

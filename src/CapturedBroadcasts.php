<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
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
     * The wire name Laravel broadcasts a notification under, and the name Echo
     * binds for `notification()`.
     */
    private const string NOTIFICATION_EVENT = 'Illuminate\Notifications\Events\BroadcastNotificationCreated';

    /**
     * @param  list<Delivery>  $deliveries
     * @param  string|null  $hint  Explains an empty log, when something explains it.
     */
    public function __construct(
        private array $deliveries,
        private ?string $hint = null,
    ) {}

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
     *
     * @param  Closure|array<array-key, mixed>|int|null  $callback  An array matches the payload as a subset.
     */
    public function assertDelivered(string $event, Closure|array|int|null $callback = null): self
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
     *
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertDeliveredOn(
        Channel|HasBroadcastChannel|string $channel,
        string $event,
        Closure|array|null $callback = null,
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
     *
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotDelivered(string $event, Closure|array|null $callback = null): self
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
     *
     * @param  Closure|array<array-key, mixed>|int|null  $callback  An array matches the payload as a subset.
     */
    public function assertDropped(string $event, Closure|array|int|null $callback = null): self
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
     * Asserts the events reached the page in the given relative order.
     *
     * Other broadcasts may arrive between them.
     *
     * @param  list<string>  $events
     */
    public function assertDeliveredInOrder(array $events): self
    {
        $delivered = array_map(
            static fn (Delivery $delivery): string => $delivery->broadcast->event,
            $this->withOutcome(DeliveryOutcome::Delivered),
        );

        $remaining = $events;

        foreach ($delivered as $event) {
            if ($remaining !== [] && in_array($event, EventName::candidates($remaining[0]), true)) {
                array_shift($remaining);
            }
        }

        Assert::assertSame(
            [],
            $remaining,
            sprintf(
                "The expected broadcasts were not delivered in order.\nExpected order: %s.\nDelivered: %s.",
                implode(', ', $events),
                $delivered === [] ? 'none' : implode(', ', $delivered),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event was delivered through a specific Laravel connection.
     *
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertDeliveredVia(
        string $connection,
        string $event,
        Closure|array|null $callback = null,
    ): self {
        Assert::assertTrue(
            $this->matchingConnection($connection, $event, $callback) !== [],
            sprintf(
                "The expected [%s] broadcast was not delivered via [%s].\n%s",
                $event,
                $connection,
                $this->connectionSummary(),
            ),
        );

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotDeliveredVia(
        string $connection,
        string $event,
        Closure|array|null $callback = null,
    ): self {
        Assert::assertCount(
            0,
            $this->matchingConnection($connection, $event, $callback),
            sprintf(
                "The unexpected [%s] broadcast was delivered via [%s].\n%s",
                $event,
                $connection,
                $this->connectionSummary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts a broadcast notification reached the notifiable's channel.
     */
    public function assertNotified(
        Channel|HasBroadcastChannel|string $notifiable,
        string $notification,
        ?Closure $callback = null,
    ): self {
        $wire = $this->notifiableWire($notifiable);

        Assert::assertTrue(
            $this->matchingNotifications($wire, $notification, $callback) !== [],
            sprintf(
                "The expected [%s] notification was not delivered on [%s].\n%s",
                $notification,
                $wire,
                $this->notificationSummary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts no such broadcast notification reached the notifiable's channel.
     */
    public function assertNotNotified(
        Channel|HasBroadcastChannel|string $notifiable,
        string $notification,
        ?Closure $callback = null,
    ): self {
        $wire = $this->notifiableWire($notifiable);

        Assert::assertCount(
            0,
            $this->matchingNotifications($wire, $notification, $callback),
            sprintf(
                "The unexpected [%s] notification was delivered on [%s].\n%s",
                $notification,
                $wire,
                $this->notificationSummary(),
            ),
        );

        return $this;
    }

    /**
     * Asserts the event was sent, whatever became of it.
     *
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertBroadcast(string $event, Closure|array|null $callback = null): self
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
     *
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotBroadcast(string $event, Closure|array|null $callback = null): self
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
     * @param  Closure|array<array-key, mixed>|null  $callback
     * @return list<Delivery>
     */
    private function matching(
        string $event,
        Closure|array|null $callback = null,
        ?DeliveryOutcome $outcome = null,
    ): array {
        $candidates = EventName::candidates($event);
        $test = $this->truthTest($callback);

        return array_values(array_filter(
            $this->deliveries,
            static fn (Delivery $delivery): bool => in_array($delivery->broadcast->event, $candidates, true)
                && ($outcome === null || $delivery->outcome === $outcome)
                && ($test === null || $test($delivery->broadcast) === true),
        ));
    }

    /**
     * Normalizes a truth test, treating an array as a payload subset to match.
     *
     * @param  Closure|array<array-key, mixed>|null  $callback
     */
    private function truthTest(Closure|array|null $callback): ?Closure
    {
        if ($callback === null || $callback instanceof Closure) {
            return $callback;
        }

        return static function (CapturedBroadcast $broadcast) use ($callback): bool {
            foreach ($callback as $key => $value) {
                if (! array_key_exists($key, $broadcast->payload)) {
                    return false;
                }

                if ($broadcast->payload[$key] !== $value) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback
     * @return list<Delivery>
     */
    private function matchingConnection(
        string $connection,
        string $event,
        Closure|array|null $callback,
    ): array {
        return array_values(array_filter(
            $this->matching($event, $callback, DeliveryOutcome::Delivered),
            static fn (Delivery $delivery): bool => $delivery->broadcast->connection === $connection,
        ));
    }

    private function connectionSummary(): string
    {
        if ($this->deliveries === []) {
            return 'Broadcasts sent: none.';
        }

        return 'Broadcasts sent: '.implode(', ', array_map(
            static fn (Delivery $delivery): string => sprintf(
                '%s via [%s]',
                $delivery->broadcast->event,
                $delivery->broadcast->connection ?? 'none',
            ),
            $this->deliveries,
        )).'.';
    }

    private function notifiableWire(Channel|HasBroadcastChannel|string $notifiable): string
    {
        [$name, $visibility] = Channels::parse($notifiable);

        return Channels::toWire($name, $visibility);
    }

    /**
     * @return list<Delivery>
     */
    private function matchingNotifications(
        string $wire,
        string $notification,
        ?Closure $callback,
    ): array {
        return array_values(array_filter(
            $this->matching(self::NOTIFICATION_EVENT, $callback, DeliveryOutcome::Delivered),
            static fn (Delivery $delivery): bool => $delivery->wireChannel() === $wire
                && ($delivery->broadcast->payload['type'] ?? null) === $notification,
        ));
    }

    private function notificationSummary(): string
    {
        $notifications = array_map(
            static fn (Delivery $delivery): string => sprintf(
                '%s on [%s] (%s)',
                is_string($delivery->broadcast->payload['type'] ?? null)
                    ? $delivery->broadcast->payload['type']
                    : 'unknown',
                $delivery->wireChannel(),
                $delivery->outcome->value,
            ),
            $this->matching(self::NOTIFICATION_EVENT),
        );

        if ($notifications === []) {
            return 'Notifications sent: none.';
        }

        return 'Notifications sent: '.implode(', ', $notifications).'.';
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
            return $this->hint === null
                ? 'Broadcasts sent: none.'
                : 'Broadcasts sent: none.'.PHP_EOL.$this->hint;
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

<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Support\Arrayable;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Contracts\ScriptExecutor;
use Pest\Realtime\Exceptions\RealtimeException;
use Pest\Realtime\Support\Channels;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use Stringable;

/**
 * Drives the page's realtime client and records everything it receives.
 */
final class Broadcasting
{
    private const int POLL_INTERVAL_MICROSECONDS = 50_000;

    private bool $installed = false;

    /** @var list<Delivery> */
    private array $deliveries = [];

    private ?string $socketId = null;

    public function __construct(
        private readonly ScriptExecutor $executor,
        private readonly Driver $driver,
        private readonly ?BroadcastCapture $broadcastCapture = null,
    ) {
        $this->broadcastCapture?->start(function (CapturedBroadcast $broadcast): void {
            $this->replay($broadcast);
        });
    }

    public function __destruct()
    {
        $this->stopCapturing();
    }

    /**
     * Restores the application's broadcasting configuration.
     *
     * Runs automatically when the session goes out of scope at the end of a test.
     */
    public function stopCapturing(): self
    {
        $this->broadcastCapture?->stop();

        return $this;
    }

    /**
     * Installs the browser runtime and connects the simulated client.
     *
     * Called automatically by the first operation that needs the page, so tests
     * rarely call it directly. Idempotent.
     */
    public function install(?int $timeoutMilliseconds = null): self
    {
        if ($this->installed) {
            return $this;
        }

        $timeoutMilliseconds ??= Realtime::timeout();
        $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1_000);

        do {
            $result = $this->executor->evaluate($this->driver->installScript());

            if ($result !== null) {
                $this->parseChannels($result, 'install');
                $this->installed = true;

                // The runtime stops the real client, leaving it disconnected.
                // Connected is the state tests almost always want to start from.
                return $this->transitionTo(ConnectionStatus::Connected);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw RealtimeException::clientNotReady($timeoutMilliseconds);
    }

    /**
     * The wire identifiers the page is currently subscribed to.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->parseChannels(
            $this->install()->executor->evaluate($this->driver->channelsScript()),
            'channels',
        );
    }

    /**
     * Asserts the page subscribed to the channel, waiting for late subscriptions.
     */
    public function assertSubscribed(
        Channel|string $channel,
        ?int $timeoutMilliseconds = null,
    ): self {
        [$name, $visibility] = Channels::parse($channel);
        $channelId = $this->driver->channelId($name, $visibility);

        $timeoutMilliseconds ??= Realtime::timeout();
        $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1_000);
        $channels = [];

        do {
            $channels = $this->channels();

            if (in_array($channelId, $channels, true)) {
                Assert::assertContains($channelId, $channels);

                return $this;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        Assert::assertContains(
            $channelId,
            $channels,
            sprintf('Expected the page to be subscribed to realtime channel [%s].', $channelId),
        );

        return $this;
    }

    public function assertNotSubscribed(Channel|string $channel): self
    {
        [$name, $visibility] = Channels::parse($channel);
        $channelId = $this->driver->channelId($name, $visibility);

        Assert::assertNotContains(
            $channelId,
            $this->channels(),
            sprintf('Expected the page not to be subscribed to realtime channel [%s].', $channelId),
        );

        return $this;
    }

    public function status(): ConnectionStatus
    {
        $result = $this->install()->executor->evaluate($this->driver->statusScript());

        if (! is_string($result) || ConnectionStatus::tryFrom($result) === null) {
            throw RealtimeException::unexpectedResult('status', $result);
        }

        return ConnectionStatus::from($result);
    }

    /**
     * The socket id the simulated client reports to the application.
     *
     * Pass it to `Broadcast::socket()` or an event's `$socket` property to
     * exercise `toOthers()` exclusion.
     */
    public function socketId(): string
    {
        if ($this->socketId !== null) {
            return $this->socketId;
        }

        $result = $this->install()->executor->evaluate($this->driver->socketIdScript());

        if (! is_string($result)) {
            throw RealtimeException::unexpectedResult('socketId', $result);
        }

        return $this->socketId = $result;
    }

    public function assertConnected(): self
    {
        return $this->assertStatus(ConnectionStatus::Connected);
    }

    public function assertDisconnected(): self
    {
        return $this->assertStatus(ConnectionStatus::Disconnected);
    }

    public function assertStatus(ConnectionStatus $status): self
    {
        $actual = $this->status();

        Assert::assertSame(
            $status,
            $actual,
            sprintf(
                'Expected the realtime connection to be [%s] but it was [%s].',
                $status->value,
                $actual->value,
            ),
        );

        return $this;
    }

    public function transitionTo(ConnectionStatus $status): self
    {
        $result = $this->executor->evaluate($this->driver->transitionScript($status));

        if ($result !== $status->value) {
            throw RealtimeException::unexpectedResult('transition', $result);
        }

        return $this;
    }

    public function connect(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Connected);
    }

    public function disconnect(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Disconnected);
    }

    public function fail(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Failed);
    }

    public function unavailable(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Unavailable);
    }

    public function reconnect(): self
    {
        return $this->install()
            ->transitionTo(ConnectionStatus::Connecting)
            ->transitionTo(ConnectionStatus::Connected);
    }

    /**
     * Pushes a Laravel broadcast event into the page.
     *
     * Channels, name, and payload come from `broadcastOn()`, `broadcastAs()`,
     * and `broadcastWith()`. The event is not dispatched through Laravel, so
     * `broadcastWhen()` is not evaluated — let the application dispatch it and
     * let capture do the work when that matters.
     */
    public function broadcast(ShouldBroadcast $event): self
    {
        $channels = $this->broadcastChannels($event, $event->broadcastOn());

        if ($channels === []) {
            throw RealtimeException::missingBroadcastChannels($event);
        }

        $eventName = method_exists($event, 'broadcastAs')
            ? $event->broadcastAs()
            : $event::class;

        if (! is_string($eventName)) {
            throw RealtimeException::invalidBroadcastName($event, $eventName);
        }

        $this->replay(new CapturedBroadcast(
            channels: $channels,
            event: $eventName,
            payload: $this->broadcastPayload($event),
        ));

        return $this;
    }

    /**
     * Pushes a raw event into the page at the wire boundary.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function emit(string $event, Channel|string $channel, array $payload = []): self
    {
        [$name, $visibility] = Channels::parse($channel);

        $this->replay(new CapturedBroadcast(
            channels: [Channels::toWire($name, $visibility)],
            event: $event,
            payload: $payload,
        ));

        return $this;
    }

    /**
     * Runs the callback and returns only the broadcasts it produced.
     */
    public function capture(Closure $callback): CapturedBroadcasts
    {
        if ($this->broadcastCapture === null) {
            throw RealtimeException::broadcastCaptureUnavailable();
        }

        $offset = count($this->deliveries);

        $callback();

        return new CapturedBroadcasts(array_slice($this->deliveries, $offset));
    }

    /**
     * Every broadcast this session has pushed into the page.
     */
    public function captured(): CapturedBroadcasts
    {
        return new CapturedBroadcasts($this->deliveries);
    }

    public function assertDelivered(string $event, Closure|int|null $callback = null): self
    {
        $this->captured()->assertDelivered($event, $callback);

        return $this;
    }

    public function assertDeliveredTimes(string $event, int $times = 1): self
    {
        $this->captured()->assertDeliveredTimes($event, $times);

        return $this;
    }

    public function assertDeliveredOn(
        Channel|string $channel,
        string $event,
        ?Closure $callback = null,
    ): self {
        $this->captured()->assertDeliveredOn($channel, $event, $callback);

        return $this;
    }

    public function assertNotDelivered(string $event, ?Closure $callback = null): self
    {
        $this->captured()->assertNotDelivered($event, $callback);

        return $this;
    }

    public function assertDropped(string $event, Closure|int|null $callback = null): self
    {
        $this->captured()->assertDropped($event, $callback);

        return $this;
    }

    public function assertNothingDropped(): self
    {
        $this->captured()->assertNothingDropped();

        return $this;
    }

    public function assertBroadcast(string $event, ?Closure $callback = null): self
    {
        $this->captured()->assertBroadcast($event, $callback);

        return $this;
    }

    public function assertNotBroadcast(string $event, ?Closure $callback = null): self
    {
        $this->captured()->assertNotBroadcast($event, $callback);

        return $this;
    }

    public function assertNothingBroadcast(): self
    {
        $this->captured()->assertNothingBroadcast();

        return $this;
    }

    /**
     * Pushes one broadcast to each of its channels and records the outcomes.
     */
    private function replay(CapturedBroadcast $broadcast): void
    {
        foreach ($broadcast->channels as $wireChannel) {
            [$name, $visibility] = Channels::parse($wireChannel);

            $outcome = $this->excludes($broadcast)
                ? DeliveryOutcome::Excluded
                : $this->push($name, $broadcast->event, $broadcast->payload, $visibility);

            $this->deliveries[] = new Delivery($broadcast, $name, $visibility, $outcome);
        }
    }

    private function excludes(CapturedBroadcast $broadcast): bool
    {
        return $broadcast->socket !== null
            && $broadcast->socket === $this->socketId();
    }

    private function push(
        string $channel,
        string $event,
        mixed $payload,
        ChannelVisibility $visibility,
    ): DeliveryOutcome {
        $result = $this->install()->executor->evaluate(
            $this->driver->emitScript($channel, $event, $payload, $visibility),
        );

        if (! is_string($result) || DeliveryOutcome::tryFrom($result) === null) {
            throw RealtimeException::unexpectedResult('emit', $result);
        }

        return DeliveryOutcome::from($result);
    }

    /**
     * @return list<string>
     */
    private function broadcastChannels(ShouldBroadcast $event, mixed $channels): array
    {
        if (is_array($channels)) {
            $resolved = [];

            foreach ($channels as $channel) {
                array_push($resolved, ...$this->broadcastChannels($event, $channel));
            }

            return $resolved;
        }

        if (! is_string($channels) && ! $channels instanceof Stringable) {
            throw RealtimeException::invalidBroadcastChannel($event, $channels);
        }

        return [(string) $channels];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function broadcastPayload(ShouldBroadcast $event): array
    {
        if (method_exists($event, 'broadcastWith')) {
            $payload = $event->broadcastWith();

            if (is_array($payload)) {
                return $payload;
            }
        }

        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($event);
            $payload[$property->getName()] = $value instanceof Arrayable
                ? $value->toArray()
                : $value;
        }

        unset($payload['broadcastQueue'], $payload['socket']);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function parseChannels(mixed $result, string $operation): array
    {
        if (! is_array($result)) {
            throw RealtimeException::unexpectedResult($operation, $result);
        }

        foreach ($result as $channel) {
            if (! is_string($channel)) {
                throw RealtimeException::unexpectedResult($operation, $result);
            }
        }

        return array_values($result);
    }
}

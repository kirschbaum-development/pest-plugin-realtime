<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Closure;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Support\Arrayable;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Contracts\ScriptExecutor;
use Pest\Realtime\Exceptions\RealtimeSimulationException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use Stringable;

final readonly class RealtimeSession
{
    public function __construct(
        private ScriptExecutor $executor,
        private Driver $driver,
        private ?BroadcastCapture $broadcastCapture = null,
    ) {}

    public function install(): self
    {
        $this->parseChannels(
            $this->executor->evaluate($this->driver->installScript()),
            'install',
        );

        return $this;
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->parseChannels(
            $this->executor->evaluate($this->driver->channelsScript()),
            'channels',
        );
    }

    public function assertSubscribed(
        string $channel,
        ChannelVisibility $visibility = ChannelVisibility::Public,
    ): self {
        $channelId = $this->driver->channelId($channel, $visibility);

        Assert::assertContains(
            $channelId,
            $this->channels(),
            sprintf('Expected the page to be subscribed to realtime channel [%s].', $channelId),
        );

        return $this;
    }

    public function status(): ConnectionStatus
    {
        $result = $this->executor->evaluate($this->driver->statusScript());

        if (! is_string($result) || ConnectionStatus::tryFrom($result) === null) {
            throw RealtimeSimulationException::unexpectedResult('status', $result);
        }

        return ConnectionStatus::from($result);
    }

    public function transitionTo(ConnectionStatus $status): self
    {
        $result = $this->executor->evaluate($this->driver->transitionScript($status));

        if ($result !== $status->value) {
            throw RealtimeSimulationException::unexpectedResult('transition', $result);
        }

        return $this;
    }

    public function connect(): self
    {
        return $this->transitionTo(ConnectionStatus::Connected);
    }

    public function disconnect(): self
    {
        return $this->transitionTo(ConnectionStatus::Disconnected);
    }

    public function fail(): self
    {
        return $this->transitionTo(ConnectionStatus::Failed);
    }

    public function reconnect(): self
    {
        return $this
            ->transitionTo(ConnectionStatus::Reconnecting)
            ->transitionTo(ConnectionStatus::Connected);
    }

    public function emit(
        string|ShouldBroadcast $event,
        ?string $channel = null,
        mixed $payload = [],
        ?ChannelVisibility $visibility = null,
    ): EventDelivery {
        if ($event instanceof ShouldBroadcast) {
            if ($channel !== null || $payload !== [] || $visibility !== null) {
                throw RealtimeSimulationException::broadcastEventOverrides($event);
            }

            return $this->emitBroadcastEvent($event);
        }

        if ($channel === null) {
            throw RealtimeSimulationException::missingChannel($event);
        }

        return $this->emitToChannel(
            $channel,
            $event,
            $payload,
            $visibility ?? ChannelVisibility::Public,
        );
    }

    public function captureBroadcasts(Closure $callback): BroadcastBatch
    {
        if ($this->broadcastCapture === null) {
            throw RealtimeSimulationException::broadcastCaptureUnavailable();
        }

        $broadcasts = $this->broadcastCapture->capture($callback);
        $deliveries = [];

        foreach ($broadcasts as $broadcast) {
            foreach ($broadcast->channels as $wireChannel) {
                [$channel, $visibility] = $this->parseWireChannel($wireChannel);

                $deliveries[] = $this->emitToChannel(
                    $channel,
                    $broadcast->event,
                    $broadcast->payload,
                    $visibility,
                );
            }
        }

        return new BroadcastBatch(count($broadcasts), $deliveries);
    }

    private function emitBroadcastEvent(ShouldBroadcast $event): EventDelivery
    {
        $channels = $this->broadcastChannels($event, $event->broadcastOn());

        if ($channels === []) {
            throw RealtimeSimulationException::missingBroadcastChannels($event);
        }

        $eventName = method_exists($event, 'broadcastAs')
            ? $event->broadcastAs()
            : $event::class;

        if (! is_string($eventName)) {
            throw RealtimeSimulationException::invalidBroadcastName($event, $eventName);
        }

        $payload = $this->broadcastPayload($event);
        $delivery = EventDelivery::Delivered;

        foreach ($channels as [$channel, $visibility]) {
            if ($this->emitToChannel($channel, $eventName, $payload, $visibility) === EventDelivery::Dropped) {
                $delivery = EventDelivery::Dropped;
            }
        }

        return $delivery;
    }

    private function emitToChannel(
        string $channel,
        string $event,
        mixed $payload,
        ChannelVisibility $visibility,
    ): EventDelivery {
        $result = $this->executor->evaluate(
            $this->driver->emitScript($channel, $event, $payload, $visibility),
        );

        if (! is_bool($result)) {
            throw RealtimeSimulationException::unexpectedResult('emit', $result);
        }

        return $result ? EventDelivery::Delivered : EventDelivery::Dropped;
    }

    /**
     * @return list<array{string, ChannelVisibility}>
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
            throw RealtimeSimulationException::invalidBroadcastChannel($event, $channels);
        }

        $channel = (string) $channels;

        return [$this->parseWireChannel($channel)];
    }

    /**
     * @return array{string, ChannelVisibility}
     */
    private function parseWireChannel(string $channel): array
    {
        if (str_starts_with($channel, 'presence-')) {
            return [substr($channel, 9), ChannelVisibility::Presence];
        }

        if (str_starts_with($channel, 'private-')) {
            return [substr($channel, 8), ChannelVisibility::Private];
        }

        return [$channel, ChannelVisibility::Public];
    }

    private function broadcastPayload(ShouldBroadcast $event): mixed
    {
        if (method_exists($event, 'broadcastWith')) {
            $payload = $event->broadcastWith();

            if ($payload !== null) {
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
            throw RealtimeSimulationException::unexpectedResult($operation, $result);
        }

        foreach ($result as $channel) {
            if (! is_string($channel)) {
                throw RealtimeSimulationException::unexpectedResult($operation, $result);
            }
        }

        return array_values($result);
    }
}

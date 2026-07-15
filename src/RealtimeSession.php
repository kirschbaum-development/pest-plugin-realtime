<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Contracts\ScriptExecutor;
use Pest\Realtime\Exceptions\RealtimeSimulationException;
use PHPUnit\Framework\Assert;

final readonly class RealtimeSession
{
    public function __construct(
        private ScriptExecutor $executor,
        private Driver $driver,
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
        string $channel,
        string $event,
        mixed $payload = [],
        ChannelVisibility $visibility = ChannelVisibility::Public,
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

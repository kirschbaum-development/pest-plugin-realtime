<?php

declare(strict_types=1);

namespace Pest\Realtime\Exceptions;

use RuntimeException;

final class RealtimeSimulationException extends RuntimeException
{
    public static function missingChannel(string $event): self
    {
        return new self(sprintf(
            'Emitting the raw realtime event [%s] requires an explicit channel.',
            $event,
        ));
    }

    public static function missingBroadcastChannels(object $event): self
    {
        return new self(sprintf(
            'The broadcast event [%s] did not provide any channels.',
            $event::class,
        ));
    }

    public static function invalidBroadcastChannel(object $event, mixed $channel): self
    {
        return new self(sprintf(
            'The broadcast event [%s] returned an invalid channel of type [%s].',
            $event::class,
            get_debug_type($channel),
        ));
    }

    public static function invalidBroadcastName(object $event, mixed $name): self
    {
        return new self(sprintf(
            'The broadcast event [%s] returned an invalid event name of type [%s].',
            $event::class,
            get_debug_type($name),
        ));
    }

    public static function broadcastEventOverrides(object $event): self
    {
        return new self(sprintf(
            'The broadcast event [%s] derives its channel, payload, and visibility; explicit overrides are not supported.',
            $event::class,
        ));
    }

    public static function broadcastCaptureUnavailable(): self
    {
        return new self(
            'Laravel broadcast capture is unavailable. Run captureBroadcasts() inside a booted Laravel application.',
        );
    }

    public static function nestedBroadcastCapture(): self
    {
        return new self('Laravel broadcast captures cannot be nested.');
    }

    public static function unexpectedResult(string $operation, mixed $result): self
    {
        return new self(sprintf(
            'The realtime browser runtime returned an unexpected result for [%s]: %s.',
            $operation,
            get_debug_type($result),
        ));
    }
}

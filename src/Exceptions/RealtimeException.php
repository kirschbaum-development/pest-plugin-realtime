<?php

declare(strict_types=1);

namespace Pest\Realtime\Exceptions;

use RuntimeException;

final class RealtimeException extends RuntimeException
{
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

    public static function broadcastCaptureUnavailable(): self
    {
        return new self(
            'Laravel broadcast capture is unavailable. Run this inside a booted Laravel application.',
        );
    }

    public static function captureAlreadyActive(): self
    {
        return new self(
            'Another realtime session is already capturing broadcasts for this application. '
            .'Call stopCapturing() on it before starting a second session.',
        );
    }

    public static function eventsAreFaked(): self
    {
        return new self(
            'Broadcast capture cannot run while events are faked. Laravel does not dispatch '
            .'broadcast listeners for events suppressed by Event::fake(), so nothing would be captured.',
        );
    }

    public static function clientNotReady(int $timeoutMilliseconds): self
    {
        return new self(sprintf(
            'Pest Realtime could not find an Echo/Pusher client within [%d] milliseconds. '
            .'Ensure the page creates its Echo subscriptions before the first realtime assertion.',
            $timeoutMilliseconds,
        ));
    }

    public static function unexpectedResult(string $operation, mixed $result): self
    {
        return new self(sprintf(
            'The realtime browser runtime returned an unexpected result for [%s]: %s.',
            $operation,
            get_debug_type($result),
        ));
    }

    public static function runtimeUnavailable(): self
    {
        return new self('The Echo/Pusher browser runtime could not be loaded.');
    }
}

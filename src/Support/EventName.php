<?php

declare(strict_types=1);

namespace Pest\Realtime\Support;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use ReflectionClass;
use Throwable;

/**
 * Resolves the wire names an assertion argument may refer to.
 *
 * Laravel hands a broadcaster only the wire name, so a class string has to be
 * matched against the name `broadcastAs()` would have produced. The event is
 * built without its constructor because tests assert against the class, not an
 * instance; `broadcastAs()` implementations that depend on constructor state
 * fall back to a plain string comparison.
 *
 * @internal
 */
final class EventName
{
    /**
     * @return list<string>
     */
    public static function candidates(string $event): array
    {
        if (! class_exists($event) || ! is_subclass_of($event, ShouldBroadcast::class)) {
            return [$event];
        }

        try {
            $instance = (new ReflectionClass($event))->newInstanceWithoutConstructor();

            if (! method_exists($instance, 'broadcastAs')) {
                return [$event];
            }

            $name = $instance->broadcastAs();
        } catch (Throwable) {
            return [$event];
        }

        return is_string($name) && $name !== $event
            ? [$event, $name]
            : [$event];
    }
}

<?php

declare(strict_types=1);

namespace Pest\Realtime\Exceptions;

use RuntimeException;

final class RealtimeSimulationException extends RuntimeException
{
    public static function unexpectedResult(string $operation, mixed $result): self
    {
        return new self(sprintf(
            'The realtime browser runtime returned an unexpected result for [%s]: %s.',
            $operation,
            get_debug_type($result),
        ));
    }
}

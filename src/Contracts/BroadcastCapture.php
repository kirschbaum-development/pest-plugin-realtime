<?php

declare(strict_types=1);

namespace Pest\Realtime\Contracts;

use Closure;
use Pest\Realtime\CapturedBroadcast;

interface BroadcastCapture
{
    /**
     * @return list<CapturedBroadcast>
     */
    public function capture(Closure $callback): array;
}

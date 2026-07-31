<?php

declare(strict_types=1);

namespace Pest\Realtime\Contracts;

use Closure;
use Pest\Realtime\CapturedBroadcast;

interface BroadcastCapture
{
    /**
     * Routes the application's broadcasts to the given callback until stopped.
     *
     * @param  Closure(CapturedBroadcast): void  $onBroadcast
     */
    public function start(Closure $onBroadcast): void;

    /**
     * Restores the application's broadcasting configuration.
     */
    public function stop(): void;

    public function capturing(): bool;

    /**
     * Returns and clears the broadcasts held back from the capture callback.
     *
     * @return list<CapturedBroadcast>
     */
    public function drainPending(): array;

    /**
     * An explanation for an empty capture log, when the application state
     * suggests one, or null when nothing stands out.
     */
    public function hint(): ?string;
}

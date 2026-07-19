<?php

declare(strict_types=1);

namespace Pest\Realtime\Laravel;

use Pest\Realtime\CapturedBroadcast;

final class BroadcastCollector
{
    /** @var list<CapturedBroadcast> */
    private array $broadcasts = [];

    public function add(CapturedBroadcast $broadcast): void
    {
        $this->broadcasts[] = $broadcast;
    }

    /**
     * @return list<CapturedBroadcast>
     */
    public function broadcasts(): array
    {
        return $this->broadcasts;
    }
}

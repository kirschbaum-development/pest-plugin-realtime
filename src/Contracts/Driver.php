<?php

declare(strict_types=1);

namespace Pest\Realtime\Contracts;

use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;

interface Driver
{
    public function installScript(): string;

    public function channelsScript(): string;

    public function statusScript(): string;

    public function socketIdScript(): string;

    public function transitionScript(ConnectionStatus $status): string;

    public function emitScript(
        string $channel,
        string $event,
        mixed $payload,
        ChannelVisibility $visibility,
    ): string;

    public function channelId(string $channel, ChannelVisibility $visibility): string;
}

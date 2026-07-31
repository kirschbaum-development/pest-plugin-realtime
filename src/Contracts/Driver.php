<?php

declare(strict_types=1);

namespace Pest\Realtime\Contracts;

use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\PresenceEvent;

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

    /**
     * Drives a membership change on a presence channel.
     */
    public function presenceScript(string $channel, PresenceEvent $event, mixed $payload): string;

    /**
     * Reads a presence channel's roster, keyed by member id.
     */
    public function membersScript(string $channel): string;

    /**
     * Reads the client events the page has sent.
     */
    public function clientEventsScript(): string;

    /**
     * Refuses a channel subscription, as a denied authorization would.
     */
    public function subscriptionErrorScript(
        string $channel,
        ChannelVisibility $visibility,
        mixed $payload,
    ): string;
}

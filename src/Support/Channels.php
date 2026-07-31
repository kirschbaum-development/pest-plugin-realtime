<?php

declare(strict_types=1);

namespace Pest\Realtime\Support;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Pest\Realtime\ChannelVisibility;
use Stringable;

/**
 * Translates between Laravel's channel vocabulary and the wire identifiers
 * Echo registers in the browser.
 *
 * @internal
 */
final class Channels
{
    /**
     * Resolves a channel to its name and visibility.
     *
     * Accepts Laravel's channel objects, an Eloquent model, a bare name such as
     * `auctions.1`, or a wire identifier such as `private-buyers.2`. Laravel's
     * channel objects stringify to the wire identifier, so both paths share one
     * parser.
     *
     * @return array{string, ChannelVisibility}
     */
    public static function parse(Channel|HasBroadcastChannel|string|Stringable $channel): array
    {
        // Checked before the string cast: models are Stringable too, and would
        // otherwise cast to their JSON rather than their channel name.
        if ($channel instanceof HasBroadcastChannel) {
            return [$channel->broadcastChannel(), ChannelVisibility::Private];
        }

        $identifier = (string) $channel;

        if (str_starts_with($identifier, 'presence-')) {
            return [substr($identifier, 9), ChannelVisibility::Presence];
        }

        // Checked before the broader `private-` prefix it starts with.
        if (str_starts_with($identifier, 'private-encrypted-')) {
            return [substr($identifier, 18), ChannelVisibility::PrivateEncrypted];
        }

        if (str_starts_with($identifier, 'private-')) {
            return [substr($identifier, 8), ChannelVisibility::Private];
        }

        return [$identifier, ChannelVisibility::Public];
    }

    /**
     * Builds the wire identifier Echo registers for the given channel.
     */
    public static function toWire(string $name, ChannelVisibility $visibility): string
    {
        return match ($visibility) {
            ChannelVisibility::Public => $name,
            ChannelVisibility::Private => 'private-'.$name,
            ChannelVisibility::Presence => 'presence-'.$name,
            ChannelVisibility::PrivateEncrypted => 'private-encrypted-'.$name,
        };
    }
}

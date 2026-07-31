<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\EncryptedPrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\Support\Channels;

it('treats a bare name as a public channel', function (): void {
    expect(Channels::parse('auctions.1'))->toBe(['auctions.1', ChannelVisibility::Public]);
});

it('reads visibility from a wire string', function (): void {
    expect(Channels::parse('private-buyers.2'))->toBe(['buyers.2', ChannelVisibility::Private])
        ->and(Channels::parse('presence-room.3'))->toBe(['room.3', ChannelVisibility::Presence]);
});

it('reads visibility from Laravel channel objects', function (): void {
    expect(Channels::parse(new Channel('auctions.1')))
        ->toBe(['auctions.1', ChannelVisibility::Public])
        ->and(Channels::parse(new PrivateChannel('buyers.2')))
        ->toBe(['buyers.2', ChannelVisibility::Private])
        ->and(Channels::parse(new PresenceChannel('room.3')))
        ->toBe(['room.3', ChannelVisibility::Presence]);
});

it('keeps the encrypted prefix inside a private channel name', function (): void {
    expect(Channels::parse(new EncryptedPrivateChannel('buyers.2')))
        ->toBe(['encrypted-buyers.2', ChannelVisibility::Private]);
});

it('round-trips a parsed channel back to its wire identifier', function (): void {
    $wire = ['auctions.1', 'private-buyers.2', 'presence-room.3', 'private-encrypted-buyers.2'];

    foreach ($wire as $identifier) {
        [$name, $visibility] = Channels::parse($identifier);

        expect(Channels::toWire($name, $visibility))->toBe($identifier);
    }
});

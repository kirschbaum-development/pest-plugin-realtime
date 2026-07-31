<?php

declare(strict_types=1);

use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\Drivers\EchoPusherDriver;

it('normalizes Echo channel identifiers', function (): void {
    $driver = new EchoPusherDriver();

    expect($driver->channelId('auctions.1', ChannelVisibility::Public))->toBe('auctions.1')
        ->and($driver->channelId('buyers.2', ChannelVisibility::Private))->toBe('private-buyers.2')
        ->and($driver->channelId('room.3', ChannelVisibility::Presence))->toBe('presence-room.3');
});

it('encodes event data safely into the browser script', function (): void {
    $script = (new EchoPusherDriver())->emitScript(
        'auctions.1',
        'App\\Events\\PriceChanged',
        ['message' => '</script><script>alert("unsafe")</script>'],
        ChannelVisibility::Public,
    );

    expect($script)
        ->toContain('App\\\\Events\\\\PriceChanged')
        ->toContain('\\u003C/script\\u003E');
});

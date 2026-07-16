<?php

declare(strict_types=1);

use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\EventDelivery;
use Pest\Realtime\Exceptions\RealtimeSimulationException;
use Pest\Realtime\RealtimeSession;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;
use Pest\Realtime\Tests\Fixtures\NamedPriceChanged;
use Pest\Realtime\Tests\Fixtures\PriceChanged;

it('normalizes Echo channel identifiers', function (): void {
    $driver = new EchoPusherDriver();

    expect($driver->channelId('auctions.1', ChannelVisibility::Public))->toBe('auctions.1')
        ->and($driver->channelId('buyers.2', ChannelVisibility::Private))->toBe('private-buyers.2')
        ->and($driver->channelId('room.3', ChannelVisibility::Presence))->toBe('presence-room.3');
});

it('installs, inspects subscriptions, and transitions connection state', function (): void {
    $executor = new FakeScriptExecutor([
        ['auctions.1', 'private-buyers.2'],
        ['auctions.1', 'private-buyers.2'],
        ConnectionStatus::Connected->value,
        ConnectionStatus::Connected->value,
        ConnectionStatus::Reconnecting->value,
        ConnectionStatus::Connected->value,
    ]);
    $session = new RealtimeSession($executor, new EchoPusherDriver());

    $session->install()
        ->assertSubscribed('buyers.2', ChannelVisibility::Private)
        ->connect();

    expect($session->status())->toBe(ConnectionStatus::Connected);

    $session->reconnect();

    expect($executor->scripts)->toHaveCount(6);
});

it('reports whether an event was delivered or dropped', function (): void {
    $executor = new FakeScriptExecutor([true, false]);
    $session = new RealtimeSession($executor, new EchoPusherDriver());

    expect($session->emit('PriceChanged', 'auctions.1', ['price' => 1200]))
        ->toBe(EventDelivery::Delivered)
        ->and($session->emit('PriceChanged', 'auctions.1', ['price' => 1300]))
        ->toBe(EventDelivery::Dropped);
});

it('derives channels, visibility, name, and payload from a broadcast event', function (): void {
    $executor = new FakeScriptExecutor([true, true, true]);
    $session = new RealtimeSession($executor, new EchoPusherDriver());

    expect($session->emit(new NamedPriceChanged(auctionId: 1, price: 1200)))
        ->toBe(EventDelivery::Delivered)
        ->and($executor->scripts)->toHaveCount(3)
        ->and($executor->scripts[0])->toContain('"auctions.1","price.changed",{"price":1200},"public"')
        ->and($executor->scripts[1])->toContain('"buyers.2","price.changed",{"price":1200},"private"')
        ->and($executor->scripts[2])->toContain('"room.3","price.changed",{"price":1200},"presence"');
});

it('uses the event class and public properties when broadcast overrides are absent', function (): void {
    $executor = new FakeScriptExecutor([true]);
    $session = new RealtimeSession($executor, new EchoPusherDriver());

    expect($session->emit(new PriceChanged(price: 1200)))
        ->toBe(EventDelivery::Delivered)
        ->and($executor->scripts[0])
        ->toContain('Pest\\\\Realtime\\\\Tests\\\\Fixtures\\\\PriceChanged')
        ->toContain('{"price":1200}');
});

it('requires a channel when emitting a raw event name', function (): void {
    $session = new RealtimeSession(new FakeScriptExecutor([]), new EchoPusherDriver());

    expect(fn () => $session->emit('PriceChanged'))
        ->toThrow(RealtimeSimulationException::class, 'requires an explicit channel');
});

it('rejects wire overrides for broadcast event objects', function (): void {
    $session = new RealtimeSession(new FakeScriptExecutor([]), new EchoPusherDriver());

    expect(fn () => $session->emit(new PriceChanged(price: 1200), channel: 'other'))
        ->toThrow(RealtimeSimulationException::class, 'explicit overrides are not supported');
});

it('rejects malformed browser runtime responses', function (): void {
    $session = new RealtimeSession(
        new FakeScriptExecutor(['not-a-channel-list']),
        new EchoPusherDriver(),
    );

    expect(fn () => $session->install())
        ->toThrow(RealtimeSimulationException::class, 'unexpected result for [install]');
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

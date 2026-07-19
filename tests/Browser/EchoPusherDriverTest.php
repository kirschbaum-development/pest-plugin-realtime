<?php

declare(strict_types=1);

use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\EventDelivery;
use Pest\Realtime\Tests\Fixtures\NamedPriceChanged;
use Pest\Realtime\Tests\Support\FixtureServer;

use function Pest\Realtime\broadcasting;

$fixtureServer = new FixtureServer();

beforeAll(function () use ($fixtureServer): void {
    $fixtureServer->start();
});

afterAll(function () use ($fixtureServer): void {
    $fixtureServer->stop();
});

it('integrates with real Laravel Echo and pusher-js clients', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $broadcasting = broadcasting($page)->install()
        ->assertSubscribed('auctions.1')
        ->assertSubscribed('buyers.2', ChannelVisibility::Private)
        ->assertSubscribed('room.3', ChannelVisibility::Presence)
        ->assertNotSubscribed('missing.4');

    $page->script(<<<'JS'
        window.__realRealtime.received.length = 0;
        window.__realRealtime.transitions.length = 0;
        window.__realRealtime.namedTransitions.length = 0;
    JS);

    expect($broadcasting->status())->toBe(ConnectionStatus::Disconnected)
        ->and($broadcasting->emit('price.changed', 'auctions.1', ['price' => 1200]))
        ->toBe(EventDelivery::Dropped)
        ->and($broadcasting->emit('price.changed', 'missing.4', ['price' => 1200]))
        ->toBe(EventDelivery::NotSubscribed);

    $broadcasting->connect();

    expect($broadcasting->emit(new NamedPriceChanged(auctionId: 1, price: 1400)))
        ->toBe(EventDelivery::Delivered)
        ->and($broadcasting->emit('price.changed', 'missing.4', ['price' => 1400]))
        ->toBe(EventDelivery::NotSubscribed)
        ->and($page->script('window.__realRealtime.received'))
        ->toBe([
            [
                'channel' => 'auctions.1',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
            [
                'channel' => 'private-buyers.2',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
            [
                'channel' => 'presence-room.3',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
        ]);

    $broadcasting->transitionTo(ConnectionStatus::Unavailable);

    expect($broadcasting->status())->toBe(ConnectionStatus::Unavailable)
        ->and($page->script('window.fixtureEcho.connectionStatus()'))->toBe('failed');

    $broadcasting->reconnect();

    expect($broadcasting->status())->toBe(ConnectionStatus::Connected)
        ->and($page->script('window.__realRealtime.connection.state'))->toBe('connected')
        ->and($page->script('window.__realRealtime.transitions'))->toBe([
            ['previous' => 'disconnected', 'current' => 'connected'],
            ['previous' => 'connected', 'current' => 'unavailable'],
            ['previous' => 'unavailable', 'current' => 'connecting'],
            ['previous' => 'connecting', 'current' => 'connected'],
        ])
        ->and($page->script('window.__realRealtime.namedTransitions'))->toBe([
            'connected',
            'unavailable',
            'connecting',
            'connected',
        ]);
});

it('supports an Echo instance exposed directly on window', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $page->script(<<<'JS'
        (() => {
            const received = [];
            const listeners = {};
            const connection = {
                state: 'connecting',
                bind: (event, callback) => {
                    listeners[event] ??= [];
                    listeners[event].push(callback);
                },
                emit: (event, payload) => {
                    for (const listener of listeners[event] ?? []) {
                        listener(payload);
                    }
                },
            };
            const channel = {
                emit: (event, payload) => received.push({ event, payload }),
            };
            const pusher = {
                connection,
                channels: { channels: { 'auctions.1': channel } },
                disconnect: () => {
                    connection.state = 'disconnected';
                },
            };

            delete window.__pestRealtime;
            window.Pusher.instances.length = 0;
            window.Echo = { connector: { pusher } };
            window.__fakeRealtime = { received };
        })()
    JS);

    $broadcasting = broadcasting($page)->install()->connect();

    expect($broadcasting->emit('price.changed', 'auctions.1', ['price' => 1500]))
        ->toBe(EventDelivery::Delivered)
        ->and($page->script('window.__fakeRealtime.received'))->toBe([
            ['event' => 'price.changed', 'payload' => ['price' => 1500]],
        ]);
});

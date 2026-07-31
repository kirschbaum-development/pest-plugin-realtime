<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\ConnectionStatus;
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

    $broadcasting = broadcasting($page)->install();

    $page->script(<<<'JS'
        window.__realRealtime.received.length = 0;
        window.__realRealtime.transitions.length = 0;
        window.__realRealtime.namedTransitions.length = 0;
    JS);

    $broadcasting
        ->assertSubscribed('auctions.1')
        ->assertSubscribed(new PrivateChannel('buyers.2'))
        ->assertSubscribed(new PresenceChannel('room.3'))
        ->assertNotSubscribed('missing.4')
        ->assertConnected();

    $broadcasting->disconnect()
        ->emit('price.changed', 'auctions.1', ['price' => 1200])
        ->emit('price.changed', 'missing.4', ['price' => 1200])
        ->assertDropped('price.changed')
        ->assertNotDelivered('price.changed');

    expect($broadcasting->captured()->notSubscribedCount())->toBe(1);

    $broadcasting->connect()
        ->broadcast(new NamedPriceChanged(auctionId: 1, price: 1400))
        ->assertDeliveredTimes('price.changed', 3)
        ->assertDeliveredOn(new PresenceChannel('room.3'), 'price.changed');

    expect($page->script('window.__realRealtime.received'))->toBe([
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

    $broadcasting->unavailable();

    expect($broadcasting->status())->toBe(ConnectionStatus::Unavailable)
        ->and($page->script('window.fixtureEcho.connectionStatus()'))->toBe('failed');

    $broadcasting->reconnect()->assertConnected();

    expect($page->script('window.__realRealtime.connection.state'))->toBe('connected')
        ->and($page->script('window.__realRealtime.transitions'))->toBe([
            ['previous' => 'connected', 'current' => 'disconnected'],
            ['previous' => 'disconnected', 'current' => 'connected'],
            ['previous' => 'connected', 'current' => 'unavailable'],
            ['previous' => 'unavailable', 'current' => 'connecting'],
            ['previous' => 'connecting', 'current' => 'connected'],
        ])
        ->and($page->script('window.__realRealtime.namedTransitions'))->toBe([
            'disconnected',
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

    broadcasting($page)
        ->emit('price.changed', 'auctions.1', ['price' => 1500])
        ->assertDelivered('price.changed');

    expect($page->script('window.__fakeRealtime.received'))->toBe([
        ['event' => 'price.changed', 'payload' => ['price' => 1500]],
    ]);
});

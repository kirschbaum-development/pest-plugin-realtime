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

it('simulates Echo Pusher events, dropped delivery, and connection recovery', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $page->script(<<<'JS'
        (() => {
            const listeners = {};
            const received = [];
            const transitions = [];

            const connection = {
                state: 'connecting',
                bind: (event, callback) => {
                    listeners[event] ??= [];
                    listeners[event].push(callback);
                },
                unbind: (event, callback) => {
                    listeners[event] = (listeners[event] ?? []).filter(
                        (listener) => listener !== callback,
                    );
                },
                emit: (event, payload) => {
                    if (event === 'state_change') {
                        transitions.push(payload);
                    }

                    for (const listener of listeners[event] ?? []) {
                        listener(payload);
                    }
                },
            };

            const makeChannel = (name) => ({
                emit: (event, payload) => received.push({ name, event, payload }),
            });

            const pusher = {
                connection,
                channels: {
                    channels: {
                        'auctions.1': makeChannel('auctions.1'),
                        'private-buyers.2': makeChannel('private-buyers.2'),
                        'presence-room.3': makeChannel('presence-room.3'),
                    },
                },
                disconnect: () => {
                    const previous = connection.state;
                    connection.state = 'disconnected';
                    connection.emit('state_change', {
                        previous,
                        current: 'disconnected',
                    });
                },
            };

            window.Echo = { connector: { pusher } };
            window.__fakeRealtime = { received, transitions, connection };
        })()
    JS);

    $broadcasting = broadcasting($page)->install()
        ->assertSubscribed('auctions.1')
        ->assertSubscribed('buyers.2', ChannelVisibility::Private)
        ->assertSubscribed('room.3', ChannelVisibility::Presence);

    expect($broadcasting->status())->toBe(ConnectionStatus::Disconnected)
        ->and($broadcasting->emit('PriceChanged', 'auctions.1', ['price' => 1200]))
        ->toBe(EventDelivery::Dropped);

    $broadcasting->connect();

    expect($broadcasting->emit('PriceChanged', 'auctions.1', ['price' => 1300]))
        ->toBe(EventDelivery::Delivered)
        ->and($broadcasting->emit(new NamedPriceChanged(auctionId: 1, price: 1400)))
        ->toBe(EventDelivery::Delivered)
        ->and($page->script('window.__fakeRealtime.received'))
        ->toBe([
            [
                'name' => 'auctions.1',
                'event' => 'PriceChanged',
                'payload' => ['price' => 1300],
            ],
            [
                'name' => 'auctions.1',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
            [
                'name' => 'private-buyers.2',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
            [
                'name' => 'presence-room.3',
                'event' => 'price.changed',
                'payload' => ['price' => 1400],
            ],
        ]);

    $broadcasting->disconnect()->fail()->reconnect();

    expect($broadcasting->status())->toBe(ConnectionStatus::Connected)
        ->and($page->script('window.__fakeRealtime.connection.state'))->toBe('connected')
        ->and($page->script('window.__fakeRealtime.transitions.length'))->toBe(6);
});

<?php

declare(strict_types=1);

use Illuminate\Broadcasting\EncryptedPrivateChannel;
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

it('delivers events to encrypted private channels', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $broadcasting = broadcasting($page)->install();

    $page->script('window.__realRealtime.received.length = 0;');

    $broadcasting
        ->assertSubscribed(new EncryptedPrivateChannel('vault.5'))
        ->emit('price.changed', new EncryptedPrivateChannel('vault.5'), ['price' => 1600])
        ->assertDeliveredOn(new EncryptedPrivateChannel('vault.5'), 'price.changed');

    // pusher-js drops plaintext on an encrypted channel, so the page listener
    // firing is what separates a real delivery from a reported one.
    expect($page->script('window.__realRealtime.received'))->toBe([
        [
            'channel' => 'private-encrypted-vault.5',
            'event' => 'price.changed',
            'payload' => ['price' => 1600],
        ],
    ]);
});

it('fires Echo presence callbacks for membership changes', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $broadcasting = broadcasting($page)->install();

    $broadcasting
        ->here(new PresenceChannel('room.3'), [['id' => 1, 'name' => 'Ana']])
        ->assertMemberCount(new PresenceChannel('room.3'), 1)
        ->joining(new PresenceChannel('room.3'), ['id' => 2, 'name' => 'Bo'])
        ->assertMember(new PresenceChannel('room.3'), 2)
        ->leaving(new PresenceChannel('room.3'), 2)
        ->assertNotMember(new PresenceChannel('room.3'), 2);

    expect($page->script('window.__realRealtime.presence'))->toBe([
        'here' => [[['id' => 1, 'name' => 'Ana']]],
        'joining' => [['id' => 2, 'name' => 'Bo']],
        'leaving' => [['id' => 2, 'name' => 'Bo']],
    ]);

    expect($broadcasting->members(new PresenceChannel('room.3')))
        ->toBe([1 => ['id' => 1, 'name' => 'Ana']]);
});

it('carries client events in both directions', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    $broadcasting = broadcasting($page)->install();

    $broadcasting->whisper('typing', new PrivateChannel('buyers.2'), ['name' => 'Ana']);

    expect($page->script('window.__realRealtime.whispered'))->toBe([['name' => 'Ana']]);

    $page->script(<<<'JS'
        window.fixtureEcho.private('buyers.2').whisper('typing', { name: 'Bo' });
    JS);

    $broadcasting->assertWhispered('typing')->assertNotWhispered('resize');

    $whisper = $broadcasting->whispers()->first();

    expect($whisper?->channel)->toBe('private-buyers.2')
        ->and($whisper?->payload)->toBe(['name' => 'Bo'])
        ->and($whisper?->connected)->toBeTrue();
});

it('fires Echo subscription error callbacks', function () use ($fixtureServer): void {
    $page = visit($fixtureServer->url());

    broadcasting($page)->failSubscription(
        new PrivateChannel('buyers.2'),
        status: 403,
        message: 'Unauthorized',
    );

    expect($page->script('window.__realRealtime.errors'))->toBe([
        ['type' => 'AuthError', 'error' => 'Unauthorized', 'status' => 403],
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

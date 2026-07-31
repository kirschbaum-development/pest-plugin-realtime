<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\Broadcasting;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Exceptions\RealtimeException;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;
use Pest\Realtime\Tests\Fixtures\NamedPriceChanged;
use Pest\Realtime\Tests\Fixtures\Post;
use Pest\Realtime\Tests\Fixtures\PriceChanged;
use PHPUnit\Framework\ExpectationFailedException;

it('releases the session and stops capture when it goes out of scope', function (): void {
    $capture = new class() implements BroadcastCapture
    {
        private bool $capturing = false;

        private ?Closure $onBroadcast = null;

        public function start(Closure $onBroadcast): void
        {
            $this->capturing = true;
            $this->onBroadcast = $onBroadcast;
        }

        public function stop(): void
        {
            $this->capturing = false;
            $this->onBroadcast = null;
        }

        public function capturing(): bool
        {
            return $this->capturing && $this->onBroadcast instanceof Closure;
        }

        public function drainPending(): array
        {
            return [];
        }

        public function hint(): ?string
        {
            return null;
        }
    };
    $broadcasting = new Broadcasting(
        new FakeScriptExecutor([]),
        new EchoPusherDriver(),
        $capture,
    );
    $session = WeakReference::create($broadcasting);

    unset($broadcasting);

    expect($session->get())->toBeNull()
        ->and($capture->capturing())->toBeFalse();
});

it('accepts a model where a channel is expected', function (): void {
    $post = new Post();
    $post->id = 1;

    $executor = new FakeScriptExecutor([
        ['private-Pest.Realtime.Tests.Fixtures.Post.1'],
        'connected',
        ['private-Pest.Realtime.Tests.Fixtures.Post.1'],
        'delivered',
    ]);
    $broadcasting = new Broadcasting($executor, new EchoPusherDriver());

    $broadcasting
        ->assertSubscribed($post)
        ->emit('PostUpdated', $post, ['model' => ['id' => 1]])
        ->assertDeliveredOn($post, 'PostUpdated');
});

it('installs and connects lazily on first use', function (): void {
    [$broadcasting, $executor] = session([['auctions.1', 'private-buyers.2']]);

    expect($executor->scripts)->toHaveCount(0);

    $broadcasting->assertSubscribed('auctions.1');

    expect($executor->scripts)->toHaveCount(3)
        ->and($executor->scripts[1])->toContain('runtime["transitionTo"](...["connected"])');
});

it('installs only once', function (): void {
    [$broadcasting, $executor] = session([
        ['auctions.1', 'private-buyers.2'],
        ['auctions.1', 'private-buyers.2'],
    ]);

    $broadcasting->assertSubscribed('auctions.1')->assertSubscribed('private-buyers.2');

    expect($executor->scripts)->toHaveCount(4);
});

it('accepts Laravel channel objects when asserting subscriptions', function (): void {
    [$broadcasting] = session([['auctions.1', 'private-buyers.2']]);

    expect($broadcasting->assertSubscribed(new PrivateChannel('buyers.2')))
        ->toBeInstanceOf(Broadcasting::class);
});

it('fails when the page never subscribes to the channel', function (): void {
    [$broadcasting] = session([['auctions.1']]);

    expect(fn () => $broadcasting->assertSubscribed('missing.9', timeoutMilliseconds: 0))
        ->toThrow(ExpectationFailedException::class, 'realtime channel [missing.9]');
});

it('records an emitted event in the delivery log', function (): void {
    [$broadcasting] = session(['delivered']);

    $broadcasting->emit('price.changed', 'auctions.1', ['price' => 2200])
        ->assertDelivered('price.changed')
        ->assertNothingDropped();
});

it('derives channels, name and payload from a broadcast event', function (): void {
    [$broadcasting, $executor] = session(['delivered', 'delivered', 'delivered']);

    $broadcasting->broadcast(new NamedPriceChanged(auctionId: 1, price: 1200))
        ->assertDeliveredTimes('price.changed', 3)
        ->assertDeliveredOn(new PrivateChannel('buyers.2'), 'price.changed');

    expect($executor->scripts[2])->toContain('"auctions.1","price.changed",{"price":1200},"public"')
        ->and($executor->scripts[3])->toContain('"buyers.2","price.changed",{"price":1200},"private"')
        ->and($executor->scripts[4])->toContain('"room.3","price.changed",{"price":1200},"presence"');
});

it('falls back to the event class and public properties', function (): void {
    [$broadcasting, $executor] = session(['delivered']);

    $broadcasting->broadcast(new PriceChanged(price: 1200))
        ->assertDelivered(PriceChanged::class);

    expect($executor->scripts[2])->toContain('{"price":1200}');
});

it('records a dropped event when the connection is down', function (): void {
    [$broadcasting] = session([ConnectionStatus::Disconnected->value, 'dropped']);

    $broadcasting->disconnect()
        ->emit('price.changed', 'auctions.1')
        ->assertDropped('price.changed')
        ->assertNotDelivered('price.changed');
});

it('asserts the simulated connection status', function (): void {
    [$broadcasting] = session([
        ConnectionStatus::Connected->value,
        ConnectionStatus::Disconnected->value,
        ConnectionStatus::Disconnected->value,
    ]);

    $broadcasting->assertConnected()->disconnect()->assertDisconnected();
});

it('fails assertConnected with the observed status', function (): void {
    [$broadcasting] = session([ConnectionStatus::Failed->value]);

    expect(fn () => $broadcasting->assertConnected())
        ->toThrow(ExpectationFailedException::class, 'failed');
});

it('reconnects through the connecting state', function (): void {
    [$broadcasting, $executor] = session([
        ConnectionStatus::Connecting->value,
        ConnectionStatus::Connected->value,
    ]);

    $broadcasting->reconnect();

    expect($executor->scripts[2])->toContain('runtime["transitionTo"](...["connecting"])')
        ->and($executor->scripts[3])->toContain('runtime["transitionTo"](...["connected"])');
});

it('requires a Laravel application to capture application code', function (): void {
    [$broadcasting] = session([]);

    expect(fn () => $broadcasting->capture(fn () => null))
        ->toThrow(RealtimeException::class, 'Laravel broadcast capture is unavailable');
});

it('waits for the client and for late channel subscriptions', function (): void {
    $executor = new FakeScriptExecutor([
        null,
        [],
        ConnectionStatus::Connected->value,
        [],
        ['auctions.1'],
        ['auctions.1'],
    ]);
    $broadcasting = new Broadcasting($executor, new EchoPusherDriver());

    $broadcasting->install(timeoutMilliseconds: 200)
        ->assertSubscribed('auctions.1', timeoutMilliseconds: 200)
        ->assertNotSubscribed(new PrivateChannel('buyers.2'));

    expect($executor->scripts)->toHaveCount(6);
});

it('rejects a browser runtime that never produces a client', function (): void {
    $executor = new FakeScriptExecutor([null]);
    $broadcasting = new Broadcasting($executor, new EchoPusherDriver());

    expect(fn () => $broadcasting->install(timeoutMilliseconds: 0))
        ->toThrow(RealtimeException::class, 'could not find an Echo/Pusher client');
});

it('rejects malformed browser runtime responses', function (): void {
    $executor = new FakeScriptExecutor(['not-a-channel-list']);
    $broadcasting = new Broadcasting($executor, new EchoPusherDriver());

    expect(fn () => $broadcasting->install())
        ->toThrow(RealtimeException::class, 'unexpected result for [install]');
});

<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use Pest\Realtime\Broadcasting;
use Pest\Realtime\CapturedBroadcast;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\DeliveryOutcome;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Exceptions\RealtimeException;
use Pest\Realtime\Laravel\LaravelBroadcastCapture;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;
use Pest\Realtime\Tests\Fixtures\CapturedPriceChanged;
use Pest\Realtime\Tests\Fixtures\PriceChanged;
use Pest\Realtime\Tests\Support\LaravelBroadcastHarness;

/**
 * @param  list<mixed>  $results
 * @return array{Broadcasting, LaravelBroadcastHarness, FakeScriptExecutor}
 */
function laravelSession(array $results): array
{
    $executor = new FakeScriptExecutor([
        ['auctions.1', 'private-buyers.2'],
        'connected',
        ...$results,
    ]);
    $laravel = new LaravelBroadcastHarness();

    $broadcasting = new Broadcasting(
        $executor,
        new EchoPusherDriver(),
        LaravelBroadcastCapture::fromContainer($laravel->container),
    );

    return [$broadcasting, $laravel, $executor];
}

it('replays application broadcasts without a capture closure', function (): void {
    [$broadcasting, $laravel, $executor] = laravelSession([
        'delivered',
        'delivered',
        'not_subscribed',
    ]);

    (new BroadcastEvent(new CapturedPriceChanged(price: 1200)))->handle($laravel->manager);

    $broadcasting
        ->assertDelivered('price.changed')
        ->assertDeliveredTimes('price.changed', 2);

    expect($executor->scripts[2])->toContain('"auctions.1","price.changed",{"price":1200},"public"')
        ->and($executor->scripts[3])->toContain('"buyers.2","price.changed",{"price":1200},"private"')
        ->and($executor->scripts[4])->toContain('"room.3","price.changed",{"price":1200},"presence"');
});

it('ignores broadcasts dispatched from another fiber', function (): void {
    [$broadcasting, $laravel, $executor] = laravelSession([]);

    $fiber = new Fiber(function () use ($laravel): void {
        (new BroadcastEvent(new CapturedPriceChanged(price: 1200)))->handle($laravel->manager);
    });

    $fiber->start();

    expect($broadcasting->captured()->capturedCount())->toBe(0)
        ->and($executor->scripts)->toBe([]);
});

it('scopes capture() to the broadcasts its callback produced', function (): void {
    [$broadcasting, $laravel] = laravelSession([
        'delivered', 'delivered', 'not_subscribed',
        'delivered', 'delivered', 'not_subscribed',
    ]);

    (new BroadcastEvent(new CapturedPriceChanged(price: 1200)))->handle($laravel->manager);

    $captured = $broadcasting->capture(function () use ($laravel): void {
        (new BroadcastEvent(new CapturedPriceChanged(price: 3300)))->handle($laravel->manager);
    });

    expect($captured->capturedCount())->toBe(1)
        ->and($captured->deliveries())->toHaveCount(3)
        ->and($captured->deliveredCount())->toBe(2)
        ->and($captured->notSubscribedCount())->toBe(1)
        ->and($captured->broadcasts()->first()?->payload)->toBe(['price' => 3300])
        ->and($broadcasting->captured()->deliveries())->toHaveCount(6);
});

it('records the resolved Laravel connection and channel visibility', function (): void {
    [$broadcasting, $laravel] = laravelSession(['delivered', 'delivered', 'not_subscribed']);

    (new BroadcastEvent(new CapturedPriceChanged(price: 1200)))->handle($laravel->manager);

    $deliveries = $broadcasting->captured()->deliveries();

    expect($deliveries[0]->broadcast->connection)->toBe('secondary')
        ->and($deliveries[0]->broadcast->channels)->toBe([
            'auctions.1',
            'private-buyers.2',
            'presence-room.3',
        ])
        ->and($deliveries[2]->channel)->toBe('room.3')
        ->and($deliveries[2]->visibility)->toBe(ChannelVisibility::Presence)
        ->and($deliveries[2]->outcome)->toBe(DeliveryOutcome::NotSubscribed);
});

it('records the default connection when the event selects none', function (): void {
    [$broadcasting, $laravel] = laravelSession(['delivered']);

    (new BroadcastEvent(new PriceChanged(price: 1200)))->handle($laravel->manager);

    expect($broadcasting->captured()->broadcasts()->first()?->connection)->toBe('null');
});

it('swaps broadcasting and queue configuration only while capturing', function (): void {
    [$broadcasting, $laravel] = laravelSession([]);

    expect($laravel->config->get('broadcasting.default'))->toBe('pest-realtime-capture')
        ->and($laravel->config->get('queue.default'))->toBe('sync');

    $broadcasting->stopCapturing();

    expect($laravel->config->get('broadcasting.default'))->toBe('null')
        ->and($laravel->config->get('queue.default'))->toBe('database')
        ->and($laravel->config->get('broadcasting.connections'))->toBe([
            'null' => ['driver' => 'null'],
            'secondary' => ['driver' => 'null'],
        ])
        ->and($laravel->manager->connection('secondary'))->toBeInstanceOf(NullBroadcaster::class);
});

it('restores broadcasting when the captured code throws', function (): void {
    [$broadcasting, $laravel] = laravelSession([]);

    expect(fn () => $broadcasting->capture(
        fn () => throw new RuntimeException('application failed'),
    ))->toThrow(RuntimeException::class, 'application failed');

    $broadcasting->stopCapturing();

    expect($laravel->config->get('broadcasting.default'))->toBe('null');
});

it('rejects a second session capturing the same application', function (): void {
    [$broadcasting, $laravel] = laravelSession([]);

    expect(fn () => new Broadcasting(
        new FakeScriptExecutor([]),
        new EchoPusherDriver(),
        LaravelBroadcastCapture::fromContainer($laravel->container),
    ))->toThrow(RealtimeException::class, 'already capturing broadcasts');

    $broadcasting->stopCapturing();
});

it('refuses to capture while events are faked', function (): void {
    $laravel = new LaravelBroadcastHarness();
    $laravel->container->instance('events', new EventFake(new Dispatcher()));

    expect(fn () => new Broadcasting(
        new FakeScriptExecutor([]),
        new EchoPusherDriver(),
        LaravelBroadcastCapture::fromContainer($laravel->container),
    ))->toThrow(RealtimeException::class, 'while events are faked');
});

it('excludes the page when a broadcast targets other sockets', function (): void {
    $executor = new FakeScriptExecutor([
        ['auctions.1'],
        'connected',
        'pest-socket-1',
    ]);
    $laravel = new LaravelBroadcastHarness();
    $broadcasting = new Broadcasting(
        $executor,
        new EchoPusherDriver(),
        LaravelBroadcastCapture::fromContainer($laravel->container),
    );

    (new BroadcastEvent(new CapturedPriceChanged(price: 1200, socket: 'pest-socket-1')))
        ->handle($laravel->manager);

    expect($broadcasting->captured()->excludedCount())->toBe(3)
        ->and($broadcasting->captured()->deliveredCount())->toBe(0)
        ->and($broadcasting->socketId())->toBe('pest-socket-1');
});

it('strips the socket from the payload it hands the page', function (): void {
    [$broadcasting, $laravel] = laravelSession(['socket-that-does-not-match', 'delivered', 'delivered', 'not_subscribed']);

    (new BroadcastEvent(new CapturedPriceChanged(price: 1200, socket: 'someone-else')))
        ->handle($laravel->manager);

    $broadcast = $broadcasting->captured()->broadcasts()->first();

    expect($broadcast)->toBeInstanceOf(CapturedBroadcast::class)
        ->and($broadcast?->socket)->toBe('someone-else')
        ->and($broadcast?->payload)->toBe(['price' => 1200]);
});

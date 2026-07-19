<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\EventDelivery;
use Pest\Realtime\Exceptions\RealtimeSimulationException;
use Pest\Realtime\Laravel\LaravelBroadcastCapture;
use Pest\Realtime\RealtimeSession;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;
use Pest\Realtime\Tests\Fixtures\CapturedPriceChanged;
use Pest\Realtime\Tests\Fixtures\PriceChanged;
use Pest\Realtime\Tests\Support\LaravelBroadcastHarness;

it('captures selected Laravel broadcast connections and replays their final wire representation', function (): void {
    $executor = new FakeScriptExecutor([
        EventDelivery::Delivered->value,
        EventDelivery::Delivered->value,
        EventDelivery::NotSubscribed->value,
    ]);
    $laravel = new LaravelBroadcastHarness();
    $capture = LaravelBroadcastCapture::fromContainer($laravel->container);
    $session = new RealtimeSession($executor, new EchoPusherDriver(), $capture);

    $batch = $session->captureBroadcasts(function () use ($laravel): void {
        (new BroadcastEvent(new CapturedPriceChanged(price: 1200, socket: '123.456')))
            ->handle($laravel->manager);
    });

    expect($batch->capturedCount())->toBe(1)
        ->and($batch->deliveredCount())->toBe(2)
        ->and($batch->droppedCount())->toBe(0)
        ->and($batch->notSubscribedCount())->toBe(1)
        ->and($batch->allDelivered())->toBeFalse()
        ->and($batch->broadcasts())->toHaveCount(1)
        ->and($batch->broadcasts()[0]->connection)->toBe('secondary')
        ->and($batch->broadcasts()[0]->socket)->toBe('123.456')
        ->and($batch->broadcasts()[0]->channels)->toBe([
            'auctions.1',
            'private-buyers.2',
            'presence-room.3',
        ])
        ->and($batch->broadcasts()[0]->event)->toBe('price.changed')
        ->and($batch->broadcasts()[0]->payload)->toBe(['price' => 1200])
        ->and($batch->deliveries())->toHaveCount(3)
        ->and($batch->deliveries()[2]->channel)->toBe('room.3')
        ->and($batch->deliveries()[2]->visibility->value)->toBe('presence')
        ->and($batch->deliveries()[2]->outcome)->toBe(EventDelivery::NotSubscribed)
        ->and($executor->scripts)->toHaveCount(3)
        ->and($executor->scripts[0])->toContain('"auctions.1","price.changed",{"price":1200},"public"')
        ->and($executor->scripts[1])->toContain('"buyers.2","price.changed",{"price":1200},"private"')
        ->and($executor->scripts[2])->toContain('"room.3","price.changed",{"price":1200},"presence"')
        ->and($laravel->config->get('broadcasting.default'))->toBe('null')
        ->and($laravel->config->get('broadcasting.connections'))->toBe([
            'null' => ['driver' => 'null'],
            'secondary' => ['driver' => 'null'],
        ])
        ->and($laravel->manager->connection('secondary'))->toBeInstanceOf(NullBroadcaster::class);
});

it('records the resolved default Laravel broadcast connection', function (): void {
    $executor = new FakeScriptExecutor([EventDelivery::Delivered->value]);
    $laravel = new LaravelBroadcastHarness();
    $capture = LaravelBroadcastCapture::fromContainer($laravel->container);
    $session = new RealtimeSession($executor, new EchoPusherDriver(), $capture);

    $batch = $session->captureBroadcasts(function () use ($laravel): void {
        (new BroadcastEvent(new PriceChanged(price: 1200)))
            ->handle($laravel->manager);
    });

    expect($batch->broadcasts()[0]->connection)->toBe('null')
        ->and($batch->allDelivered())->toBeTrue();
});

it('restores Laravel broadcasting when the captured code throws', function (): void {
    $laravel = new LaravelBroadcastHarness();
    $capture = LaravelBroadcastCapture::fromContainer($laravel->container);
    $session = new RealtimeSession(
        new FakeScriptExecutor([]),
        new EchoPusherDriver(),
        $capture,
    );

    expect(fn () => $session->captureBroadcasts(
        fn () => throw new RuntimeException('application failed'),
    ))->toThrow(RuntimeException::class, 'application failed')
        ->and($laravel->config->get('broadcasting.default'))->toBe('null')
        ->and($laravel->config->get('broadcasting.connections'))->toBe([
            'null' => ['driver' => 'null'],
            'secondary' => ['driver' => 'null'],
        ]);
});

it('rejects nested captures across separate capture instances', function (): void {
    $laravel = new LaravelBroadcastHarness();
    $outer = LaravelBroadcastCapture::fromContainer($laravel->container);
    $inner = LaravelBroadcastCapture::fromContainer($laravel->container);

    expect(fn () => $outer?->capture(
        fn () => $inner?->capture(fn () => null),
    ))->toThrow(RealtimeSimulationException::class, 'cannot be nested')
        ->and($laravel->config->get('broadcasting.default'))->toBe('null')
        ->and($laravel->config->get('broadcasting.connections'))->toBe([
            'null' => ['driver' => 'null'],
            'secondary' => ['driver' => 'null'],
        ]);
});

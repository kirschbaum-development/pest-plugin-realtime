<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Laravel\LaravelBroadcastCapture;
use Pest\Realtime\RealtimeSession;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;
use Pest\Realtime\Tests\Fixtures\CapturedPriceChanged;
use Pest\Realtime\Tests\Support\LaravelBroadcastHarness;

it('captures selected Laravel broadcast connections and replays their final wire representation', function (): void {
    $executor = new FakeScriptExecutor([true, true, false]);
    $laravel = new LaravelBroadcastHarness();
    $capture = LaravelBroadcastCapture::fromContainer($laravel->container);
    $session = new RealtimeSession($executor, new EchoPusherDriver(), $capture);

    $batch = $session->captureBroadcasts(function () use ($laravel): void {
        (new BroadcastEvent(new CapturedPriceChanged(price: 1200)))
            ->handle($laravel->manager);
    });

    expect($batch->capturedCount())->toBe(1)
        ->and($batch->deliveredCount())->toBe(2)
        ->and($batch->droppedCount())->toBe(1)
        ->and($batch->allDelivered())->toBeFalse()
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

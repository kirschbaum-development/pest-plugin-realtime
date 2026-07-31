<?php

declare(strict_types=1);

use Pest\Browser\Playwright\Playwright;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Realtime;

afterEach(function (): void {
    Realtime::flush();
});

it('defaults to the Echo Pusher driver', function (): void {
    expect(Realtime::driver())->toBeInstanceOf(EchoPusherDriver::class);
});

it('remembers a driver set once', function (): void {
    $driver = new EchoPusherDriver();

    expect(Realtime::driver($driver))->toBe($driver)
        ->and(Realtime::driver())->toBe($driver);

    Realtime::flush();

    expect(Realtime::driver())->not->toBe($driver);
});

it('falls back to Pest Browser own timeout', function (): void {
    expect(Realtime::timeout())->toBe(Playwright::timeout());
});

it('remembers a timeout set once', function (): void {
    expect(Realtime::timeout(1234))->toBe(1234)
        ->and(Realtime::timeout())->toBe(1234);

    Realtime::flush();

    expect(Realtime::timeout())->toBe(Playwright::timeout());
});

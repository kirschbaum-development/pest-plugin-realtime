<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\Exceptions\RealtimeException;

it('fails a channel subscription the way a denied authorization would', function (): void {
    [$broadcasting, $executor] = session(['ok']);

    $broadcasting->failSubscription(new PrivateChannel('orders.1'));

    expect($executor->scripts[2])->toContain('"orders.1"')
        ->and($executor->scripts[2])->toContain('"private"')
        ->and($executor->scripts[2])->toContain('"type":"AuthError"')
        ->and($executor->scripts[2])->toContain('"status":403');
});

it('carries a custom status and message', function (): void {
    [$broadcasting, $executor] = session(['ok']);

    $broadcasting->failSubscription(
        new PresenceChannel('room.3'),
        status: 419,
        message: 'Session expired',
    );

    expect($executor->scripts[2])->toContain('"status":419')
        ->and($executor->scripts[2])->toContain('"error":"Session expired"')
        ->and($executor->scripts[2])->toContain('"presence"');
});

it('fails when the page never registered the channel', function (): void {
    [$broadcasting] = session(['not_subscribed']);

    expect(fn () => $broadcasting->failSubscription(new PrivateChannel('orders.9')))
        ->toThrow(RealtimeException::class, 'private-orders.9');
});

it('does not record a subscription failure as a delivery', function (): void {
    [$broadcasting] = session(['ok']);

    $broadcasting->failSubscription(new PrivateChannel('orders.1'));

    expect($broadcasting->captured()->deliveries())->toHaveCount(0);
});

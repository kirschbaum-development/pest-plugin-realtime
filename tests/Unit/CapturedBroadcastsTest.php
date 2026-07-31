<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use Pest\Realtime\CapturedBroadcast;
use Pest\Realtime\CapturedBroadcasts;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\Delivery;
use Pest\Realtime\DeliveryOutcome;
use Pest\Realtime\Tests\Fixtures\NamedPriceChanged;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * @param  array<array-key, mixed>  $payload
 * @param  list<string>|null  $channels
 */
function delivery(
    string $event,
    string $channel,
    DeliveryOutcome $outcome,
    ChannelVisibility $visibility = ChannelVisibility::Public,
    array $payload = [],
    ?array $channels = null,
): Delivery {
    return new Delivery(
        new CapturedBroadcast(
            channels: $channels ?? [$channel],
            event: $event,
            payload: $payload,
        ),
        $channel,
        $visibility,
        $outcome,
    );
}

it('exposes deliveries and distinct broadcasts as collections', function (): void {
    $broadcast = new CapturedBroadcast(['auctions.1', 'private-buyers.2'], 'price.changed', []);

    $broadcasts = new CapturedBroadcasts([
        new Delivery($broadcast, 'auctions.1', ChannelVisibility::Public, DeliveryOutcome::Delivered),
        new Delivery($broadcast, 'buyers.2', ChannelVisibility::Private, DeliveryOutcome::Dropped),
    ]);

    expect($broadcasts->deliveries())->toBeInstanceOf(Collection::class)
        ->and($broadcasts->deliveries())->toHaveCount(2)
        ->and($broadcasts->broadcasts())->toBeInstanceOf(Collection::class)
        ->and($broadcasts->broadcasts())->toHaveCount(1)
        ->and($broadcasts->capturedCount())->toBe(1)
        ->and($broadcasts->deliveredCount())->toBe(1)
        ->and($broadcasts->droppedCount())->toBe(1);
});

it('passes assertDelivered when the event reached the page', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Delivered),
    ]);

    expect($broadcasts->assertDelivered('price.changed'))->toBe($broadcasts);
});

it('fails assertDelivered with the broadcasts that were actually sent', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Dropped),
    ]);

    expect(fn () => $broadcasts->assertDelivered('price.changed'))
        ->toThrow(
            ExpectationFailedException::class,
            'The expected [price.changed] broadcast was not delivered.',
        );

    expect(fn () => $broadcasts->assertDelivered('price.changed'))
        ->toThrow(ExpectationFailedException::class, 'price.changed on [auctions.1] (dropped)');
});

it('reports that nothing was broadcast when the log is empty', function (): void {
    expect(fn () => (new CapturedBroadcasts([]))->assertDelivered('price.changed'))
        ->toThrow(ExpectationFailedException::class, 'Broadcasts sent: none.');
});

it('filters assertions with a truth test over the captured broadcast', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Delivered, payload: ['price' => 2200]),
    ]);

    $broadcasts->assertDelivered(
        'price.changed',
        fn (CapturedBroadcast $broadcast): bool => $broadcast->payload['price'] === 2200,
    );

    expect(fn () => $broadcasts->assertDelivered(
        'price.changed',
        fn (CapturedBroadcast $broadcast): bool => $broadcast->payload['price'] === 9999,
    ))->toThrow(ExpectationFailedException::class);
});

it('counts deliveries when given an integer', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Delivered),
        delivery('price.changed', 'buyers.2', DeliveryOutcome::Delivered),
    ]);

    $broadcasts->assertDelivered('price.changed', 2);

    expect(fn () => $broadcasts->assertDeliveredTimes('price.changed', 1))
        ->toThrow(
            ExpectationFailedException::class,
            'The expected [price.changed] broadcast was delivered 2 times instead of 1 time.',
        );
});

it('asserts delivery on a specific channel', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'buyers.2', DeliveryOutcome::Delivered, ChannelVisibility::Private),
    ]);

    $broadcasts->assertDeliveredOn(new PrivateChannel('buyers.2'), 'price.changed');

    expect(fn () => $broadcasts->assertDeliveredOn('buyers.2', 'price.changed'))
        ->toThrow(ExpectationFailedException::class, 'was not delivered on [buyers.2]');
});

it('asserts an event was dropped', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Dropped),
    ]);

    $broadcasts->assertDropped('price.changed');

    expect(fn () => $broadcasts->assertNothingDropped())
        ->toThrow(ExpectationFailedException::class, 'Expected no dropped broadcasts');
});

it('asserts nothing was broadcast', function (): void {
    (new CapturedBroadcasts([]))->assertNothingBroadcast();

    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Delivered),
    ]);

    expect(fn () => $broadcasts->assertNothingBroadcast())
        ->toThrow(ExpectationFailedException::class, 'Expected no broadcasts');
});

it('asserts an event was not delivered', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Dropped),
    ]);

    $broadcasts->assertNotDelivered('price.changed');

    expect(fn () => $broadcasts->assertNotBroadcast('price.changed'))
        ->toThrow(ExpectationFailedException::class, 'The unexpected [price.changed] broadcast was sent.');
});

it('matches a class string against an event that renames itself with broadcastAs', function (): void {
    $broadcasts = new CapturedBroadcasts([
        delivery('price.changed', 'auctions.1', DeliveryOutcome::Delivered),
    ]);

    expect($broadcasts->assertDelivered(NamedPriceChanged::class))->toBe($broadcasts);
});

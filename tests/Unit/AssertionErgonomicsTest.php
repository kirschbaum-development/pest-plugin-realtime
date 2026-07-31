<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;

it('matches a payload by array subset', function (): void {
    [$broadcasting] = session(['delivered']);

    $broadcasting->emit('price.changed', 'auctions.1', ['price' => 2200, 'currency' => 'USD']);

    $broadcasting
        ->assertDelivered('price.changed', ['price' => 2200])
        ->assertNotDelivered('price.changed', ['price' => 9999]);
});

it('requires every key of an array subset to match', function (): void {
    [$broadcasting] = session(['delivered']);

    $broadcasting->emit('price.changed', 'auctions.1', ['price' => 2200, 'currency' => 'USD']);

    expect(fn () => $broadcasting->assertDelivered('price.changed', [
        'price' => 2200,
        'currency' => 'EUR',
    ]))->toThrow(ExpectationFailedException::class);
});

it('matches an array subset on a specific channel', function (): void {
    [$broadcasting] = session(['delivered']);

    $broadcasting->emit('price.changed', 'auctions.1', ['price' => 2200]);

    $broadcasting->assertDeliveredOn('auctions.1', 'price.changed', ['price' => 2200]);
});

it('matches an array subset when asserting a drop', function (): void {
    [$broadcasting] = session(['dropped']);

    $broadcasting->emit('price.changed', 'auctions.1', ['price' => 2200]);

    $broadcasting->assertDropped('price.changed', ['price' => 2200]);
});

it('asserts delivered events in relative order', function (): void {
    [$broadcasting] = session(['delivered', 'delivered', 'delivered']);

    $broadcasting
        ->emit('lot.opened', 'auctions.1')
        ->emit('price.changed', 'auctions.1')
        ->emit('lot.closed', 'auctions.1');

    $broadcasting->assertDeliveredInOrder(['lot.opened', 'lot.closed']);
});

it('fails when delivered events arrive out of the expected order', function (): void {
    [$broadcasting] = session(['delivered', 'delivered']);

    $broadcasting
        ->emit('lot.opened', 'auctions.1')
        ->emit('lot.closed', 'auctions.1');

    expect(fn () => $broadcasting->assertDeliveredInOrder(['lot.closed', 'lot.opened']))
        ->toThrow(ExpectationFailedException::class, 'lot.opened, lot.closed');
});

it('ignores events outside the expected order', function (): void {
    [$broadcasting] = session(['delivered', 'delivered', 'delivered']);

    $broadcasting
        ->emit('lot.opened', 'auctions.1')
        ->emit('noise', 'auctions.1')
        ->emit('lot.closed', 'auctions.1');

    $broadcasting->assertDeliveredInOrder(['lot.opened', 'lot.closed']);
});

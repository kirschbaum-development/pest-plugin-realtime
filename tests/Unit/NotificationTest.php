<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\Broadcasting;
use Pest\Realtime\CapturedBroadcast;
use Pest\Realtime\Tests\Fixtures\Post;
use PHPUnit\Framework\ExpectationFailedException;

const ORDER_SHIPPED = 'App\Notifications\OrderShipped';

const NOTIFICATION_EVENT = 'Illuminate\Notifications\Events\BroadcastNotificationCreated';

function notify(Broadcasting $broadcasting, string $type = ORDER_SHIPPED): void
{
    $broadcasting->emit(
        NOTIFICATION_EVENT,
        new PrivateChannel('Pest.Realtime.Tests.Fixtures.Post.1'),
        ['id' => 'c2a1', 'type' => $type, 'order' => 5],
    );
}

it('asserts a broadcast notification reached a notifiable', function (): void {
    $post = new Post();
    $post->id = 1;

    [$broadcasting] = session(['delivered']);

    notify($broadcasting);

    $broadcasting
        ->assertNotified($post, ORDER_SHIPPED)
        ->assertNotNotified($post, 'App\Notifications\OrderCancelled');
});

it('narrows a notification assertion with a truth test', function (): void {
    $post = new Post();
    $post->id = 1;

    [$broadcasting] = session(['delivered']);

    notify($broadcasting);

    $broadcasting->assertNotified(
        $post,
        ORDER_SHIPPED,
        fn (CapturedBroadcast $broadcast): bool => $broadcast->payload['order'] === 5,
    );

    expect(fn () => $broadcasting->assertNotified(
        $post,
        ORDER_SHIPPED,
        fn (CapturedBroadcast $broadcast): bool => $broadcast->payload['order'] === 9,
    ))->toThrow(ExpectationFailedException::class);
});

it('does not match a notification sent to another notifiable', function (): void {
    $other = new Post();
    $other->id = 2;

    [$broadcasting] = session(['delivered']);

    notify($broadcasting);

    $broadcasting->assertNotNotified($other, ORDER_SHIPPED);

    expect(fn () => $broadcasting->assertNotified($other, ORDER_SHIPPED))
        ->toThrow(ExpectationFailedException::class, 'private-Pest.Realtime.Tests.Fixtures.Post.2');
});

it('accepts an explicit channel for a custom notification route', function (): void {
    [$broadcasting] = session(['delivered']);

    notify($broadcasting);

    $broadcasting->assertNotified(
        new PrivateChannel('Pest.Realtime.Tests.Fixtures.Post.1'),
        ORDER_SHIPPED,
    );
});

it('reports the notifications it actually found', function (): void {
    $post = new Post();
    $post->id = 1;

    [$broadcasting] = session(['delivered']);

    notify($broadcasting, 'App\Notifications\OrderCancelled');

    expect(fn () => $broadcasting->assertNotified($post, ORDER_SHIPPED))
        ->toThrow(ExpectationFailedException::class, 'App\Notifications\OrderCancelled');
});

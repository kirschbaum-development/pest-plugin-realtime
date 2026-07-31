<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\Whisper;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * @param  array<array-key, mixed>  $payload
 * @return array<array-key, mixed>
 */
function whisperRecord(string $event = 'typing', array $payload = ['name' => 'Bo']): array
{
    return [
        'event' => $event,
        'channel' => 'private-buyers.2',
        'payload' => $payload,
        'connected' => true,
    ];
}

it('sends a client event into the page', function (): void {
    [$broadcasting, $executor] = session(['delivered']);

    $broadcasting->whisper('typing', new PrivateChannel('buyers.2'), ['name' => 'Ana']);

    expect($executor->scripts[2])->toContain('"buyers.2","client-typing",{"name":"Ana"},"private"')
        ->and($broadcasting->captured()->deliveredCount())->toBe(1);
});

it('reads the client events the page sent', function (): void {
    [$broadcasting] = session([[whisperRecord()]]);

    $whispers = $broadcasting->whispers();

    expect($whispers)->toHaveCount(1)
        ->and($whispers->first())->toBeInstanceOf(Whisper::class)
        ->and($whispers->first()?->event)->toBe('typing')
        ->and($whispers->first()?->channel)->toBe('private-buyers.2')
        ->and($whispers->first()?->payload)->toBe(['name' => 'Bo'])
        ->and($whispers->first()?->connected)->toBeTrue();
});

it('asserts a client event the page sent', function (): void {
    [$broadcasting] = session([[whisperRecord()], [whisperRecord()]]);

    $broadcasting
        ->assertWhispered('typing')
        ->assertNotWhispered('resize');
});

it('narrows a whisper assertion with a truth test', function (): void {
    [$broadcasting] = session([[whisperRecord()], [whisperRecord()]]);

    $broadcasting->assertWhispered(
        'typing',
        fn (Whisper $whisper): bool => $whisper->payload['name'] === 'Bo',
    );

    expect(fn () => $broadcasting->assertWhispered(
        'typing',
        fn (Whisper $whisper): bool => $whisper->payload['name'] === 'Ana',
    ))->toThrow(ExpectationFailedException::class);
});

it('fails with the client events the page actually sent', function (): void {
    [$broadcasting] = session([[]]);

    expect(fn () => $broadcasting->assertWhispered('typing'))
        ->toThrow(ExpectationFailedException::class, 'Client events sent: none.');
});

it('lists the client events it did find when an assertion fails', function (): void {
    [$broadcasting] = session([[whisperRecord()]]);

    expect(fn () => $broadcasting->assertWhispered('resize'))
        ->toThrow(ExpectationFailedException::class, 'typing on [private-buyers.2]');
});

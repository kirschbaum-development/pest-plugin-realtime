<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Pest\Realtime\Exceptions\RealtimeException;
use PHPUnit\Framework\ExpectationFailedException;

it('drives presence membership without recording deliveries', function (): void {
    [$broadcasting, $executor] = session(['ok', 'ok', 'ok']);

    $broadcasting
        ->here(new PresenceChannel('room.3'), [['id' => 1, 'name' => 'Ana']])
        ->joining(new PresenceChannel('room.3'), ['id' => 2, 'name' => 'Bo'])
        ->leaving(new PresenceChannel('room.3'), 2);

    expect($broadcasting->captured()->deliveries())->toHaveCount(0)
        ->and($executor->scripts[2])->toContain('"room.3"')
        ->and($executor->scripts[2])->toContain('"here"')
        ->and($executor->scripts[3])->toContain('"joined"')
        ->and($executor->scripts[3])->toContain('"user_id":2')
        ->and($executor->scripts[4])->toContain('"left"');
});

it('treats a bare presence channel name as a presence channel', function (): void {
    [$broadcasting, $executor] = session(['ok']);

    $broadcasting->joining('room.3', ['id' => 2, 'name' => 'Bo']);

    expect($executor->scripts[2])->toContain('"room.3","joined"');
});

it('refuses presence operations on a channel that is not a presence channel', function (): void {
    [$broadcasting] = session([]);

    expect(fn () => $broadcasting->joining(new PrivateChannel('buyers.2'), ['id' => 2]))
        ->toThrow(RealtimeException::class, 'is not a presence channel');
});

it('derives the member id from the member payload', function (): void {
    [$broadcasting, $executor] = session(['ok']);

    $broadcasting->joining(new PresenceChannel('room.3'), ['id' => 7, 'name' => 'Bo']);

    expect($executor->scripts[2])->toContain('"user_id":7')
        ->and($executor->scripts[2])->toContain('"user_info":{"id":7,"name":"Bo"}');
});

it('requires a member id when the payload carries none', function (): void {
    [$broadcasting] = session([]);

    expect(fn () => $broadcasting->joining(new PresenceChannel('room.3'), ['name' => 'Bo']))
        ->toThrow(RealtimeException::class, 'did not provide a member id');
});

it('fails when the page never joined the presence channel', function (): void {
    [$broadcasting] = session(['not_subscribed']);

    expect(fn () => $broadcasting->joining(new PresenceChannel('room.9'), ['id' => 2]))
        ->toThrow(RealtimeException::class, 'presence-room.9');
});

it('reads the member roster from the page keyed by member id', function (): void {
    [$broadcasting] = session([[1 => ['id' => 1, 'name' => 'Ana'], 2 => ['id' => 2, 'name' => 'Bo']]]);

    expect($broadcasting->members(new PresenceChannel('room.3')))
        ->toBe([1 => ['id' => 1, 'name' => 'Ana'], 2 => ['id' => 2, 'name' => 'Bo']]);
});

it('asserts who is present in a presence channel', function (): void {
    [$broadcasting] = session([
        [1 => ['id' => 1, 'name' => 'Ana']],
        [1 => ['id' => 1, 'name' => 'Ana']],
        [1 => ['id' => 1, 'name' => 'Ana']],
    ]);

    $broadcasting
        ->assertMemberCount(new PresenceChannel('room.3'), 1)
        ->assertMember(new PresenceChannel('room.3'), 1)
        ->assertNotMember(new PresenceChannel('room.3'), 2);
});

it('fails with the roster it actually found', function (): void {
    [$broadcasting] = session([[1 => ['id' => 1, 'name' => 'Ana']]]);

    expect(fn () => $broadcasting->assertMember(new PresenceChannel('room.3'), 9))
        ->toThrow(ExpectationFailedException::class, 'Members present: [1].');
});

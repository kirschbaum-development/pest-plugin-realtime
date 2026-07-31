# Laravel-Way Public API

Date: 2026-07-30

## Problem

The public API reads like a simulator control panel rather than a Laravel test helper:

1. Ceremony before value — `install()`, then `connect()`, then work.
2. Getters where Laravel has assertions — `droppedCount()`, `allDelivered()`, `status()` all
   forced through `expect()`, producing failure messages like
   `Failed asserting that 0 is identical to 1.`
3. Plugin vocabulary where Laravel already has words — `ChannelVisibility::Private` instead of
   `PrivateChannel`.

The package is pre-Packagist, so breaking changes are free.

## Target usage

```php
use Illuminate\Broadcasting\PrivateChannel;

use function Pest\Realtime\broadcasting;

it('recovers an event missed while disconnected', function (): void {
    $auction = Auction::query()->findOrFail(1);
    $page = visit("/auctions/{$auction->id}/live");

    $broadcasting = broadcasting($page)
        ->assertSubscribed('auctions.1')
        ->assertSubscribed(new PrivateChannel('buyers.2'));

    $auction->update(['lot_selling_price' => 2200]);

    $broadcasting->assertDelivered('lot.price.changed');
    $page->assertSee('$2,200.00');

    $broadcasting->disconnect();
    $auction->update(['lot_selling_price' => 3300]);
    $broadcasting->assertDropped('lot.price.changed');

    $broadcasting->fail()->reconnect();
    $page->assertSee('$3,300.00');
});
```

## Design

### 1. No ceremony

`install()` becomes lazy and idempotent — the first operation needing the browser runtime installs
it. `RealtimeSession` stops being `readonly` to hold that state.

`resources/echo-pusher.js` calls `pusher.disconnect()` at install, so post-install state is always
`disconnected`. Since the simulator owns connection state entirely after install, install now
transitions to `connected`. Disconnection becomes the thing a test opts into.

Timeouts default to `Pest\Browser\Playwright\Playwright::timeout()` rather than a private 5000ms
constant, so tuning Pest Browser tunes this plugin too.

### 2. Laravel channel vocabulary

`Support\Channels::parse()` accepts `Channel`, `PrivateChannel`, `PresenceChannel`,
`EncryptedPrivateChannel`, a bare name (`auctions.1`), or a wire string (`private-buyers.2`), and
returns `[name, ChannelVisibility]`.

Laravel's channel classes stringify to the wire format, so parsing the string covers every case
including `private-encrypted-` — it round-trips through `Driver::channelId()` unchanged.

`ChannelVisibility` is demoted to an internal detail and a return-value type.

### 3. One delivery log

The session records every push into the page — from `emit()`, `broadcast()`, or ambient capture —
as a `Delivery`. All assertions read that log, mirroring `EventFake`.

```
Delivery { CapturedBroadcast $broadcast, string $channel, ChannelVisibility $visibility, DeliveryOutcome $outcome }
```

`CapturedBroadcasts` holds a `list<Delivery>` and provides the assertions plus the existing
counters. It serves both as the session's log view and as `capture()`'s scoped return value.

Assertions: `assertBroadcast`, `assertNotBroadcast`, `assertNothingBroadcast`, `assertDelivered`,
`assertNotDelivered`, `assertDeliveredTimes`, `assertDeliveredOn`, `assertDropped`,
`assertNothingDropped`, `assertNotSubscribed`.

Failure messages list what actually happened, in Laravel's style:

```
The expected [lot.price.changed] broadcast was not dropped.
Broadcasts sent: lot.price.changed on [auctions.1] (delivered), order.updated on [private-buyers.2] (not_subscribed)
```

Truth-test callbacks receive `CapturedBroadcast` (channels, event, payload, connection, socket).
They do not receive the originating event object: Laravel hands the broadcaster only the wire
name and payload, so the instance is not available on the capture path. Matching a class-string
against an event that defines `broadcastAs()` is handled best-effort via
`ReflectionClass::newInstanceWithoutConstructor()`, falling back to plain string comparison.

### 4. Split `emit()`

The `string|ShouldBroadcast` overload exists only to support a runtime `broadcastEventOverrides`
exception. Split it and the exception disappears:

- `broadcast(ShouldBroadcast $event): self` — mirrors Laravel's `broadcast()` helper.
- `emit(string $event, Channel|string $channel, array $payload = []): self` — synthetic and
  malformed-payload tests.

Both return `$this`; outcomes land in the delivery log.

### 5. Ambient capture

`CapturingBroadcaster` flushes each broadcast to the browser as it arrives instead of after a
callback returns, so the closure boundary becomes optional. Capture starts when the session is
constructed and is restored by `__destruct()` (the session is a test-local variable, freed by
refcounting at test end) plus an explicit `stopCapturing()`.

`BroadcastCapture` becomes:

```php
public function start(Closure $onBroadcast): void;
public function stop(): void;
public function capturing(): bool;
```

`capture(Closure)` records a log offset, runs the callback, and returns the scoped
`CapturedBroadcasts`. Nesting is no longer a special case; the `WeakMap` guard moves to `start()`
to catch two sessions sharing one application.

Two documented footguns become handled behaviour:

- `queue.default` is forced to `sync` during capture, so `ShouldBroadcast` events run inline.
  Restored on stop. Skipped when the container has no queue config.
- An active `EventFake` is detected and throws a clear exception instead of silently capturing
  nothing.

### 6. Naming

| Before | After |
|---|---|
| `RealtimeSession` | `Broadcasting` |
| `captureBroadcasts()` | `capture()` |
| `BroadcastBatch` | `CapturedBroadcasts` |
| `EventDelivery` | `DeliveryOutcome` |
| `BroadcastDelivery` | `Delivery` |
| `RealtimeSimulationException` | `RealtimeException` |

### 7. Smaller items

- `broadcasts()` / `deliveries()` return `Illuminate\Support\Collection`.
- `illuminate/support` declared explicitly instead of relied on transitively.
- `Realtime::driver()` sets a default driver once in `tests/Pest.php`. No `extend()` manager —
  YAGNI for a single driver.
- `expect()->extend()`: `toBeConnected`, `toBeDisconnected`, `toHaveDelivered`, `toHaveDropped`.
- `waitForSubscription()` is dropped; it was a verbatim alias of `assertSubscribed()`.
- `socketId()` is exposed and honoured during replay: a captured broadcast whose `socket` matches
  the page's simulated socket id is skipped with outcome `Excluded`, making `toOthers()` testable.

## Deliberately not done

- **Page proxying.** Having `Broadcasting::__call()` forward to the page would allow one unbroken
  chain, and Pest Browser already does this (`AwaitableWebpage` → `Webpage`). Deferred until the
  rest settles: it adds `@mixin` complexity across three page types for no new capability.
- **Recording the event instance during capture.** Would require decorating `BroadcastManager` and
  coupling to Laravel internals across three major versions.

## Testing

Unit tests drive each unit through `FakeScriptExecutor` and `LaravelBroadcastHarness`, both of
which already exist. The browser integration test in `tests/Browser/` is updated to the new API and
remains the end-to-end check against real Laravel Echo and pusher-js.

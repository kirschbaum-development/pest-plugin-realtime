# Pest Plugin Realtime

[![Tests](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/tests.yml/badge.svg)](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/static.yml/badge.svg)](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/static.yml)

Deterministically test realtime browser behavior, dropped events, and connection recovery with [Pest](https://pestphp.com).

The first driver targets Laravel Echo with its Pusher-compatible connector, including Reverb, Pusher, and Ably's Pusher protocol. It operates at the existing Echo subscription boundary, so no realtime server is required during browser tests.

The integration is tested in a real browser against Laravel Echo 2.x and pusher-js 8.x. The package does not install those JavaScript libraries in consuming applications; it uses the application's existing client.

## Requirements

- PHP 8.3+
- Pest 4 or 5
- Pest Browser 4 or 5
- A page using Laravel Echo's Pusher-compatible connector

## Installation

Until the package is registered with Packagist, add its public GitHub repository to Composer once:

```bash
composer config repositories.pest-plugin-realtime vcs https://github.com/kirschbaum-development/pest-plugin-realtime
```

Then install the tagged release:

```bash
composer require kirschbaum-development/pest-plugin-realtime --dev
```

Your browser-test frontend must create its normal Echo subscriptions. It can point to a closed local port because the simulator stops the real client after the page loads.

```dotenv
VITE_BROADCAST_CONNECTION=reverb
VITE_REVERB_APP_KEY=browser-tests
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=65535
VITE_REVERB_SCHEME=http
```

Backend broadcasting can remain disabled. The session temporarily replaces Laravel's configured broadcast connections and restores them afterward.

## Usage

```php
use App\Models\Auction;
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

There is no setup step. The browser runtime installs itself on the first realtime call, the simulated client starts connected, and inside a booted Laravel application the session captures the app's broadcasts and replays them into the page as they happen.

Model observers, event listeners, `broadcastWhen()`, `broadcastOn()`, `broadcastAs()`, `broadcastWith()`, and explicitly selected broadcast connections all run under Laravel's normal dispatcher. The plugin captures the final calls Laravel would make to its broadcaster.

```text
test callback
    │
    ▼
application code ──► observer/listener ──► Laravel broadcast event
                                              │
                                              ▼
                                    temporary capture driver
                                              │ final channels,
                                              │ name, payload
                                              ▼
                                Echo simulator ──► page listener
```

`ShouldBroadcast` events are run inline: the queue connection is switched to `sync` while capturing, and restored afterward. Broadcast jobs handled later by a separate queue worker run in another process and stay outside this in-memory capture scope.

Do not wrap the application event under test in `Event::fake()`. Laravel cannot run observers or broadcast listeners for a suppressed event, so the session refuses to start and tells you why.

### Broadcasts from the page's own requests

Pest Browser serves page requests in a separate Fiber. Replaying a broadcast from inside one would re-enter the browser while the page is still waiting for its response, so those broadcasts are held and replayed the next time the test reads the session:

```php
$page->click('Place bid');

$broadcasting->assertDelivered('lot.price.changed'); // flushes, then asserts
$page->assertSee('$2,200.00');
```

Every realtime read flushes first, so assertions, `channels()`, `status()`, and `capture()` all pick them up. When a page assertion is the next thing that needs them, flush explicitly:

```php
$page->click('Place bid');

$broadcasting->flush();

$page->assertSee('$2,200.00');
```

### Assertions

```php
$broadcasting->assertDelivered('lot.price.changed');
$broadcasting->assertDelivered(LotPriceChanged::class);
$broadcasting->assertDelivered('lot.price.changed', 2);
$broadcasting->assertDeliveredTimes('lot.price.changed', 2);
$broadcasting->assertDeliveredOn(new PrivateChannel('buyers.2'), 'lot.price.changed');
$broadcasting->assertNotDelivered('lot.price.changed');
$broadcasting->assertDropped('lot.price.changed');
$broadcasting->assertNothingDropped();
$broadcasting->assertBroadcast('lot.price.changed');
$broadcasting->assertNotBroadcast('lot.price.changed');
$broadcasting->assertNothingBroadcast();
$broadcasting->assertConnected();
$broadcasting->assertDisconnected();
$broadcasting->assertSubscribed('auctions.1');
$broadcasting->assertNotSubscribed(new PrivateChannel('admins.1'));
$broadcasting->assertDeliveredInOrder(['lot.opened', 'lot.price.changed', 'lot.closed']);
$broadcasting->assertDeliveredVia('reverb', 'lot.price.changed');
$broadcasting->assertNotDeliveredVia('pusher', 'lot.price.changed');
```

Every assertion returns the session, so they chain. Failures name the event and list what actually happened:

```text
The expected [lot.price.changed] broadcast was not dropped.
Broadcasts sent: lot.price.changed on [auctions.1] (delivered), order.updated on [private-buyers.2] (not_subscribed).
```

A class string matches the wire name `broadcastAs()` would have produced, so both `LotPriceChanged::class` and `'lot.price.changed'` work.

A closure narrows the match. It receives the `CapturedBroadcast`, since Laravel hands a broadcaster only the wire name and payload:

```php
use Pest\Realtime\CapturedBroadcast;

$broadcasting->assertDelivered(
    'lot.price.changed',
    fn (CapturedBroadcast $broadcast): bool => $broadcast->payload['lot_selling_price'] === 2200,
);
```

An array is the shorthand for the common case, matching the payload as a subset:

```php
$broadcasting->assertDelivered('lot.price.changed', ['lot_selling_price' => 2200]);
```

`assertDeliveredInOrder()` checks relative order, so unrelated broadcasts may arrive in between.

### Channels

Channels accept Laravel's own vocabulary, an Eloquent model, a bare name, or the wire identifier you see in devtools:

```php
$broadcasting->assertSubscribed('auctions.1');
$broadcasting->assertSubscribed(new PrivateChannel('buyers.2'));
$broadcasting->assertSubscribed(new PresenceChannel('room.3'));
$broadcasting->assertSubscribed(new EncryptedPrivateChannel('vault.5'));
$broadcasting->assertSubscribed('private-buyers.2');
$broadcasting->assertSubscribed($post);  // private-App.Models.Post.1
```

A model resolves to the private channel Laravel's own conventions produce, which is what `BroadcastsEvents` and broadcast notifications use. That makes model broadcasting read directly:

```php
$post->update(['title' => 'Revised']);

$broadcasting->assertDeliveredOn($post, 'PostUpdated');
```

`assertSubscribed()` waits for late subscriptions. The default timeout is Pest Browser's own assertion timeout, and can be overridden per call with `assertSubscribed(..., timeoutMilliseconds: 10_000)`.

Encrypted private channels are delivered to directly rather than through pusher-js's decryption path, since the simulator has no shared secret to encrypt with. The page's listener runs exactly as it would for a decrypted frame.

### Scoped capture

When you want the broadcasts from one specific action rather than the whole test:

```php
$broadcasts = $broadcasting->capture(
    fn () => $auction->update(['lot_selling_price' => 2200]),
);

$broadcasts->assertDelivered('lot.price.changed')->assertNothingDropped();

$broadcasts->capturedCount();
$broadcasts->deliveredCount();
$broadcasts->droppedCount();
$broadcasts->notSubscribedCount();
$broadcasts->excludedCount();
$broadcasts->allDelivered();

$broadcasts->broadcasts();  // Collection<CapturedBroadcast>
$broadcasts->deliveries();  // Collection<Delivery>
```

`$broadcasting->captured()` returns the same object for everything the session has sent.

Each `CapturedBroadcast` exposes its wire `channels`, `event`, and `payload`, along with the selected Laravel `connection` and any `socket` exclusion supplied by `toOthers()`. Each `Delivery` links that capture to a normalized channel, its visibility, and an outcome:

- `Delivered`: the page registered the channel and its simulated connection was connected.
- `Dropped`: the page registered the channel but its simulated connection was not connected.
- `NotSubscribed`: this page did not register the channel. This is normal when an event broadcasts to several page types; replay continues to the remaining channels.
- `Excluded`: the broadcast excluded this page's socket through `toOthers()`.

### Emitting directly

`broadcast()` pushes a Laravel event, deriving channels, name, and payload from `broadcastOn()`, `broadcastAs()`, and `broadcastWith()`:

```php
$broadcasting->broadcast(new LotPriceChanged($auction));
```

`emit()` pushes a raw event at the wire boundary, for synthetic and malformed-payload tests:

```php
$broadcasting->emit('lot.price.changed', 'auctions.1', ['lot_selling_price' => 2200]);
```

Neither dispatches through Laravel, so neither evaluates `broadcastWhen()`. Let the application dispatch the event when its conditional broadcasting behavior is part of the test.

Anonymous events need nothing special. `Broadcast::on(...)->as(...)->with(...)->send()` is an ordinary `ShouldBroadcast` event, so capture records it like any other.

### Presence channels

Membership is driven from the test, so `here()`, `joining()`, and `leaving()` in the page run against a roster you control:

```php
$broadcasting
    ->here(new PresenceChannel('room.3'), [
        ['id' => 1, 'name' => 'Ana'],
    ])
    ->joining(new PresenceChannel('room.3'), ['id' => 2, 'name' => 'Bo'])
    ->leaving(new PresenceChannel('room.3'), 2);

$broadcasting->assertMemberCount(new PresenceChannel('room.3'), 1);
$broadcasting->assertMember(new PresenceChannel('room.3'), 1);
$broadcasting->assertNotMember(new PresenceChannel('room.3'), 2);

$broadcasting->members(new PresenceChannel('room.3')); // [1 => ['id' => 1, 'name' => 'Ana']]
```

The member array is the one a presence channel authorization callback returns, and its `id` becomes the member id unless you pass one. A bare name is treated as a presence channel here, so `here('room.3', ...)` also works.

### Client events

`whisper()` pushes a client event into the page, as another client's whisper would:

```php
$broadcasting->whisper('typing', new PrivateChannel('chat.1'), ['name' => 'Ana']);
```

Whispers the page itself sends are recorded, so a typing indicator can be asserted from the other side:

```php
$page->fill('#message', 'Hello');

$broadcasting->assertWhispered('typing');
$broadcasting->assertNotWhispered('resize');

$broadcasting->whispers(); // Collection<Whisper>
```

Each `Whisper` exposes its `event`, wire `channel`, `payload`, and whether the simulated connection was `connected` at the time.

### Notifications

Broadcast notifications travel the same capture path, and assertions take the notifiable directly:

```php
$user->notify(new OrderShipped($order));

$broadcasting->assertNotified($user, OrderShipped::class);
$broadcasting->assertNotNotified($user, OrderCancelled::class);
```

A notifiable resolves to its private model channel. Pass a channel explicitly when the notifiable overrides `receivesBroadcastNotificationsOn()`. As with `Event::fake()`, do not wrap the notification under test in `Notification::fake()`; the session refuses to start and tells you why.

### Failed subscriptions

Channel authorization runs against your application's own endpoint and is out of the simulator's scope, but the client-side outcome of a refusal is not:

```php
$broadcasting->failSubscription(new PrivateChannel('orders.1'), status: 403);

$page->assertSee('You no longer have access to this order.');
```

That fires Echo's `error()` callback with the same shape Pusher produces for a denied authorization.

### Connection controls

```php
$broadcasting->connect();
$broadcasting->disconnect();
$broadcasting->fail();
$broadcasting->unavailable();
$broadcasting->reconnect(); // connecting, then connected
$broadcasting->transitionTo(ConnectionStatus::Unavailable);
$broadcasting->status();
```

The Echo/Pusher driver models Pusher's `initialized`, `connecting`, `connected`, `unavailable`, `failed`, and `disconnected` states. Every transition emits both Pusher's `state_change` event and the state-specific event, matching the real client's observable behavior. Echo normalizes Pusher's `unavailable` state to `failed` through `Echo.connectionStatus()`.

### Testing `toOthers()`

The simulated client's socket id is available, so a broadcast that excludes it can be exercised end to end:

```php
Broadcast::socket($broadcasting->socketId());

$auction->update(['lot_selling_price' => 2200]);

$broadcasting->assertNotDelivered('lot.price.changed');
```

Capture runs in the test's PHP process, so `$socket` is only set when the test sets it. Requests the browser itself makes carry their own `X-Socket-ID` and are handled in another process.

### Defaults

Set once in `tests/Pest.php`:

```php
use Pest\Realtime\Realtime;

Realtime::driver(new YourRealtimeDriver());
Realtime::timeout(10_000);
```

## What it tests

```text
Pest test ──► simulator ──► Echo/Pusher channel ──► application listener
```

- Public, private, presence, and encrypted private subscriptions
- Exact event names and payloads
- Initialized, connecting, connected, unavailable, failed, and disconnected states
- Events delivered while connected
- Events dropped during an outage
- Application resync behavior after recovery
- Presence rosters and join/leave churn
- Client events in both directions
- Broadcast notifications
- The client-side outcome of a refused subscription

## Boundary and limitations

This package is a realtime client simulator, not a WebSocket protocol emulator. It installs after navigation and deliberately centralizes version-sensitive access to Echo/Pusher's active client and channels.

It does not test:

- WebSocket handshakes or frames
- Reverb/Pusher server behavior
- TLS, proxy, or load-balancer configuration
- Channel authorization endpoints, only what the client does when one refuses

One session captures one application at a time. Starting a second session against the same Laravel application throws; call `stopCapturing()` on the first, or let it go out of scope. Multi-client scenarios that need two pages sharing one capture are not supported yet.

An event implementing `ShouldDispatchAfterCommit` is not dispatched until its transaction commits, which a transactional test never does. When nothing was captured and a transaction is open, the failure message says so.

Keep backend tests for channel authorization, event payload contracts, and broadcast failure tolerance. A future driver can use Playwright WebSocket routing when Pest Browser exposes that browser-context API publicly.

## Custom drivers

Implement `Pest\Realtime\Contracts\Driver` and pass it to `broadcasting()`, or register it as the default:

```php
$broadcasting = broadcasting($page, new YourRealtimeDriver());
```

`Driver` gained `presenceScript()`, `membersScript()`, `clientEventsScript()`, and `subscriptionErrorScript()` in 0.7.0, and `BroadcastCapture` gained `drainPending()` and `hint()`. Drivers and capture implementations written against 0.6 need those methods added.

## License

Pest Plugin Realtime is open-source software licensed under the [MIT license](LICENSE.md).

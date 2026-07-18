# Pest Plugin Realtime

[![Tests](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/tests.yml/badge.svg)](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/tests.yml)
[![Static Analysis](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/static.yml/badge.svg)](https://github.com/kirschbaum-development/pest-plugin-realtime/actions/workflows/static.yml)

Deterministically test realtime browser behavior, dropped events, and connection recovery with [Pest](https://pestphp.com).

The first driver targets Laravel Echo with its Pusher-compatible connector, including Reverb, Pusher, and Ably's Pusher protocol. It operates at the existing Echo subscription boundary, so no realtime server is required during browser tests.

## Requirements

- PHP 8.3+
- Pest 4
- Pest Browser 4
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

Backend broadcasting can remain disabled. When `captureBroadcasts()` runs, the plugin temporarily replaces Laravel's configured broadcast connections and restores them afterward.

## Usage

```php
use App\Models\Auction;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;

use function Pest\Realtime\broadcasting;

it('recovers an event missed while disconnected', function (): void {
    $auction = Auction::query()->findOrFail(1);
    $page = visit("/auctions/{$auction->id}/live");

    $broadcasting = broadcasting($page)->install()
        ->assertSubscribed('auctions.1')
        ->assertSubscribed('buyers.2', ChannelVisibility::Private)
        ->connect();

    $broadcasts = $broadcasting->captureBroadcasts(
        fn () => $auction->update(['lot_selling_price' => 2200]),
    );

    expect($broadcasts->allDelivered())->toBeTrue();

    $page->waitForText('$2,200.00');

    $broadcasting->disconnect();

    $broadcasts = $broadcasting->captureBroadcasts(
        fn () => $auction->update(['lot_selling_price' => 3300]),
    );

    expect($broadcasts->droppedCount())->toBe(1);

    $broadcasting
        ->transitionTo(ConnectionStatus::Failed)
        ->reconnect();

    $page->waitForText('$3,300.00');
});
```

`captureBroadcasts()` runs the callback through the real application path. Model observers, event listeners, `broadcastWhen()`, `broadcastOn()`, `broadcastAs()`, `broadcastWith()`, and explicitly selected broadcast connections all run under Laravel's normal dispatcher. The plugin captures the final calls Laravel would make to its broadcaster and replays them through the simulated browser connection in order.

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

The capture scope always restores the original broadcasting configuration and resolved drivers, including when the callback throws. Its result reports the number of Laravel broadcast calls and their browser deliveries:

```php
$broadcasts->capturedCount();
$broadcasts->deliveredCount();
$broadcasts->droppedCount();
$broadcasts->allDelivered();
```

Do not wrap the application event under test in `Event::fake()`: Laravel cannot run observers or broadcast listeners for an event that the test has suppressed.

### Direct event emission

When passed a Laravel broadcast event object, `emit()` derives every wire-level value from the event:

- `broadcastOn()` supplies all channels and their public, private, or presence visibility.
- `broadcastAs()` supplies the event name, falling back to the event class.
- `broadcastWith()` supplies the payload, falling back to the event's public properties.

The low-level form remains available for synthetic events and malformed-payload tests. The event name is the first argument:

```php
$broadcasting->emit(
    event: LotSellingPriceUpdatedEvent::class,
    channel: 'auctions.1',
    payload: ['lot_selling_price' => 2200],
);
```

### Connection controls

```php
$broadcasting->connect();
$broadcasting->disconnect();
$broadcasting->fail();
$broadcasting->reconnect(); // reconnecting, then connected
$broadcasting->transitionTo(ConnectionStatus::Reconnecting);
$broadcasting->status();
```

### Channel visibility

```php
$broadcasting->assertSubscribed('news');
$broadcasting->assertSubscribed('users.1', ChannelVisibility::Private);
$broadcasting->assertSubscribed('rooms.1', ChannelVisibility::Presence);
```

## What it tests

```text
Pest test ──► simulator ──► Echo/Pusher channel ──► application listener
```

- Public, private, and presence subscriptions
- Exact event names and payloads
- Connected, disconnected, failed, and reconnecting states
- Events delivered while connected
- Events dropped during an outage
- Application resync behavior after recovery

## Boundary and limitations

This package is a realtime client simulator, not a WebSocket protocol emulator. It installs after navigation and deliberately centralizes version-sensitive access to Echo/Pusher's active client and channels.

It does not test:

- WebSocket handshakes or frames
- Reverb/Pusher server behavior
- TLS, proxy, or load-balancer configuration
- Channel authorization endpoints

`captureBroadcasts()` captures work dispatched in the test's PHP process. Broadcast jobs handled later by a separate queue worker and application requests triggered inside the browser run in other processes, so they are outside this in-memory capture scope. Use a synchronous queue for queued broadcasts you want to capture, or trigger and capture the underlying application action directly in the test.

Keep backend tests for channel authorization, event payload contracts, and broadcast failure tolerance. A future driver can use Playwright WebSocket routing when Pest Browser exposes that browser-context API publicly.

## Custom drivers

Implement `Pest\Realtime\Contracts\Driver` and pass it to `broadcasting()`:

```php
$broadcasting = broadcasting($page, new YourRealtimeDriver())->install();
```

## License

Pest Plugin Realtime is open-source software licensed under the [MIT license](LICENSE.md).

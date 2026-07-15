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

Backend broadcasting can remain disabled or faked.

## Usage

```php
use App\Events\LotSellingPriceUpdatedEvent;
use Pest\Realtime\ChannelVisibility;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\EventDelivery;

use function Pest\Realtime\realtime;

it('recovers an event missed while disconnected', function (): void {
    $page = visit('/auctions/1/live');

    $realtime = realtime($page)->install()
        ->assertSubscribed('auctions.1')
        ->assertSubscribed('buyers.2', ChannelVisibility::Private)
        ->connect();

    expect($realtime->emit(
        channel: 'auctions.1',
        event: LotSellingPriceUpdatedEvent::class,
        payload: ['lot_selling_price' => 2200],
    ))->toBe(EventDelivery::Delivered);

    $page->waitForText('$2,200.00');

    $realtime->disconnect();

    expect($realtime->emit(
        channel: 'auctions.1',
        event: LotSellingPriceUpdatedEvent::class,
        payload: ['lot_selling_price' => 3300],
    ))->toBe(EventDelivery::Dropped);

    // Update the application's authoritative database state here.

    $realtime
        ->transitionTo(ConnectionStatus::Failed)
        ->reconnect();

    $page->waitForText('$3,300.00');
});
```

### Connection controls

```php
$realtime->connect();
$realtime->disconnect();
$realtime->fail();
$realtime->reconnect(); // reconnecting, then connected
$realtime->transitionTo(ConnectionStatus::Reconnecting);
$realtime->status();
```

### Channel visibility

```php
$realtime->assertSubscribed('news');
$realtime->assertSubscribed('users.1', ChannelVisibility::Private);
$realtime->assertSubscribed('rooms.1', ChannelVisibility::Presence);
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
- Backend broadcast dispatch

Keep backend tests for channel authorization, event payload contracts, and broadcast failure tolerance. A future driver can use Playwright WebSocket routing when Pest Browser exposes that browser-context API publicly.

## Custom drivers

Implement `Pest\Realtime\Contracts\Driver` and pass it to `realtime()`:

```php
$realtime = realtime($page, new YourRealtimeDriver())->install();
```

## License

Pest Plugin Realtime is open-source software licensed under the [MIT license](LICENSE.md).

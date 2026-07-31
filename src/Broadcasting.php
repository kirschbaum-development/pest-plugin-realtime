<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Closure;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Contracts\ScriptExecutor;
use Pest\Realtime\Exceptions\RealtimeException;
use Pest\Realtime\Support\Channels;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Stringable;
use WeakReference;

/**
 * Drives the page's realtime client and records everything it receives.
 */
final class Broadcasting
{
    private const int POLL_INTERVAL_MICROSECONDS = 50_000;

    private bool $installed = false;

    /** @var list<Delivery> */
    private array $deliveries = [];

    private ?string $socketId = null;

    public function __construct(
        private readonly ScriptExecutor $executor,
        private readonly Driver $driver,
        private readonly ?BroadcastCapture $broadcastCapture = null,
    ) {
        $session = WeakReference::create($this);

        $this->broadcastCapture?->start(static function (CapturedBroadcast $broadcast) use ($session): void {
            $broadcasting = $session->get();

            if ($broadcasting instanceof self) {
                $broadcasting->replay($broadcast);
            }
        });
    }

    public function __destruct()
    {
        $this->stopCapturing();
    }

    /**
     * Restores the application's broadcasting configuration.
     *
     * Runs automatically when the session goes out of scope at the end of a test.
     */
    public function stopCapturing(): self
    {
        $this->broadcastCapture?->stop();

        return $this;
    }

    /**
     * Installs the browser runtime and connects the simulated client.
     *
     * Called automatically by the first operation that needs the page, so tests
     * rarely call it directly. Idempotent.
     */
    public function install(?int $timeoutMilliseconds = null): self
    {
        if ($this->installed) {
            return $this;
        }

        $timeoutMilliseconds ??= Realtime::timeout();
        $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1_000);

        do {
            $result = $this->executor->evaluate($this->driver->installScript());

            if ($result !== null) {
                $this->parseChannels($result, 'install');
                $this->installed = true;

                // The runtime stops the real client, leaving it disconnected.
                // Connected is the state tests almost always want to start from.
                return $this->transitionTo(ConnectionStatus::Connected);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw RealtimeException::clientNotReady($timeoutMilliseconds);
    }

    /**
     * The wire identifiers the page is currently subscribed to.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->parseChannels(
            $this->flush()->install()->executor->evaluate($this->driver->channelsScript()),
            'channels',
        );
    }

    /**
     * Replays broadcasts the application made while handling a browser request.
     *
     * Pest Browser serves page requests in another Fiber, where pushing into the
     * page would re-enter the browser mid-request, so those broadcasts are held
     * until the test asks for them. Every realtime read flushes first; call this
     * directly when a page assertion, rather than a realtime one, comes next.
     */
    public function flush(): self
    {
        foreach ($this->broadcastCapture?->drainPending() ?? [] as $broadcast) {
            $this->replay($broadcast);
        }

        return $this;
    }

    /**
     * Asserts the page subscribed to the channel, waiting for late subscriptions.
     */
    public function assertSubscribed(
        Channel|HasBroadcastChannel|string $channel,
        ?int $timeoutMilliseconds = null,
    ): self {
        [$name, $visibility] = Channels::parse($channel);
        $channelId = $this->driver->channelId($name, $visibility);

        $timeoutMilliseconds ??= Realtime::timeout();
        $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1_000);
        $channels = [];

        do {
            $channels = $this->channels();

            if (in_array($channelId, $channels, true)) {
                Assert::assertContains($channelId, $channels);

                return $this;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        Assert::assertContains(
            $channelId,
            $channels,
            sprintf('Expected the page to be subscribed to realtime channel [%s].', $channelId),
        );

        return $this;
    }

    public function assertNotSubscribed(Channel|HasBroadcastChannel|string $channel): self
    {
        [$name, $visibility] = Channels::parse($channel);
        $channelId = $this->driver->channelId($name, $visibility);

        Assert::assertNotContains(
            $channelId,
            $this->channels(),
            sprintf('Expected the page not to be subscribed to realtime channel [%s].', $channelId),
        );

        return $this;
    }

    /**
     * Seeds a presence channel's roster, firing Echo's `here()` callback.
     *
     * @param  list<array<array-key, mixed>>  $members
     */
    public function here(Channel|HasBroadcastChannel|string $channel, array $members): self
    {
        $ids = [];
        $hash = new stdClass();

        foreach ($members as $member) {
            $id = (string) $this->memberId($member);
            $ids[] = $id;
            $hash->{$id} = $member;
        }

        return $this->presence($channel, PresenceEvent::Here, [
            'presence' => ['ids' => $ids, 'hash' => $hash, 'count' => count($ids)],
        ]);
    }

    /**
     * Adds a member, firing Echo's `joining()` callback.
     *
     * The id defaults to the member data's `id`, matching the array a presence
     * channel authorization callback returns.
     *
     * @param  array<array-key, mixed>  $member
     */
    public function joining(
        Channel|HasBroadcastChannel|string $channel,
        array $member,
        string|int|null $id = null,
    ): self {
        return $this->presence($channel, PresenceEvent::Joined, [
            'user_id' => $id ?? $this->memberId($member),
            'user_info' => $member,
        ]);
    }

    /**
     * Removes a member, firing Echo's `leaving()` callback.
     */
    public function leaving(Channel|HasBroadcastChannel|string $channel, string|int $id): self
    {
        return $this->presence($channel, PresenceEvent::Left, ['user_id' => $id]);
    }

    /**
     * The presence channel's roster, keyed by member id.
     *
     * @return array<array-key, mixed>
     */
    public function members(Channel|HasBroadcastChannel|string $channel): array
    {
        $name = $this->presenceChannel($channel);

        $result = $this->flush()->install()->executor->evaluate(
            $this->driver->membersScript($name),
        );

        if ($result === 'not_subscribed') {
            throw RealtimeException::presenceChannelNotSubscribed(
                Channels::toWire($name, ChannelVisibility::Presence),
            );
        }

        if (! is_array($result)) {
            throw RealtimeException::unexpectedResult('members', $result);
        }

        return $result;
    }

    public function assertMemberCount(Channel|HasBroadcastChannel|string $channel, int $count): self
    {
        $members = $this->members($channel);

        Assert::assertCount(
            $count,
            $members,
            sprintf(
                'Expected [%s] to have %d %s, found %d. %s',
                $this->presenceWire($channel),
                $count,
                Str::plural('member', $count),
                count($members),
                $this->memberSummary($members),
            ),
        );

        return $this;
    }

    public function assertMember(Channel|HasBroadcastChannel|string $channel, string|int $id): self
    {
        $members = $this->members($channel);

        Assert::assertTrue(
            $this->hasMember($members, $id),
            sprintf(
                'Expected [%s] to have member [%s]. %s',
                $this->presenceWire($channel),
                $id,
                $this->memberSummary($members),
            ),
        );

        return $this;
    }

    public function assertNotMember(Channel|HasBroadcastChannel|string $channel, string|int $id): self
    {
        $members = $this->members($channel);

        Assert::assertFalse(
            $this->hasMember($members, $id),
            sprintf(
                'Expected [%s] not to have member [%s]. %s',
                $this->presenceWire($channel),
                $id,
                $this->memberSummary($members),
            ),
        );

        return $this;
    }

    public function status(): ConnectionStatus
    {
        $result = $this->flush()->install()->executor->evaluate($this->driver->statusScript());

        if (! is_string($result) || ConnectionStatus::tryFrom($result) === null) {
            throw RealtimeException::unexpectedResult('status', $result);
        }

        return ConnectionStatus::from($result);
    }

    /**
     * The socket id the simulated client reports to the application.
     *
     * Pass it to `Broadcast::socket()` or an event's `$socket` property to
     * exercise `toOthers()` exclusion.
     */
    public function socketId(): string
    {
        if ($this->socketId !== null) {
            return $this->socketId;
        }

        $result = $this->install()->executor->evaluate($this->driver->socketIdScript());

        if (! is_string($result)) {
            throw RealtimeException::unexpectedResult('socketId', $result);
        }

        return $this->socketId = $result;
    }

    public function assertConnected(): self
    {
        return $this->assertStatus(ConnectionStatus::Connected);
    }

    public function assertDisconnected(): self
    {
        return $this->assertStatus(ConnectionStatus::Disconnected);
    }

    public function assertStatus(ConnectionStatus $status): self
    {
        $actual = $this->status();

        Assert::assertSame(
            $status,
            $actual,
            sprintf(
                'Expected the realtime connection to be [%s] but it was [%s].',
                $status->value,
                $actual->value,
            ),
        );

        return $this;
    }

    public function transitionTo(ConnectionStatus $status): self
    {
        $result = $this->executor->evaluate($this->driver->transitionScript($status));

        if ($result !== $status->value) {
            throw RealtimeException::unexpectedResult('transition', $result);
        }

        return $this;
    }

    public function connect(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Connected);
    }

    public function disconnect(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Disconnected);
    }

    public function fail(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Failed);
    }

    public function unavailable(): self
    {
        return $this->install()->transitionTo(ConnectionStatus::Unavailable);
    }

    public function reconnect(): self
    {
        return $this->install()
            ->transitionTo(ConnectionStatus::Connecting)
            ->transitionTo(ConnectionStatus::Connected);
    }

    /**
     * Pushes a Laravel broadcast event into the page.
     *
     * Channels, name, and payload come from `broadcastOn()`, `broadcastAs()`,
     * and `broadcastWith()`. The event is not dispatched through Laravel, so
     * `broadcastWhen()` is not evaluated — let the application dispatch it and
     * let capture do the work when that matters.
     */
    public function broadcast(ShouldBroadcast $event): self
    {
        $channels = $this->broadcastChannels($event, $event->broadcastOn());

        if ($channels === []) {
            throw RealtimeException::missingBroadcastChannels($event);
        }

        $eventName = method_exists($event, 'broadcastAs')
            ? $event->broadcastAs()
            : $event::class;

        if (! is_string($eventName)) {
            throw RealtimeException::invalidBroadcastName($event, $eventName);
        }

        $this->replay(new CapturedBroadcast(
            channels: $channels,
            event: $eventName,
            payload: $this->broadcastPayload($event),
        ));

        return $this;
    }

    /**
     * Pushes a raw event into the page at the wire boundary.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function emit(string $event, Channel|HasBroadcastChannel|string $channel, array $payload = []): self
    {
        [$name, $visibility] = Channels::parse($channel);

        $this->replay(new CapturedBroadcast(
            channels: [Channels::toWire($name, $visibility)],
            event: $event,
            payload: $payload,
        ));

        return $this;
    }

    /**
     * Refuses a channel subscription, firing Echo's `error()` callback.
     *
     * Channel authorization itself runs against the application's own endpoint
     * and is out of this simulator's scope; this drives the client-side outcome
     * a refusal produces.
     */
    public function failSubscription(
        Channel|HasBroadcastChannel|string $channel,
        int $status = 403,
        string $message = 'Unable to authorize channel',
    ): self {
        [$name, $visibility] = Channels::parse($channel);

        $result = $this->install()->executor->evaluate(
            $this->driver->subscriptionErrorScript($name, $visibility, [
                'type' => 'AuthError',
                'error' => $message,
                'status' => $status,
            ]),
        );

        if ($result === 'not_subscribed') {
            throw RealtimeException::channelNotSubscribed(Channels::toWire($name, $visibility));
        }

        if ($result !== 'ok') {
            throw RealtimeException::unexpectedResult('subscriptionError', $result);
        }

        return $this;
    }

    /**
     * Pushes a client event into the page, as another client's whisper would.
     *
     * Echo receives it through `listenForWhisper()`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function whisper(string $event, Channel|HasBroadcastChannel|string $channel, array $payload = []): self
    {
        return $this->emit('client-'.$event, $channel, $payload);
    }

    /**
     * The client events the page has whispered.
     *
     * @return Collection<int, Whisper>
     */
    public function whispers(): Collection
    {
        $result = $this->install()->executor->evaluate($this->driver->clientEventsScript());

        if (! is_array($result)) {
            throw RealtimeException::unexpectedResult('clientEvents', $result);
        }

        $whispers = [];

        foreach ($result as $record) {
            if (! is_array($record)) {
                throw RealtimeException::unexpectedResult('clientEvents', $result);
            }

            $whispers[] = new Whisper(
                event: is_string($record['event'] ?? null) ? $record['event'] : '',
                channel: is_string($record['channel'] ?? null) ? $record['channel'] : '',
                payload: is_array($record['payload'] ?? null) ? $record['payload'] : [],
                connected: (bool) ($record['connected'] ?? false),
            );
        }

        return new Collection($whispers);
    }

    public function assertWhispered(string $event, ?Closure $callback = null): self
    {
        $whispers = $this->whispers();

        Assert::assertTrue(
            $this->matchingWhispers($whispers, $event, $callback)->isNotEmpty(),
            sprintf(
                "The expected [%s] client event was not sent.\n%s",
                $event,
                $this->whisperSummary($whispers),
            ),
        );

        return $this;
    }

    public function assertNotWhispered(string $event, ?Closure $callback = null): self
    {
        $whispers = $this->whispers();

        Assert::assertTrue(
            $this->matchingWhispers($whispers, $event, $callback)->isEmpty(),
            sprintf(
                "The unexpected [%s] client event was sent.\n%s",
                $event,
                $this->whisperSummary($whispers),
            ),
        );

        return $this;
    }

    /**
     * Runs the callback and returns only the broadcasts it produced.
     */
    public function capture(Closure $callback): CapturedBroadcasts
    {
        if ($this->broadcastCapture === null) {
            throw RealtimeException::broadcastCaptureUnavailable();
        }

        $offset = count($this->flush()->deliveries);

        $callback();

        return new CapturedBroadcasts(
            array_slice($this->flush()->deliveries, $offset),
            $this->broadcastCapture->hint(),
        );
    }

    /**
     * Every broadcast this session has pushed into the page.
     */
    public function captured(): CapturedBroadcasts
    {
        return new CapturedBroadcasts(
            $this->flush()->deliveries,
            $this->broadcastCapture?->hint(),
        );
    }

    /**
     * @param  Closure|array<array-key, mixed>|int|null  $callback  An array matches the payload as a subset.
     */
    public function assertDelivered(string $event, Closure|array|int|null $callback = null): self
    {
        $this->captured()->assertDelivered($event, $callback);

        return $this;
    }

    public function assertDeliveredTimes(string $event, int $times = 1): self
    {
        $this->captured()->assertDeliveredTimes($event, $times);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertDeliveredOn(
        Channel|HasBroadcastChannel|string $channel,
        string $event,
        Closure|array|null $callback = null,
    ): self {
        $this->captured()->assertDeliveredOn($channel, $event, $callback);

        return $this;
    }

    /**
     * Asserts the events reached the page in the given relative order.
     *
     * @param  list<string>  $events
     */
    public function assertDeliveredInOrder(array $events): self
    {
        $this->captured()->assertDeliveredInOrder($events);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertDeliveredVia(
        string $connection,
        string $event,
        Closure|array|null $callback = null,
    ): self {
        $this->captured()->assertDeliveredVia($connection, $event, $callback);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotDeliveredVia(
        string $connection,
        string $event,
        Closure|array|null $callback = null,
    ): self {
        $this->captured()->assertNotDeliveredVia($connection, $event, $callback);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotDelivered(string $event, Closure|array|null $callback = null): self
    {
        $this->captured()->assertNotDelivered($event, $callback);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|int|null  $callback  An array matches the payload as a subset.
     */
    public function assertDropped(string $event, Closure|array|int|null $callback = null): self
    {
        $this->captured()->assertDropped($event, $callback);

        return $this;
    }

    public function assertNothingDropped(): self
    {
        $this->captured()->assertNothingDropped();

        return $this;
    }

    /**
     * Asserts a broadcast notification reached the notifiable's channel.
     *
     * A notifiable resolves to its private model channel. Pass a channel
     * explicitly when the notifiable overrides
     * `receivesBroadcastNotificationsOn()`.
     */
    public function assertNotified(
        Channel|HasBroadcastChannel|string $notifiable,
        string $notification,
        ?Closure $callback = null,
    ): self {
        $this->captured()->assertNotified($notifiable, $notification, $callback);

        return $this;
    }

    public function assertNotNotified(
        Channel|HasBroadcastChannel|string $notifiable,
        string $notification,
        ?Closure $callback = null,
    ): self {
        $this->captured()->assertNotNotified($notifiable, $notification, $callback);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertBroadcast(string $event, Closure|array|null $callback = null): self
    {
        $this->captured()->assertBroadcast($event, $callback);

        return $this;
    }

    /**
     * @param  Closure|array<array-key, mixed>|null  $callback  An array matches the payload as a subset.
     */
    public function assertNotBroadcast(string $event, Closure|array|null $callback = null): self
    {
        $this->captured()->assertNotBroadcast($event, $callback);

        return $this;
    }

    public function assertNothingBroadcast(): self
    {
        $this->captured()->assertNothingBroadcast();

        return $this;
    }

    /**
     * @param  Collection<int, Whisper>  $whispers
     * @return Collection<int, Whisper>
     */
    private function matchingWhispers(
        Collection $whispers,
        string $event,
        ?Closure $callback,
    ): Collection {
        return $whispers->filter(
            static fn (Whisper $whisper): bool => $whisper->event === $event
                && ($callback === null || $callback($whisper) === true),
        );
    }

    /**
     * @param  Collection<int, Whisper>  $whispers
     */
    private function whisperSummary(Collection $whispers): string
    {
        if ($whispers->isEmpty()) {
            return 'Client events sent: none.';
        }

        return 'Client events sent: '.$whispers->map(
            static fn (Whisper $whisper): string => sprintf(
                '%s on [%s]',
                $whisper->event,
                $whisper->channel,
            ),
        )->implode(', ').'.';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function presence(Channel|HasBroadcastChannel|string $channel, PresenceEvent $event, array $data): self
    {
        $name = $this->presenceChannel($channel);

        $result = $this->install()->executor->evaluate(
            $this->driver->presenceScript($name, $event, $data),
        );

        if ($result === 'not_subscribed') {
            throw RealtimeException::presenceChannelNotSubscribed(
                Channels::toWire($name, ChannelVisibility::Presence),
            );
        }

        if ($result !== 'ok') {
            throw RealtimeException::unexpectedResult('presence', $result);
        }

        return $this;
    }

    /**
     * Resolves a presence channel's name, treating a bare name as presence.
     */
    private function presenceChannel(Channel|HasBroadcastChannel|string $channel): string
    {
        [$name, $visibility] = Channels::parse($channel);

        if ($visibility === ChannelVisibility::Presence || $visibility === ChannelVisibility::Public) {
            return $name;
        }

        throw RealtimeException::notAPresenceChannel(Channels::toWire($name, $visibility));
    }

    private function presenceWire(Channel|HasBroadcastChannel|string $channel): string
    {
        return Channels::toWire($this->presenceChannel($channel), ChannelVisibility::Presence);
    }

    /**
     * @param  array<array-key, mixed>  $member
     */
    private function memberId(array $member): string|int
    {
        $id = $member['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw RealtimeException::missingMemberId();
        }

        return $id;
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private function hasMember(array $members, string|int $id): bool
    {
        foreach (array_keys($members) as $key) {
            if ((string) $key === (string) $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private function memberSummary(array $members): string
    {
        if ($members === []) {
            return 'Members present: none.';
        }

        return 'Members present: '.implode(', ', array_map(
            static fn (string|int $id): string => sprintf('[%s]', $id),
            array_keys($members),
        )).'.';
    }

    /**
     * Pushes one broadcast to each of its channels and records the outcomes.
     */
    private function replay(CapturedBroadcast $broadcast): void
    {
        foreach ($broadcast->channels as $wireChannel) {
            [$name, $visibility] = Channels::parse($wireChannel);

            $outcome = $this->excludes($broadcast)
                ? DeliveryOutcome::Excluded
                : $this->push($name, $broadcast->event, $broadcast->payload, $visibility);

            $this->deliveries[] = new Delivery($broadcast, $name, $visibility, $outcome);
        }
    }

    private function excludes(CapturedBroadcast $broadcast): bool
    {
        return $broadcast->socket !== null
            && $broadcast->socket === $this->socketId();
    }

    private function push(
        string $channel,
        string $event,
        mixed $payload,
        ChannelVisibility $visibility,
    ): DeliveryOutcome {
        $result = $this->install()->executor->evaluate(
            $this->driver->emitScript($channel, $event, $payload, $visibility),
        );

        if (! is_string($result) || DeliveryOutcome::tryFrom($result) === null) {
            throw RealtimeException::unexpectedResult('emit', $result);
        }

        return DeliveryOutcome::from($result);
    }

    /**
     * @return list<string>
     */
    private function broadcastChannels(ShouldBroadcast $event, mixed $channels): array
    {
        if (is_array($channels)) {
            $resolved = [];

            foreach ($channels as $channel) {
                array_push($resolved, ...$this->broadcastChannels($event, $channel));
            }

            return $resolved;
        }

        if (! is_string($channels) && ! $channels instanceof Stringable) {
            throw RealtimeException::invalidBroadcastChannel($event, $channels);
        }

        return [(string) $channels];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function broadcastPayload(ShouldBroadcast $event): array
    {
        if (method_exists($event, 'broadcastWith')) {
            $payload = $event->broadcastWith();

            if (is_array($payload)) {
                return $payload;
            }
        }

        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($event);
            $payload[$property->getName()] = $value instanceof Arrayable
                ? $value->toArray()
                : $value;
        }

        unset($payload['broadcastQueue'], $payload['socket']);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function parseChannels(mixed $result, string $operation): array
    {
        if (! is_array($result)) {
            throw RealtimeException::unexpectedResult($operation, $result);
        }

        foreach ($result as $channel) {
            if (! is_string($channel)) {
                throw RealtimeException::unexpectedResult($operation, $result);
            }
        }

        return array_values($result);
    }
}

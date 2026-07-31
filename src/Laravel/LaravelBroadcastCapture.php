<?php

declare(strict_types=1);

namespace Pest\Realtime\Laravel;

use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Support\Testing\Fakes\EventFake;
use Pest\Realtime\CapturedBroadcast;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Exceptions\RealtimeException;
use Throwable;
use WeakMap;

/**
 * Routes an application's broadcasts to the simulated browser client.
 *
 * Laravel's configured broadcast connections are swapped for a capturing driver
 * and restored when the session stops.
 */
final class LaravelBroadcastCapture implements BroadcastCapture
{
    private const string CONNECTION = 'pest-realtime-capture';

    private const string DRIVER = 'pest-realtime-capture';

    /** @var WeakMap<BroadcastManager, true>|null */
    private static ?WeakMap $capturing = null;

    private bool $active = false;

    private mixed $originalBroadcastDefault = null;

    /** @var array<array-key, mixed> */
    private array $originalBroadcastConnections = [];

    private mixed $originalQueueDefault = null;

    private bool $swappedQueue = false;

    public function __construct(
        private readonly ContainerContract $container,
        private readonly BroadcastManager $manager,
        private readonly Repository $config,
    ) {}

    public static function fromContainer(?ContainerContract $container = null): ?self
    {
        $container ??= Container::getInstance();

        if (! $container->bound(BroadcastManager::class) || ! $container->bound('config')) {
            return null;
        }

        try {
            $config = $container->make('config');
            $manager = $container->make(BroadcastManager::class);
        } catch (Throwable) {
            return null;
        }

        if (! $config instanceof Repository) {
            return null;
        }

        return new self($container, $manager, $config);
    }

    public function capturing(): bool
    {
        return $this->active;
    }

    public function start(Closure $onBroadcast): void
    {
        if ($this->active) {
            return;
        }

        $capturing = self::$capturing ??= new WeakMap();

        if (isset($capturing[$this->manager])) {
            throw RealtimeException::captureAlreadyActive();
        }

        $this->guardAgainstFakedEvents();

        $this->originalBroadcastDefault = $this->config->get('broadcasting.default');
        $originalConnections = $this->config->get('broadcasting.connections', []);
        $this->originalBroadcastConnections = is_array($originalConnections) ? $originalConnections : [];

        $connections = [];

        foreach ($this->originalBroadcastConnections as $name => $connection) {
            if (is_string($name)) {
                $connections[$name] = ['driver' => self::DRIVER, 'connection' => $name];
            }
        }

        $connections[self::CONNECTION] = [
            'driver' => self::DRIVER,
            'connection' => is_string($this->originalBroadcastDefault)
                ? $this->originalBroadcastDefault
                : null,
        ];

        $this->manager->extend(
            self::DRIVER,
            fn (mixed $app, array $config): CapturingBroadcaster => new CapturingBroadcaster(
                static function (CapturedBroadcast $broadcast) use ($onBroadcast): void {
                    $onBroadcast($broadcast);
                },
                is_string($config['connection'] ?? null) ? $config['connection'] : null,
            ),
        );

        $this->config->set('broadcasting.default', self::CONNECTION);
        $this->config->set('broadcasting.connections', $connections);
        $this->manager->forgetDrivers();

        $this->useSyncQueue();

        $capturing[$this->manager] = true;
        $this->active = true;
    }

    public function stop(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;

        $this->config->set('broadcasting.default', $this->originalBroadcastDefault);
        $this->config->set('broadcasting.connections', $this->originalBroadcastConnections);
        $this->manager->forgetDrivers();

        if ($this->swappedQueue) {
            $this->config->set('queue.default', $this->originalQueueDefault);
            $this->swappedQueue = false;
        }

        $capturing = self::$capturing;

        if ($capturing !== null) {
            unset($capturing[$this->manager]);
        }
    }

    /**
     * Runs `ShouldBroadcast` events inline.
     *
     * Queued broadcasts are otherwise handled by a separate worker process,
     * which this in-memory capture cannot reach.
     */
    private function useSyncQueue(): void
    {
        if (! $this->config->has('queue.default')) {
            return;
        }

        $this->originalQueueDefault = $this->config->get('queue.default');

        if ($this->originalQueueDefault === 'sync') {
            return;
        }

        $connections = $this->config->get('queue.connections', []);

        if (is_array($connections) && ! isset($connections['sync'])) {
            $connections['sync'] = ['driver' => 'sync'];
            $this->config->set('queue.connections', $connections);
        }

        $this->config->set('queue.default', 'sync');
        $this->swappedQueue = true;
    }

    private function guardAgainstFakedEvents(): void
    {
        if (! $this->container->bound('events')) {
            return;
        }

        try {
            $events = $this->container->make('events');
        } catch (Throwable) {
            return;
        }

        if ($events instanceof EventFake) {
            throw RealtimeException::eventsAreFaked();
        }
    }
}

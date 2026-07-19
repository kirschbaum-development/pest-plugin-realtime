<?php

declare(strict_types=1);

namespace Pest\Realtime\Laravel;

use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Pest\Realtime\CapturedBroadcast;
use Pest\Realtime\Contracts\BroadcastCapture;
use Pest\Realtime\Exceptions\RealtimeSimulationException;
use Throwable;
use WeakMap;

final class LaravelBroadcastCapture implements BroadcastCapture
{
    private const string CONNECTION = 'pest-realtime-capture';

    private const string DRIVER = 'pest-realtime-capture';

    /** @var WeakMap<BroadcastManager, true>|null */
    private static ?WeakMap $capturing = null;

    public function __construct(
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

        return new self(
            $manager,
            $config,
        );
    }

    /**
     * @return list<CapturedBroadcast>
     */
    public function capture(Closure $callback): array
    {
        self::$capturing ??= new WeakMap();

        if (isset(self::$capturing[$this->manager])) {
            throw RealtimeSimulationException::nestedBroadcastCapture();
        }

        $originalDefault = $this->config->get('broadcasting.default');
        $originalConnections = $this->config->get('broadcasting.connections', []);

        if (! is_array($originalConnections)) {
            $originalConnections = [];
        }

        $collector = new BroadcastCollector();
        $connections = [];

        foreach ($originalConnections as $name => $connection) {
            if (is_string($name)) {
                $connections[$name] = [
                    'driver' => self::DRIVER,
                    'connection' => $name,
                ];
            }
        }

        $connections[self::CONNECTION] = [
            'driver' => self::DRIVER,
            'connection' => is_string($originalDefault) ? $originalDefault : null,
        ];

        $this->manager->extend(
            self::DRIVER,
            fn (mixed $app, array $config): CapturingBroadcaster => new CapturingBroadcaster(
                $collector,
                is_string($config['connection'] ?? null) ? $config['connection'] : null,
            ),
        );

        self::$capturing[$this->manager] = true;

        try {
            $this->config->set('broadcasting.default', self::CONNECTION);
            $this->config->set('broadcasting.connections', $connections);
            $this->manager->forgetDrivers();

            $callback();

            return $collector->broadcasts();
        } finally {
            $this->config->set('broadcasting.default', $originalDefault);
            $this->config->set('broadcasting.connections', $originalConnections);
            $this->manager->forgetDrivers();
            unset(self::$capturing[$this->manager]);
        }
    }
}

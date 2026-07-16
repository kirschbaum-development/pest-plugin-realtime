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

final class LaravelBroadcastCapture implements BroadcastCapture
{
    private const string CONNECTION = 'pest-realtime-capture';

    private const string DRIVER = 'pest-realtime-capture';

    private bool $capturing = false;

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
        if ($this->capturing) {
            throw RealtimeSimulationException::nestedBroadcastCapture();
        }

        $originalDefault = $this->config->get('broadcasting.default');
        $originalConnections = $this->config->get('broadcasting.connections', []);

        if (! is_array($originalConnections)) {
            $originalConnections = [];
        }

        $broadcaster = new CapturingBroadcaster();
        $connections = [];

        foreach ($originalConnections as $name => $connection) {
            if (is_string($name)) {
                $connections[$name] = ['driver' => self::DRIVER];
            }
        }

        $connections[self::CONNECTION] = ['driver' => self::DRIVER];

        $this->manager->extend(
            self::DRIVER,
            fn (): CapturingBroadcaster => $broadcaster,
        );

        $this->config->set('broadcasting.default', self::CONNECTION);
        $this->config->set('broadcasting.connections', $connections);
        $this->manager->forgetDrivers();
        $this->capturing = true;

        try {
            $callback();

            return $broadcaster->broadcasts();
        } finally {
            $this->config->set('broadcasting.default', $originalDefault);
            $this->config->set('broadcasting.connections', $originalConnections);
            $this->manager->forgetDrivers();
            $this->capturing = false;
        }
    }
}

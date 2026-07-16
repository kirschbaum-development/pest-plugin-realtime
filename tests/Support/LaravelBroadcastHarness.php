<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Support;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

final readonly class LaravelBroadcastHarness
{
    public Container $container;

    public Repository $config;

    public BroadcastManager $manager;

    public function __construct()
    {
        $this->container = new Container();
        $this->config = new Repository([
            'broadcasting' => [
                'default' => 'null',
                'connections' => [
                    'null' => ['driver' => 'null'],
                    'secondary' => ['driver' => 'null'],
                ],
            ],
        ]);
        $this->manager = new BroadcastManager($this->container);

        $this->container->instance('config', $this->config);
        $this->container->instance(BroadcastManager::class, $this->manager);
    }
}

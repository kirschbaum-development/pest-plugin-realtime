<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Laravel\LaravelBroadcastCapture;
use Pest\Realtime\Support\PestBrowserScriptExecutor;

function broadcasting(
    Webpage|AwaitableWebpage|PendingAwaitablePage $page,
    ?Driver $driver = null,
): RealtimeSession {
    return new RealtimeSession(
        new PestBrowserScriptExecutor($page),
        $driver ?? new EchoPusherDriver(),
        LaravelBroadcastCapture::fromContainer(),
    );
}

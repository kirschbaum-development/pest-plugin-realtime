<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Laravel\LaravelBroadcastCapture;
use Pest\Realtime\Support\PestBrowserScriptExecutor;

/**
 * Starts a realtime session for the given page.
 *
 * The browser runtime installs itself on first use and the simulated client
 * starts connected. Inside a booted Laravel application the session also
 * captures the application's broadcasts and replays them into the page.
 */
function broadcasting(
    Webpage|AwaitableWebpage|PendingAwaitablePage $page,
    ?Driver $driver = null,
): Broadcasting {
    return new Broadcasting(
        new PestBrowserScriptExecutor($page),
        $driver ?? Realtime::driver(),
        LaravelBroadcastCapture::fromContainer(),
    );
}

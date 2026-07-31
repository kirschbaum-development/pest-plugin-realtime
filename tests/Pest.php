<?php

declare(strict_types=1);

use Pest\Realtime\Broadcasting;
use Pest\Realtime\ConnectionStatus;
use Pest\Realtime\Drivers\EchoPusherDriver;
use Pest\Realtime\Tests\Fakes\FakeScriptExecutor;

/**
 * Builds a session whose runtime is already installed and connected.
 *
 * The first two queued results answer the lazy install and the connect
 * transition it performs, so `$results` describes only what the test does next.
 *
 * @param  list<mixed>  $results
 * @return array{Broadcasting, FakeScriptExecutor}
 */
function session(array $results): array
{
    $executor = new FakeScriptExecutor([
        ['auctions.1', 'private-buyers.2'],
        ConnectionStatus::Connected->value,
        ...$results,
    ]);

    return [new Broadcasting($executor, new EchoPusherDriver()), $executor];
}

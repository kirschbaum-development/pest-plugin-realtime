<?php

declare(strict_types=1);

namespace Pest\Realtime\Support;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Pest\Realtime\Contracts\ScriptExecutor;

final readonly class PestBrowserScriptExecutor implements ScriptExecutor
{
    public function __construct(
        private Webpage|AwaitableWebpage|PendingAwaitablePage $page,
    ) {}

    public function evaluate(string $script): mixed
    {
        return $this->page->script($script);
    }
}

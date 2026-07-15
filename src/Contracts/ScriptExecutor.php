<?php

declare(strict_types=1);

namespace Pest\Realtime\Contracts;

interface ScriptExecutor
{
    public function evaluate(string $script): mixed;
}

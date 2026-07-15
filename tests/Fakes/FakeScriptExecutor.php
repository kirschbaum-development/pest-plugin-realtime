<?php

declare(strict_types=1);

namespace Pest\Realtime\Tests\Fakes;

use Pest\Realtime\Contracts\ScriptExecutor;
use RuntimeException;

final class FakeScriptExecutor implements ScriptExecutor
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<mixed>  $results
     */
    public function __construct(private array $results) {}

    public function evaluate(string $script): mixed
    {
        $this->scripts[] = $script;

        if ($this->results === []) {
            throw new RuntimeException('No fake script result was queued.');
        }

        return array_shift($this->results);
    }
}

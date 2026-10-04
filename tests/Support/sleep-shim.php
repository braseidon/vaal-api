<?php

/*
 * Test-only: routes ApiClient's unqualified sleep() call through
 * RecordedSleeps. Required once by the tests that read the `sleep` strategy;
 * it must load before any ApiClient sleep runs, which PHPUnit guarantees by
 * loading every test file before the first test.
 */

namespace Braseidon\VaalApi\Client;

use Braseidon\VaalApi\Tests\Support\RecordedSleeps;

if (! function_exists(__NAMESPACE__.'\sleep')) {
    function sleep(int $seconds): int
    {
        return RecordedSleeps::sleep($seconds);
    }
}

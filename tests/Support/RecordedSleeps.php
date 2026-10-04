<?php

namespace Braseidon\VaalApi\Tests\Support;

/**
 * Records the sleeps ApiClient's `sleep` rate limit strategy asks for, instead
 * of sleeping them.
 *
 * `sleep-shim.php` declares `Braseidon\VaalApi\Client\sleep()`, which PHP
 * resolves ahead of the global `sleep()` for the unqualified call in
 * ApiClient::handleRateLimit(). While recording, the shim lands here; outside
 * a recording it sleeps for real, so no other test changes behaviour.
 */
final class RecordedSleeps
{
    /** @var list<int>|null Seconds asked for, or null when not recording */
    private static ?array $seconds = null;

    public static function start(): void
    {
        self::$seconds = [];
    }

    /**
     * Stop recording and return what was asked for.
     *
     * @return list<int>
     */
    public static function stop(): array
    {
        $seconds = self::$seconds ?? [];
        self::$seconds = null;

        return $seconds;
    }

    public static function sleep(int $seconds): int
    {
        if (self::$seconds === null) {
            return \sleep($seconds);
        }

        self::$seconds[] = $seconds;

        return 0;
    }
}

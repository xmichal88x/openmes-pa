<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Switches that gate runtime behaviour, read from `system_settings` with the
 * config/env value as the declared default.
 *
 * For a switch like `allow_overproduction` the stored value is the source of
 * truth: it is what the admin sets in Settings -> System and what the API
 * writes. The env var (`ALLOW_OVERPRODUCTION`) is a deployment-time default
 * used only when the key is absent, which is also what an installation that
 * never touched the switch sees. Every answer therefore carries its source
 * (`database` or `env`), so a wrong-looking value can be traced back to where
 * it came from instead of being guessed at.
 *
 * Memoised per request rather than cached with a TTL: flipping the switch in
 * the UI must take effect on the next request, not after a cache expiry. The
 * container binds this class as request-scoped, so a long-lived worker (Octane,
 * queue) reads a fresh copy per request/job; flush() drops the memo inside one
 * request (tests, and code that writes the setting in the same process).
 */
final class RuntimeSettings
{
    /** Settings key, and the config/env key that backs it. */
    public const ALLOW_OVERPRODUCTION = 'allow_overproduction';

    public const SOURCE_DATABASE = 'database';

    public const SOURCE_ENV = 'env';

    /** @var array<string, array{value: bool, source: string}> */
    private array $resolved = [];

    /** Whether production may exceed the planned quantity. */
    public function allowOverproduction(): bool
    {
        return $this->allowOverproductionSetting()['value'];
    }

    /**
     * Where the effective value comes from: `database` when a boolean is stored
     * in `system_settings`, `env` when the key is absent (or unreadable) and the
     * declared default `ALLOW_OVERPRODUCTION` applies.
     */
    public function allowOverproductionSource(): string
    {
        return $this->allowOverproductionSetting()['source'];
    }

    /** Drop the per-request memo. */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /** @return array{value: bool, source: string} */
    private function allowOverproductionSetting(): array
    {
        if (isset($this->resolved[self::ALLOW_OVERPRODUCTION])) {
            return $this->resolved[self::ALLOW_OVERPRODUCTION];
        }

        $configured = (bool) config('openmmes.allow_overproduction', false);

        $stored = $this->storedValue(self::ALLOW_OVERPRODUCTION);
        if ($stored === null) {
            return $this->resolved[self::ALLOW_OVERPRODUCTION] = [
                'value' => $configured,
                'source' => self::SOURCE_ENV,
            ];
        }

        $decoded = is_bool($stored) ? $stored : json_decode((string) $stored, true);
        if (is_bool($decoded)) {
            return $this->resolved[self::ALLOW_OVERPRODUCTION] = [
                'value' => $decoded,
                'source' => self::SOURCE_DATABASE,
            ];
        }

        // A stored value we cannot read is not `false`: it is unreadable. Guessing
        // either way would silently change what production may do, so the raw
        // value is named in the log and the declared default applies - with the
        // reported source saying `env`, i.e. "the database is not the answer".
        Log::warning('system_settings.invalid_value', [
            'key' => self::ALLOW_OVERPRODUCTION,
            'raw' => $stored,
            'expected' => 'boolean',
            'applied_source' => self::SOURCE_ENV,
            'applied_value' => $configured,
        ]);

        return $this->resolved[self::ALLOW_OVERPRODUCTION] = [
            'value' => $configured,
            'source' => self::SOURCE_ENV,
        ];
    }

    /**
     * The raw stored value, or null when nothing is stored. A missing table
     * (installer) is the same "nothing stored" case, not an error to page on:
     * the config default then decides, and the reported source says so.
     */
    private function storedValue(string $key): mixed
    {
        try {
            return DB::table('system_settings')->where('key', $key)->value('value');
        } catch (\Throwable) {
            return null;
        }
    }
}

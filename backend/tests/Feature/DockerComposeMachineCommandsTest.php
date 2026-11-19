<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Every service that caches the config gets the host's MACHINE_COMMANDS_* values.
 *
 * Every application container runs docker-entrypoint.sh, which ends with
 * `php artisan config:cache` — and four of them write into the same
 * bootstrap/cache volume. A sidecar started without these variables re-caches
 * the defaults (all off), so `config('machine_commands.enabled')` reads false
 * for the whole stack while the host .env says true. That is exactly how the
 * machine-command feature stayed silently dead until the same nine variables
 * were passed to every service (R31/R35).
 *
 * The compose fallbacks also have to match config/machine_commands.php: a
 * compose default is not "a default the app would have used anyway", it is a
 * value handed to the app, so it silently overrides the config file's fallback
 * — a narrower step_match or a longer lease than the code intends.
 */
class DockerComposeMachineCommandsTest extends TestCase
{
    /** Services that run artisan config:cache (or are kept in sync with them). */
    private const SERVICES = [
        'backend',
        'reverb',
        'mqtt-listener',
        'modbus-poller',
        'opcua-gateway',
        'queue-worker',
    ];

    /** Variable => the value docker-compose.yml must default to when .env is silent. */
    private const VARIABLES = [
        'MACHINE_COMMANDS_ENABLED' => '${MACHINE_COMMANDS_ENABLED:-false}',
        'MACHINE_COMMANDS_LOAD_NC' => '${MACHINE_COMMANDS_LOAD_NC:-false}',
        'MACHINE_COMMANDS_MANUAL_M30' => '${MACHINE_COMMANDS_MANUAL_M30:-false}',
        'MACHINE_COMMANDS_GENERATE_LABELS' => '${MACHINE_COMMANDS_GENERATE_LABELS:-false}',
        'MACHINE_COMMANDS_LINE_CODES' => '${MACHINE_COMMANDS_LINE_CODES:-CNC}',
        'MACHINE_COMMANDS_STEP_MATCH' => '${MACHINE_COMMANDS_STEP_MATCH:-cnc,ciecie,cięcie,nest,frez}',
        'MACHINE_COMMANDS_WORKSTATION_TYPES' => '${MACHINE_COMMANDS_WORKSTATION_TYPES:-}',
        'MACHINE_COMMANDS_LEASE_SECONDS' => '${MACHINE_COMMANDS_LEASE_SECONDS:-300}',
        'MACHINE_COMMANDS_MAX_ATTEMPTS' => '${MACHINE_COMMANDS_MAX_ATTEMPTS:-5}',
    ];

    /** Variable => the config key whose file default it must reproduce. */
    private const CONFIG_KEYS = [
        'MACHINE_COMMANDS_ENABLED' => 'machine_commands.enabled',
        'MACHINE_COMMANDS_LOAD_NC' => 'machine_commands.types.load_nc',
        'MACHINE_COMMANDS_MANUAL_M30' => 'machine_commands.types.manual_m30',
        'MACHINE_COMMANDS_GENERATE_LABELS' => 'machine_commands.types.generate_labels',
        'MACHINE_COMMANDS_LINE_CODES' => 'machine_commands.line_codes',
        'MACHINE_COMMANDS_STEP_MATCH' => 'machine_commands.step_match',
        'MACHINE_COMMANDS_WORKSTATION_TYPES' => 'machine_commands.workstation_type_codes',
        'MACHINE_COMMANDS_LEASE_SECONDS' => 'machine_commands.lease_seconds',
        'MACHINE_COMMANDS_MAX_ATTEMPTS' => 'machine_commands.max_attempts',
    ];

    public function test_every_config_caching_service_gets_the_machine_command_flags(): void
    {
        $compose = $this->compose();

        foreach (self::SERVICES as $serwis) {
            $this->assertArrayHasKey($serwis, $compose['services'] ?? [], "docker-compose.yml has no {$serwis} service.");
            $srodowisko = $compose['services'][$serwis]['environment'] ?? [];

            foreach (self::VARIABLES as $zmienna => $domyslna) {
                $this->assertSame(
                    $domyslna,
                    $srodowisko[$zmienna] ?? null,
                    "Service {$serwis} does not pass {$zmienna} from the host .env.",
                );
            }
        }
    }

    public function test_no_service_caches_a_different_set_of_machine_command_flags(): void
    {
        $compose = $this->compose();
        $zestawy = [];

        foreach (self::SERVICES as $serwis) {
            $srodowisko = $compose['services'][$serwis]['environment'] ?? [];
            $zestawy[$serwis] = array_intersect_key($srodowisko, self::VARIABLES);
        }

        $wzorzec = $zestawy['backend'];
        $this->assertNotEmpty($wzorzec);

        foreach ($zestawy as $serwis => $zestaw) {
            $this->assertSame(
                $wzorzec,
                $zestaw,
                "Service {$serwis} would cache different machine-command flags than backend, and the last writer wins.",
            );
        }
    }

    public function test_compose_defaults_reproduce_the_config_file_defaults(): void
    {
        // config() reflects the .env override when one is set, and that override
        // is exactly what compose must not second-guess — so compare only on a
        // machine where the file defaults are what the app would see.
        foreach (array_keys(self::CONFIG_KEYS) as $zmienna) {
            if (env($zmienna) !== null) {
                $this->markTestSkipped("{$zmienna} is set in this environment, so config() no longer reports the file default.");
            }
        }

        $srodowisko = $this->compose()['services']['backend']['environment'] ?? [];

        foreach (self::CONFIG_KEYS as $zmienna => $klucz) {
            $zConfigu = config($klucz);

            $this->assertSame(
                $zConfigu,
                $this->domyslnaZCompose($srodowisko[$zmienna] ?? null, $zConfigu),
                "docker-compose.yml defaults {$zmienna} to something other than {$klucz}: a compose fallback is a value handed to the app, not a fallback it would have used anyway.",
            );
        }
    }

    /**
     * Read `${VAR:-default}` as the value the app would end up seeing, typed
     * like the config value it is compared against.
     */
    private function domyslnaZCompose(?string $wyrazenie, mixed $zConfigu): mixed
    {
        if (! is_string($wyrazenie) || ! preg_match('/^\$\{[A-Z0-9_]+:-(.*)\}$/', $wyrazenie, $dopasowanie)) {
            // No `:-` fallback at all — an empty value is not a default.
            return '__no_default__';
        }

        $domyslna = $dopasowanie[1];

        return match (true) {
            is_bool($zConfigu) => in_array(strtolower($domyslna), ['true', '1', 'yes', 'on'], true),
            is_int($zConfigu) => (int) $domyslna,
            is_array($zConfigu) => array_values(array_filter(
                array_map('trim', explode(',', $domyslna)),
                fn (string $element) => $element !== '',
            )),
            default => $domyslna,
        };
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        $plik = dirname(base_path()).'/docker-compose.yml';

        if (! is_file($plik)) {
            $this->markTestSkipped('docker-compose.yml is not next to the backend in this checkout.');
        }

        return Yaml::parseFile($plik);
    }
}

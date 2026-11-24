<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settings → Import: the material shortage hold is a gate opened on its own
 * form, after the balances are declared, so a configuration file may not switch
 * it on. A file that does is refused whole - nothing from it is saved.
 */
class ConfigurationImportHoldTest extends TestCase
{
    use RefreshDatabase;

    /** The switch a file may not turn on. */
    private const HOLD = 'hold_on_material_shortage';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Operator', 'web');
        $this->admin = User::factory()->create(['username' => 'boss', 'password' => Hash::make('boss-secret')]);
        $this->admin->assignRole('Admin');
    }

    private function file(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('config.json', json_encode($data));
    }

    private function import(array $data)
    {
        return $this->actingAs($this->admin)->post(route('settings.import'), [
            'settings_file' => $this->file($data),
        ]);
    }

    /** The same file posted by a client that asks for JSON rather than the admin form. */
    private function importJson(array $data)
    {
        return $this->actingAs($this->admin)->post(route('settings.import'), [
            'settings_file' => $this->file($data),
        ], ['Accept' => 'application/json']);
    }

    public function test_an_import_that_switches_on_the_shortage_hold_is_refused_and_saves_nothing(): void
    {
        $workflow = DB::table('system_settings')->where('key', 'workflow_mode')->value('value');

        $this->import([
            // The other setting comes first in the file: it is written before the
            // offending key is reached, so only the rollback keeps it out.
            'system_settings' => ['workflow_mode' => 'status', self::HOLD => true],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, self::HOLD) && str_contains($m, 'false'));

        $this->assertDatabaseMissing('system_settings', ['key' => self::HOLD]);
        $this->assertSame($workflow, DB::table('system_settings')->where('key', 'workflow_mode')->value('value'));
        $this->assertNotSame('status', DB::table('system_settings')->where('key', 'workflow_mode')->value('value'));
    }

    public function test_the_refusal_is_a_json_422_for_a_client_that_expects_json(): void
    {
        $workflow = DB::table('system_settings')->where('key', 'workflow_mode')->value('value');

        $this->importJson([
            'system_settings' => [self::HOLD => true, 'workflow_mode' => 'status'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.settings_file.0', fn ($m) => str_contains($m, self::HOLD));

        $this->assertDatabaseMissing('system_settings', ['key' => self::HOLD]);
        $this->assertSame($workflow, DB::table('system_settings')->where('key', 'workflow_mode')->value('value'));
    }

    public function test_a_file_with_the_switch_false_imports_normally(): void
    {
        $this->import([
            'system_settings' => [self::HOLD => false, 'workflow_mode' => 'status'],
        ])->assertSessionHas('success');

        $this->assertFalse(json_decode((string) DB::table('system_settings')->where('key', self::HOLD)->value('value'), true));
        $this->assertSame('status', json_decode((string) DB::table('system_settings')->where('key', 'workflow_mode')->value('value'), true));
    }

    public function test_a_file_without_the_key_leaves_a_stored_true_alone(): void
    {
        // The gate is opened on its own form: a file that does not carry the key
        // never touches the row.
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::HOLD],
            ['value' => json_encode(true), 'updated_at' => now()],
        );

        $this->import([
            'system_settings' => ['workflow_mode' => 'status'],
        ])->assertSessionHas('success');

        $this->assertTrue(json_decode((string) DB::table('system_settings')->where('key', self::HOLD)->value('value'), true));
        $this->assertSame('status', json_decode((string) DB::table('system_settings')->where('key', 'workflow_mode')->value('value'), true));
    }
}

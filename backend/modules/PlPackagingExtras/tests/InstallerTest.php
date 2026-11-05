<?php

namespace Modules\PlPackagingExtras\tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\PlPackagingExtras\Installer;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_creates_packing_line_and_workstation(): void
    {
        app(Installer::class)->install();

        $line = DB::table('lines')
            ->where('code', 'PACKING')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame('Pakowanie', $line->name);
        $this->assertTrue((bool) $line->is_active);
        $this->assertSame('queue', $line->default_operator_view);

        $workstation = DB::table('workstations')
            ->where('code', 'PACK-1')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($workstation);
        $this->assertSame((int) $line->id, (int) $workstation->line_id);
        $this->assertSame('Pakowanie', $workstation->name);
        $this->assertTrue((bool) $workstation->is_active);
    }

    public function test_second_install_does_not_duplicate_rows(): void
    {
        $installer = app(Installer::class);

        $installer->install();
        $installer->install();

        $this->assertSame(1, DB::table('lines')->where('code', 'PACKING')->count());
        $this->assertSame(1, DB::table('workstations')->where('code', 'PACK-1')->count());
    }

    public function test_install_does_not_overwrite_existing_rows(): void
    {
        $now = now();

        $lineId = DB::table('lines')->insertGetId([
            'code' => 'PACKING',
            'name' => 'Custom line',
            'is_active' => false,
            'default_operator_view' => 'kanban',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workstationId = DB::table('workstations')->insertGetId([
            'line_id' => $lineId,
            'code' => 'PACK-1',
            'name' => 'Custom station',
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(Installer::class)->install();

        $line = DB::table('lines')->where('id', $lineId)->first();
        $this->assertSame('Custom line', $line->name);
        $this->assertSame(0, (int) $line->is_active);
        $this->assertSame('kanban', $line->default_operator_view);

        $workstation = DB::table('workstations')->where('id', $workstationId)->first();
        $this->assertSame('Custom station', $workstation->name);
        $this->assertSame((int) $lineId, (int) $workstation->line_id);
        $this->assertSame(0, (int) $workstation->is_active);
        $this->assertSame(1, DB::table('lines')->where('code', 'PACKING')->count());
        $this->assertSame(1, DB::table('workstations')->where('code', 'PACK-1')->count());
    }

    public function test_soft_deleted_rows_are_not_treated_as_active(): void
    {
        $this->usePartialUniqueIndexOnLines();

        $now = now();

        $deletedLineId = DB::table('lines')->insertGetId([
            'code' => 'PACKING',
            'name' => 'Deleted line',
            'is_active' => true,
            'default_operator_view' => 'queue',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $now,
        ]);

        DB::table('workstations')->insert([
            'line_id' => $deletedLineId,
            'code' => 'PACK-1',
            'name' => 'Deleted station',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $now,
        ]);

        app(Installer::class)->install();

        $activeLine = DB::table('lines')
            ->where('code', 'PACKING')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($activeLine);
        $this->assertSame('Pakowanie', $activeLine->name);

        $activeWorkstation = DB::table('workstations')
            ->where('code', 'PACK-1')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($activeWorkstation);
        $this->assertSame((int) $activeLine->id, (int) $activeWorkstation->line_id);

        $this->assertSame(2, DB::table('lines')->where('code', 'PACKING')->count());
        $this->assertSame(2, DB::table('workstations')->where('code', 'PACK-1')->count());
    }

    private function usePartialUniqueIndexOnLines(): void
    {
        DB::statement('DROP INDEX IF EXISTS "lines_code_unique"');
        DB::statement('CREATE UNIQUE INDEX "lines_code_unique" ON "lines" ("code") WHERE "deleted_at" IS NULL');
    }
}

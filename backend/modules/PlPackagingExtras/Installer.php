<?php

namespace Modules\PlPackagingExtras;

use Illuminate\Support\Facades\DB;

class Installer
{
    public function install(): void
    {
        DB::transaction(function (): void {
            $lineId = DB::table('lines')
                ->where('code', 'PACKING')
                ->whereNull('deleted_at')
                ->value('id');

            if ($lineId === null) {
                $now = now();

                $lineId = DB::table('lines')->insertGetId([
                    'code' => 'PACKING',
                    'name' => 'Pakowanie',
                    'is_active' => true,
                    'default_operator_view' => 'queue',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $workstationExists = DB::table('workstations')
                ->where('code', 'PACK-1')
                ->whereNull('deleted_at')
                ->exists();

            if (! $workstationExists) {
                $now = now();

                DB::table('workstations')->insert([
                    'line_id' => $lineId,
                    'code' => 'PACK-1',
                    'name' => 'Pakowanie',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function uninstall(): void
    {
    }
}

<?php

namespace Tests\Feature\Quality;

use App\Models\QaProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class QaProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::create(['name' => 'QA tenant'])->id;
        app(TenantContext::class)->set($this->tenantId);
    }

    public function test_defaults_are_applied(): void
    {
        $profile = QaProfile::factory()->create();

        $this->assertTrue($profile->required);
        $this->assertTrue($profile->active);
        $this->assertSame(1, $profile->version);
        $this->assertSame('dim', $profile->checklist_json[0]['id']);
    }

    public function test_product_type_id_is_unique(): void
    {
        $profile = QaProfile::factory()->create();

        $this->expectException(QueryException::class);

        QaProfile::factory()->create([
            'product_type_id' => $profile->product_type_id,
            'product_type_code' => 'OTHER-CODE',
        ]);
    }

    public function test_product_type_code_is_unique(): void
    {
        $profile = QaProfile::factory()->create();

        $this->expectException(QueryException::class);

        QaProfile::factory()->create([
            'product_type_code' => $profile->product_type_code,
        ]);
    }

    public function test_checklist_json_is_cast_to_array(): void
    {
        $profile = QaProfile::factory()->create([
            'checklist_json' => [
                ['id' => 'dims', 'label' => 'Wymiary', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 10],
            ],
        ])->fresh();

        $this->assertIsArray($profile->checklist_json);
        $this->assertSame('dims', $profile->checklist_json[0]['id']);
    }

    public function test_soft_delete_records_who_deleted_the_profile(): void
    {
        $user = User::factory()->create();
        $profile = QaProfile::factory()->create();

        $this->actingAs($user);
        $profile->delete();

        $this->assertSoftDeleted($profile);
        $this->assertSame($user->id, $profile->fresh()->deleted_by_id);
    }

    public function test_active_scope_returns_only_active_profiles(): void
    {
        QaProfile::factory()->create();
        QaProfile::factory()->inactive()->create();

        $this->assertSame(1, QaProfile::query()->active()->count());
    }

    public function test_tenant_scope_hides_profiles_of_another_tenant(): void
    {
        QaProfile::factory()->create();

        $other = Tenant::create(['name' => 'Other tenant']);
        app(TenantContext::class)->set($other->id);

        $this->assertSame(0, QaProfile::query()->count());
    }

    public function test_valid_checklist_has_no_errors(): void
    {
        $items = [
            ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool'],
            ['id' => 'qty', 'label' => 'Ilość', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 100],
            ['id' => 'note', 'label' => 'Uwagi', 'type' => 'text'],
        ];

        $this->assertSame([], QaProfile::checklistErrors($items));
    }

    public function test_empty_checklist_is_an_error_when_required(): void
    {
        $this->assertNotSame([], QaProfile::checklistErrors([]));
        $this->assertSame([], QaProfile::checklistErrors([], false));
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $items = [
            ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool'],
            ['id' => 'dim', 'label' => 'Drugi wymiar', 'type' => 'bool'],
        ];

        $this->assertNotSame([], QaProfile::checklistErrors($items));
    }

    public function test_unknown_type_is_rejected(): void
    {
        $items = [
            ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'select'],
        ];

        $this->assertNotSame([], QaProfile::checklistErrors($items));
    }

    public function test_number_min_greater_than_max_is_rejected(): void
    {
        $items = [
            ['id' => 'qty', 'label' => 'Ilość', 'type' => 'number', 'min' => 10, 'max' => 1],
        ];

        $this->assertNotSame([], QaProfile::checklistErrors($items));
    }

    public function test_missing_label_is_rejected(): void
    {
        $items = [
            ['id' => 'dim', 'type' => 'bool'],
        ];

        $this->assertNotSame([], QaProfile::checklistErrors($items));
    }

    public function test_normalise_checklist_keeps_only_allowed_keys(): void
    {
        $items = [
            ['id' => 'qty', 'label' => 'Ilość', 'type' => 'number', 'min' => 1, 'max' => 5, 'unexpected' => 'x'],
        ];

        $this->assertSame([
            ['id' => 'qty', 'label' => 'Ilość', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 5],
        ], QaProfile::normaliseChecklist($items));
    }

    public function test_normalise_checklist_allows_an_empty_optional_list(): void
    {
        $this->assertSame([], QaProfile::normaliseChecklist(null, false));
        $this->assertSame([], QaProfile::normaliseChecklist([], false));
    }

    public function test_normalise_checklist_throws_on_invalid_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QaProfile::normaliseChecklist([
            ['id' => 'dim', 'label' => '', 'type' => 'bool'],
        ]);
    }

    public function test_has_valid_checklist_uses_required_flag(): void
    {
        $required = QaProfile::factory()->create(['checklist_json' => []]);
        $optional = QaProfile::factory()->notRequired()->create(['checklist_json' => []]);

        $this->assertFalse($required->hasValidChecklist());
        $this->assertTrue($optional->hasValidChecklist());
    }
}

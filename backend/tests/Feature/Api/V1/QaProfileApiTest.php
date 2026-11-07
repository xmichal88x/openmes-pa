<?php

namespace Tests\Feature\Api\V1;

use App\Models\ProductType;
use App\Models\QaProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function checklist(): array
    {
        return [
            ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool', 'required' => true],
            ['id' => 'qty', 'label' => 'Ilość', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 100],
        ];
    }

    public function test_admin_can_create_a_profile(): void
    {
        $productType = ProductType::factory()->create();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/qa-profiles', [
            'product_type_id' => $productType->id,
            'required' => true,
            'active' => true,
            'checklist_json' => $this->checklist(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.product_type_id', $productType->id)
            ->assertJsonPath('data.product_type_code', $productType->code)
            ->assertJsonPath('data.product_type.code', $productType->code)
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.checklist_json.0.id', 'dim');

        $this->assertDatabaseHas('qa_profiles', [
            'product_type_id' => $productType->id,
            'product_type_code' => $productType->code,
            'version' => 1,
        ]);
    }

    public function test_product_type_code_is_derived_when_omitted(): void
    {
        $productType = ProductType::factory()->create(['code' => 'PANEL-18']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/qa-profiles', [
                'product_type_id' => $productType->id,
                'checklist_json' => $this->checklist(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.product_type_code', 'PANEL-18');
    }

    public function test_mismatched_product_type_code_is_rejected(): void
    {
        $productType = ProductType::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/qa-profiles', [
                'product_type_id' => $productType->id,
                'product_type_code' => 'WRONG-CODE',
                'checklist_json' => $this->checklist(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_type_code');
    }

    public function test_duplicate_product_type_id_is_rejected(): void
    {
        $profile = QaProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/qa-profiles', [
                'product_type_id' => $profile->product_type_id,
                'product_type_code' => $profile->product_type_code,
                'checklist_json' => $this->checklist(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_type_id');
    }

    public function test_invalid_checklists_are_rejected(): void
    {
        $cases = [
            'empty when required' => [],
            'duplicate ids' => [
                ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool'],
                ['id' => 'dim', 'label' => 'Drugi wymiar', 'type' => 'bool'],
            ],
            'unknown type' => [
                ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'select'],
            ],
        ];

        foreach ($cases as $label => $checklist) {
            $productType = ProductType::factory()->create();

            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/v1/qa-profiles', [
                    'product_type_id' => $productType->id,
                    'required' => true,
                    'checklist_json' => $checklist,
                ])
                ->assertStatus(422, $label)
                ->assertJsonValidationErrors('checklist_json');
        }
    }

    public function test_required_and_active_changes_do_not_bump_version(): void
    {
        $profile = QaProfile::factory()->create(['version' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/qa-profiles/{$profile->id}", [
                'required' => false,
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.required', false)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('qa_profiles', [
            'id' => $profile->id,
            'required' => false,
            'active' => false,
            'version' => 1,
        ]);
    }

    public function test_sending_the_same_checklist_does_not_bump_version(): void
    {
        $profile = QaProfile::factory()->create(['version' => 1, 'checklist_json' => $this->checklist()]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/qa-profiles/{$profile->id}", [
                'checklist_json' => $this->checklist(),
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 1);
    }

    public function test_checklist_change_bumps_version(): void
    {
        $profile = QaProfile::factory()->create(['version' => 1, 'checklist_json' => $this->checklist()]);

        $changed = $this->checklist();
        $changed[] = ['id' => 'note', 'label' => 'Uwagi', 'type' => 'text', 'required' => false];

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/qa-profiles/{$profile->id}", [
                'checklist_json' => $changed,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.checklist_json.2.id', 'note');

        $this->assertDatabaseHas('qa_profiles', ['id' => $profile->id, 'version' => 2]);
    }

    public function test_show_resolves_a_profile_by_id_and_by_code(): void
    {
        $profile = QaProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/qa-profiles/{$profile->product_type_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/qa-profiles/{$profile->product_type_code}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id);
    }

    public function test_show_returns_404_for_an_unknown_profile(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/qa-profiles/NOPE-404')
            ->assertNotFound();
    }

    public function test_delete_soft_deletes_the_profile(): void
    {
        $profile = QaProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/qa-profiles/{$profile->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('qa_profiles', ['id' => $profile->id]);
    }

    public function test_operator_cannot_manage_qa_profiles(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/qa-profiles')
            ->assertForbidden();

        $productType = ProductType::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/qa-profiles', [
                'product_type_id' => $productType->id,
                'checklist_json' => $this->checklist(),
            ])
            ->assertForbidden();
    }
}

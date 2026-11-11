<?php

namespace Tests\Feature\Packaging;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The MES is a read-only proxy to the PA/CSA label store: it must list the
 * already generated sheet labels and stream the PDF, never regenerate them.
 */
class AlphacamLabelProxyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-service-token';

    private const BASE_URL = 'https://pa.test';

    private User $operator;

    private BatchStep $step;

    private string $labelBatchId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Role::findOrCreate('Operator', 'web');
        Role::findOrCreate('PackingWorker', 'web');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');

        $batch = Batch::factory()->create();
        $this->step = BatchStep::factory()->create(['batch_id' => $batch->id]);
        $this->labelBatchId = str_repeat('a', 64);

        config([
            'packaging.alphacam_labels.base_url' => self::BASE_URL,
            'packaging.alphacam_labels.token' => self::TOKEN,
            'packaging.alphacam_labels.timeout' => 2.0,
        ]);
    }

    private function listing(): array
    {
        return [
            'batch_id' => $this->step->batch_id,
            'sheets' => [[
                'sheet' => 'Arkusz A1',
                'order_no' => '1182',
                'label_batch_id' => $this->labelBatchId,
                'status' => 'generated',
                'labels_pdf' => '/api/labels/'.$this->labelBatchId.'/labels.pdf',
                'map_pdf' => '/api/labels/'.$this->labelBatchId.'/map.pdf',
                'labels_count' => 6,
                'batch_labels_count' => 4,
            ]],
        ];
    }

    private function indexUrl(): string
    {
        return route('packaging.labels.alphacam.index', $this->step);
    }

    private function pdfUrl(?string $labelBatchId = null): string
    {
        return route('packaging.labels.alphacam.pdf', [
            'batchStep' => $this->step,
            'label_batch_id' => $labelBatchId ?? $this->labelBatchId,
        ]);
    }

    private function mapUrl(?string $labelBatchId = null): string
    {
        return route('packaging.labels.alphacam.map', [
            'batchStep' => $this->step,
            'label_batch_id' => $labelBatchId ?? $this->labelBatchId,
        ]);
    }

    public function test_operator_gets_sheets_with_mes_pdf_route(): void
    {
        Http::fake(['*' => Http::response($this->listing(), 200)]);
        $expectedPdfUrl = route('packaging.labels.alphacam.pdf', [
            'batchStep' => $this->step,
            'label_batch_id' => $this->labelBatchId,
        ]);
        $expectedMapUrl = route('packaging.labels.alphacam.map', [
            'batchStep' => $this->step,
            'label_batch_id' => $this->labelBatchId,
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->indexUrl())
            ->assertOk()
            ->assertJsonPath('batch_step_id', $this->step->id)
            ->assertJsonPath('batch_id', $this->step->batch_id)
            ->assertJsonPath('sheets.0.sheet', 'Arkusz A1')
            ->assertJsonPath('sheets.0.labels_count', 6)
            ->assertJsonPath('sheets.0.labels_pdf', $expectedPdfUrl)
            ->assertJsonPath('sheets.0.map_pdf', $expectedMapUrl);

        Http::assertSent(fn ($request) => $request->url() === self::BASE_URL.'/api/labels/by-batch/'.$this->step->batch_id
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_index_leaves_map_pdf_null_when_pa_has_no_map(): void
    {
        $listing = $this->listing();
        $listing['sheets'][0]['map_pdf'] = null;

        Http::fake(['*' => Http::response($listing, 200)]);

        $this->actingAs($this->operator)
            ->getJson($this->indexUrl())
            ->assertOk()
            ->assertJsonPath('sheets.0.map_pdf', null);
    }

    public function test_missing_configuration_returns_503(): void
    {
        config([
            'packaging.alphacam_labels.base_url' => null,
            'packaging.alphacam_labels.token' => null,
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->indexUrl())
            ->assertStatus(503)
            ->assertJsonMissing(['token' => self::TOKEN]);
    }

    public function test_pa_error_returns_502(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->actingAs($this->operator)
            ->getJson($this->indexUrl())
            ->assertStatus(502);
    }

    public function test_connection_error_returns_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->actingAs($this->operator)
            ->getJson($this->indexUrl())
            ->assertStatus(503);
    }

    public function test_pdf_streams_the_generated_file(): void
    {
        Http::fake([
            '*/api/labels/by-batch/*' => Http::response($this->listing(), 200),
            '*/labels.pdf' => Http::response('%PDF-1.4 fake-pdf', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($this->operator)->get($this->pdfUrl());

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'inline; filename="labels-'.$this->step->batch_id.'-'.$this->labelBatchId.'.pdf"'
            );

        $this->assertSame('%PDF-1.4 fake-pdf', $response->streamedContent());
    }

    public function test_pdf_rejects_a_malformed_label_batch_id(): void
    {
        $this->actingAs($this->operator)
            ->getJson($this->pdfUrl('not-a-valid-id'))
            ->assertStatus(422);
    }

    public function test_pdf_rejects_a_label_batch_id_outside_the_batch(): void
    {
        Http::fake(['*' => Http::response($this->listing(), 200)]);

        $this->actingAs($this->operator)
            ->getJson($this->pdfUrl(str_repeat('b', 64)))
            ->assertNotFound();
    }

    public function test_pdf_reports_a_pa_error(): void
    {
        Http::fake([
            '*/api/labels/by-batch/*' => Http::response($this->listing(), 200),
            '*/labels.pdf' => Http::response('boom', 500),
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->pdfUrl())
            ->assertStatus(502);
    }

    public function test_pdf_requires_configuration(): void
    {
        config([
            'packaging.alphacam_labels.base_url' => null,
            'packaging.alphacam_labels.token' => null,
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->pdfUrl())
            ->assertStatus(503);
    }

    public function test_map_streams_the_generated_file(): void
    {
        Http::fake([
            '*/api/labels/by-batch/*' => Http::response($this->listing(), 200),
            '*/map.pdf' => Http::response('%PDF-1.4 map', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($this->operator)->get($this->mapUrl());

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'inline; filename="map-'.$this->step->batch_id.'-'.$this->labelBatchId.'.pdf"'
            );

        $this->assertSame('%PDF-1.4 map', $response->streamedContent());
    }

    public function test_map_rejects_a_malformed_label_batch_id(): void
    {
        $this->actingAs($this->operator)
            ->getJson($this->mapUrl('not-a-valid-id'))
            ->assertStatus(422);
    }

    public function test_map_rejects_a_label_batch_id_outside_the_batch(): void
    {
        Http::fake(['*' => Http::response($this->listing(), 200)]);

        $this->actingAs($this->operator)
            ->getJson($this->mapUrl(str_repeat('b', 64)))
            ->assertNotFound();
    }

    public function test_map_reports_a_pa_error(): void
    {
        Http::fake([
            '*/api/labels/by-batch/*' => Http::response($this->listing(), 200),
            '*/map.pdf' => Http::response('boom', 500),
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->mapUrl())
            ->assertStatus(502);
    }

    public function test_map_requires_configuration(): void
    {
        config([
            'packaging.alphacam_labels.base_url' => null,
            'packaging.alphacam_labels.token' => null,
        ]);

        $this->actingAs($this->operator)
            ->getJson($this->mapUrl())
            ->assertStatus(503);
    }

    public function test_guest_cannot_access_the_proxy(): void
    {
        $this->getJson($this->indexUrl())->assertUnauthorized();
    }

    public function test_a_role_outside_the_label_group_is_forbidden(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('PackingWorker');

        $this->actingAs($worker)->getJson($this->indexUrl())->assertForbidden();
    }
}

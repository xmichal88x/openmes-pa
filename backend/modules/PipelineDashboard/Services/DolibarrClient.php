<?php

namespace Modules\PipelineDashboard\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DolibarrClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('DOLIBARR_URL', ''), '/');
        $this->apiKey = env('DOLIBARR_API_KEY', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    /**
     * Pobierz status MO dla order_no.
     */
    public function getMoStatus(string $orderNo): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $cacheKey = "pipeline_doli_mo_{$orderNo}";

        return Cache::remember($cacheKey, 60, function () use ($orderNo) {
            try {
                $response = Http::withHeaders([
                    'DOLAPIKEY' => $this->apiKey,
                ])->timeout(5)->get("{$this->baseUrl}/mos", [
                    'limit' => 1,
                    'sortfield' => 't.rowid',
                    'sortorder' => 'DESC',
                ]);

                if ($response->failed()) {
                    return null;
                }

                $mos = $response->json('data', []);

                foreach ($mos as $mo) {
                    $ref = $mo['ref'] ?? '';
                    if (str_contains($ref, $orderNo)) {
                        return [
                            'mo_ref' => $ref,
                            'status' => $mo['fk_statut'] ?? null,
                            'completed' => ($mo['fk_statut'] ?? 0) >= 3,
                        ];
                    }
                }

                return null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}

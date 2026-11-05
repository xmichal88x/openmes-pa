<?php

namespace Modules\PlPackagingExtras\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'module' => 'PlPackagingExtras',
        ]);
    }
}

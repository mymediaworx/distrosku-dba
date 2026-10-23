<?php

namespace App\Http\Controllers;

use App\Services\HealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __invoke(Request $request, HealthChecker $health): JsonResponse
    {
        $instance = $request->attributes->get('instance');
        $health->record($instance, (array) $request->json()->all(), 'push');

        return response()->json(['ok' => true, 'status' => $instance->fresh()->status]);
    }
}

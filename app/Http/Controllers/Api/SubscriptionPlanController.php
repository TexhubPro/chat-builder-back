<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'plans' => $plans,
        ]);
    }
}

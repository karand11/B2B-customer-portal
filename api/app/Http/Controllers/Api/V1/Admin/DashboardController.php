<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): JsonResponse
    {
        // Staff and admin see the same numbers. The route is already behind role:staff|admin; this is the backstop.
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(['data' => $stats->get()]);
    }
}

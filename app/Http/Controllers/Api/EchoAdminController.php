<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use App\Models\ChatMetricsSetting;

class EchoAdminController extends Controller
{
    // POST /api/admin/echo/prune
    public function prune(Request $request)
    {
        $this->authorize('viewFilament');

        $days = intval($request->get('days', 0));
        if ($days < 1) {
            $setting = ChatMetricsSetting::first();
            $days = $setting ? intval($setting->retention_days) : 30;
            if ($days < 1) $days = 30;
        }

        // call the artisan command programmatically
        $exit = Artisan::call('metrics:prune-buckets', ['--days' => $days]);
        $output = Artisan::output();

        return response()->json(['ok' => true, 'output' => trim($output)]);
    }

    // GET /api/admin/echo/settings
    public function settings(Request $request)
    {
        $this->authorize('viewFilament');
        $setting = ChatMetricsSetting::first();
        return response()->json(['retention_days' => $setting ? intval($setting->retention_days) : 30]);
    }
}

<?php

namespace App\Observers;

use Illuminate\Support\Facades\Cache;

class DashboardCacheInvalidationObserver
{
    public function created($model): void
    {
        $this->clearCache();
    }

    public function updated($model): void
    {
        $this->clearCache();
    }

    public function deleted($model): void
    {
        $this->clearCache();
    }

    public function restored($model): void
    {
        $this->clearCache();
    }

    protected function clearCache(): void
    {
        try {
            if (Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
                Cache::tags(['dashboard_charts'])->flush();
            } else {
                // Fallback: clear entire cache if tagging not supported. This is heavy but safe.
                Cache::flush();
            }
        } catch (\Throwable $e) {
            // Swallow exceptions to avoid breaking model operations; log if needed.
            logger()->warning('Failed to clear dashboard cache: ' . $e->getMessage());
        }
    }
}

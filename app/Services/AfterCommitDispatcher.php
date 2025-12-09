<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Helper to dispatch events only after DB transactions commit.
 * If not in a transaction, dispatches immediately.
 */
class AfterCommitDispatcher
{
    /**
     * Dispatch an event after current DB transaction commits.
     *
     * @param mixed $event
     * @return void
     */
    public static function dispatch($event): void
    {
        try {
            // DB::transactionLevel exists on supported Laravel versions. If in a transaction,
            // queue the dispatch for after commit; otherwise dispatch immediately.
            if (method_exists(DB::class, 'transactionLevel') ? DB::transactionLevel() > 0 : false) {
                DB::afterCommit(function () use ($event) {
                    Event::dispatch($event);
                });
            } else {
                Event::dispatch($event);
            }
        } catch (\Throwable $e) {
            // Fallback: if anything goes wrong, try to dispatch immediately to avoid lost signals.
            try {
                Event::dispatch($event);
            } catch (\Throwable $_) {
                // swallow — events are best-effort here
            }
        }
    }
}

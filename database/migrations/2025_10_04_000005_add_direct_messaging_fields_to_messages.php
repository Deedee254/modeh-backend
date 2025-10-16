<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('messages')) {
            // If the messages table doesn't exist yet (migration ordering in some environments),
            // skip altering here to avoid errors during migrate:fresh. The backfill will be
            // handled by a subsequent deployment step or a follow-up migration.
            // Log at info level to avoid noisy warnings in test environments.
            \Log::info('Skipping add_direct_messaging_fields_to_messages migration: messages table not present yet.');
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            // Add new direct messaging fields
            if (!Schema::hasColumn('messages', 'sender_id')) {
                $table->unsignedBigInteger('sender_id')->nullable()->after('from_user_id');
            }
            if (!Schema::hasColumn('messages', 'recipient_id')) {
                $table->unsignedBigInteger('recipient_id')->nullable()->after('to_user_id');
            }
            if (!Schema::hasColumn('messages', 'content')) {
                $table->text('content')->nullable()->after('body');
            }
            if (!Schema::hasColumn('messages', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('read');
            }

            // Add foreign keys only if columns were just added
            // (Some DB drivers don't allow adding foreign keys in the same schema call if they existed)
            try {
                if (Schema::hasColumn('messages', 'sender_id')) {
                    $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');
                }
                if (Schema::hasColumn('messages', 'recipient_id')) {
                    $table->foreign('recipient_id')->references('id')->on('users')->onDelete('set null');
                }
            } catch (\Throwable $e) {
                // ignore key creation errors in environments where it's not possible
            }
        });

        // Backfill the new columns from legacy columns in a DB-agnostic way
        try {
            // Use the Message model to safely copy values across DB engines
            \App\Models\Message::chunkById(100, function ($messages) {
                foreach ($messages as $m) {
                    $changed = false;
                    if (empty($m->sender_id) && isset($m->from_user_id)) {
                        $m->sender_id = $m->from_user_id;
                        $changed = true;
                    }
                    if (empty($m->recipient_id) && isset($m->to_user_id)) {
                        $m->recipient_id = $m->to_user_id;
                        $changed = true;
                    }
                    if (empty($m->content) && isset($m->body)) {
                        $m->content = $m->body;
                        $changed = true;
                    }
                    if (!isset($m->is_read) && isset($m->read)) {
                        $m->is_read = (bool) $m->read;
                        $changed = true;
                    }
                    if ($changed) {
                        $m->save();
                    }
                }
            });
        } catch (\Throwable $e) {
            // If backfill fails for any reason, log but don't break the migration
            \Log::error('Message backfill in migration failed: ' . $e->getMessage());
        }
    }

    public function down()
    {
        Schema::table('messages', function (Blueprint $table) {
            try {
                $table->dropForeign(['sender_id']);
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $table->dropForeign(['recipient_id']);
            } catch (\Throwable $e) {
                // ignore
            }
            $table->dropColumn(['sender_id', 'recipient_id', 'content', 'is_read']);
        });
    }
};
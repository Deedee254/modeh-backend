<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (!Schema::hasColumn('tournaments', 'sponsor_id')) {
                $table->foreignId('sponsor_id')->nullable()->after('created_by')->constrained()->onDelete('set null');
            }
            if (!Schema::hasColumn('tournaments', 'sponsor_banner')) {
                $table->string('sponsor_banner')->nullable()->after('sponsor_id');
            }
            if (!Schema::hasColumn('tournaments', 'sponsor_details')) {
                $table->json('sponsor_details')->nullable()->after('sponsor_banner');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('tournaments')) return;
        Schema::table('tournaments', function (Blueprint $table) {
            // MySQL requires dropping foreign keys/indexes before dropping columns
            try {
                if (Schema::hasColumn('tournaments', 'sponsor_id')) {
                    $table->dropForeign(['sponsor_id']);
                }
            } catch (\Throwable $e) {
                // ignore if key doesn't exist
            }

            if (Schema::hasColumn('tournaments', 'sponsor_id')) {
                try { $table->dropColumn('sponsor_id'); } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('tournaments', 'sponsor_banner')) {
                try { $table->dropColumn('sponsor_banner'); } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('tournaments', 'sponsor_details')) {
                try { $table->dropColumn('sponsor_details'); } catch (\Throwable $e) {}
            }
        });
    }
};
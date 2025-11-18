<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'owner_type')) {
                $table->string('owner_type')->nullable()->after('id');
            }
            if (!Schema::hasColumn('subscriptions', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
            }
        });

        // Migrate existing subscriptions to point to User as owner
        // Use raw statement to copy user_id into owner_id
        DB::statement("UPDATE subscriptions SET owner_type = 'App\\\\Models\\\\User', owner_id = user_id WHERE owner_id IS NULL OR owner_id = 0");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'owner_type')) {
                $table->dropColumn('owner_type');
            }
            if (Schema::hasColumn('subscriptions', 'owner_id')) {
                $table->dropColumn('owner_id');
            }
        });
    }
};

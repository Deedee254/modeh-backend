<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->foreignId('processed_by_admin_id')->nullable()->constrained('users')->nullOnDelete()->after('paid_at');
        });
    }

    public function down()
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropForeign(['processed_by_admin_id']);
            $table->dropColumn(['paid_at', 'processed_by_admin_id']);
        });
    }
};

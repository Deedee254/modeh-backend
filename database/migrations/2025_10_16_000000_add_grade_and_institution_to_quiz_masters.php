<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quiz_masters', function (Blueprint $table) {
            $table->foreignId('grade_id')->nullable()->constrained('grades')->after('user_id');
            $table->string('institution')->nullable()->after('grade_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_masters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_id');
            $table->dropColumn('institution');
        });
    }
};
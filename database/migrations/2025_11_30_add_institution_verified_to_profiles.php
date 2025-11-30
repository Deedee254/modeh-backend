<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('quizees', function (Blueprint $table) {
            $table->boolean('institution_verified')->default(false);
            $table->unsignedBigInteger('verified_institution_id')->nullable();
            $table->foreign('verified_institution_id')->references('id')->on('institutions')->onDelete('set null');
        });

        Schema::table('quiz_masters', function (Blueprint $table) {
            $table->boolean('institution_verified')->default(false);
            $table->unsignedBigInteger('verified_institution_id')->nullable();
            $table->foreign('verified_institution_id')->references('id')->on('institutions')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('quizees', function (Blueprint $table) {
            $table->dropForeign(['verified_institution_id']);
            $table->dropColumn(['institution_verified', 'verified_institution_id']);
        });

        Schema::table('quiz_masters', function (Blueprint $table) {
            $table->dropForeign(['verified_institution_id']);
            $table->dropColumn(['institution_verified', 'verified_institution_id']);
        });
    }
};

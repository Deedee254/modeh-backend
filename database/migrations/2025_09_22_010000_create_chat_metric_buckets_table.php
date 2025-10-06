<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_metric_buckets', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key')->index();
            $table->string('bucket')->index(); // e.g. 202509220930 (YYYYMMDDHHMM)
            $table->bigInteger('value')->default(0);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['metric_key', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_metric_buckets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('affiliate_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('affiliate_code');
            $table->string('source_url')->nullable();
            $table->string('target_url');
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('affiliate_code');
            $table->index('converted_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('affiliate_link_clicks');
    }
};
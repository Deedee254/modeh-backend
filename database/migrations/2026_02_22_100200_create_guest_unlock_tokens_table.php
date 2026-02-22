<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_unlock_tokens')) {
            return;
        }

        Schema::create('guest_unlock_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 120)->unique();
            $table->string('guest_identifier')->index();
            $table->string('item_type')->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->foreignId('purchase_id')->constrained('one_off_purchases')->onDelete('cascade');
            $table->uuid('guest_attempt_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_unlock_tokens');
    }
};


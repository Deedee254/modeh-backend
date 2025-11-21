<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // If the table already exists, skip creation to make this migration idempotent.
        if (Schema::hasTable('affiliate_referrals')) {
            return;
        }

        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type')->default('signup'); // signup, purchase
            $table->decimal('earnings', 12, 2)->default(0);
            $table->string('status')->default('active'); // active, expired, revoked
            $table->timestamps();

            // Ensure one referral per affiliate per user
            $table->unique(['affiliate_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};

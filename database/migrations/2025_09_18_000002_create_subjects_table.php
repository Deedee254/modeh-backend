<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('created_by')->nullable(); // tutor id
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->boolean('auto_approve')->default(false);
            // consolidated columns from later migrations
            $table->timestamp('approval_requested_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('subjects');
    }
};

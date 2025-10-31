<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('grades', function (Blueprint $table) {
            // add nullable foreign key to levels
            if (!Schema::hasColumn('grades', 'level_id')) {
                $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete()->after('id');
            }
            if (!Schema::hasColumn('grades', 'type')) {
                $table->string('type')->default('grade')->after('description');
            }
        });
    }

    public function down()
    {
        Schema::table('grades', function (Blueprint $table) {
            if (Schema::hasColumn('grades', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('grades', 'level_id')) {
                $table->dropConstrainedForeignId('level_id');
            }
        });

        Schema::dropIfExists('levels');
    }
};

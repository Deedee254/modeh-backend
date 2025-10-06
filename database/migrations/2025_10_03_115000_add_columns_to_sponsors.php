<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sponsors', 'description')) {
            Schema::table('sponsors', function (Blueprint $table) {
                $table->text('description')->nullable()->after('name');
            });
        }

        if (!Schema::hasColumn('sponsors', 'logo_url')) {
            Schema::table('sponsors', function (Blueprint $table) {
                $table->string('logo_url')->nullable()->after('description');
            });
        }

        if (!Schema::hasColumn('sponsors', 'website_url')) {
            Schema::table('sponsors', function (Blueprint $table) {
                $table->string('website_url')->nullable()->after('logo_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sponsors', function (Blueprint $table) {
            if (Schema::hasColumn('sponsors', 'website_url')) {
                $table->dropColumn('website_url');
            }
            if (Schema::hasColumn('sponsors', 'logo_url')) {
                $table->dropColumn('logo_url');
            }
            if (Schema::hasColumn('sponsors', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};

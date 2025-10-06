<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('battles', 'uuid')) {
            Schema::table('battles', function (Blueprint $table) {
                $table->string('uuid', 36)->nullable()->unique()->after('id');
            });

            // populate existing rows with uuids
            $rows = DB::table('battles')->select('id')->get();
            foreach ($rows as $r) {
                DB::table('battles')->where('id', $r->id)->update(['uuid' => (string) Str::uuid()]);
            }
        }
    }

    public function down()
    {
        if (Schema::hasColumn('battles', 'uuid')) {
            Schema::table('battles', function (Blueprint $table) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};

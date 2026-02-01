<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBillingColumnsToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Invoice email for sending invoices
            $table->string('invoice_email')->nullable()->after('email');
            // Full billing address (text to allow long addresses)
            $table->text('billing_address')->nullable()->after('invoice_email');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invoice_email', 'billing_address']);
        });
    }
}

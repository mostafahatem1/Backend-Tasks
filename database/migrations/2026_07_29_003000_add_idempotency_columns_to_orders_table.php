<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('total_amount');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['user_id', 'idempotency_key'], 'orders_user_id_idempotency_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_user_id_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};

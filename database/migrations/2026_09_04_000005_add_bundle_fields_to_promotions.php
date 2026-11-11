<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->decimal('buy_qty', 10, 2)->nullable()->after('value');
            $table->decimal('pay_qty', 10, 2)->nullable()->after('buy_qty');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['buy_qty', 'pay_qty']);
        });
    }
};

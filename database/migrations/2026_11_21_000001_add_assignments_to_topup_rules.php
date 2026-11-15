<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Las reglas de uso se asignan a cupones: por lote, por fechas o por número (o a todos). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_rules', function (Blueprint $table) {
            $table->boolean('assign_all')->default(false);
            $table->json('batch_ids')->nullable();
            $table->string('date_field', 10)->nullable(); // issued | expire
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
        });

        Schema::create('topup_rule_coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('rule_id');
            $table->string('coupon_number', 64);
            $table->primary(['rule_id', 'coupon_number']);
            $table->index('coupon_number');
        });

        // Las reglas con condiciones que ya existían aplicaban a todos los cupones: se conservan así
        DB::table('topup_rules')->where('type', 'rule')->update(['assign_all' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_rule_coupons');
        Schema::table('topup_rules', function (Blueprint $table) {
            $table->dropColumn(['assign_all', 'batch_ids', 'date_field', 'date_from', 'date_to']);
        });
    }
};

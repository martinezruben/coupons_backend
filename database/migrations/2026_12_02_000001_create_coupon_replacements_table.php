<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reemplazo de un cupón perdido: el saldo pasa a un cupón nuevo y el viejo queda anulado, previa aprobación de otro administrador. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupon_replacements')) {
            Schema::create('coupon_replacements', function (Blueprint $table) {
                $table->id();
                $table->string('coupon_type', 10);
                $table->unsignedBigInteger('old_coupon_id');
                $table->string('old_coupon_number', 64)->index();
                $table->unsignedBigInteger('new_coupon_id')->nullable();
                $table->string('new_coupon_number', 64)->nullable()->index();
                $table->decimal('balance', 12, 2);
                $table->string('reason', 255);
                $table->unsignedBigInteger('requested_by_admin_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('status', 10)->default('PENDING')->index();
                $table->unsignedBigInteger('decided_by_admin_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            foreach (['coupons.replace', 'coupon_barcodes.replace', 'coupon_replacements.view', 'coupon_replacements.approve'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de auditoría.
    }
};

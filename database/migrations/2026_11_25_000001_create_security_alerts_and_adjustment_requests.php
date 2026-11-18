<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Alertas de seguridad de cupones y solicitudes de ajuste de saldo que otro administrador debe aprobar. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupon_security_alerts')) {
            Schema::create('coupon_security_alerts', function (Blueprint $table) {
                $table->id();
                $table->string('type', 40)->index();
                $table->string('severity', 10)->index();
                $table->string('message', 255);
                $table->string('coupon_number', 64)->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('location_id')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (! Schema::hasTable('coupon_adjustment_requests')) {
            Schema::create('coupon_adjustment_requests', function (Blueprint $table) {
                $table->id();
                $table->string('coupon_type', 10);
                $table->string('coupon_number', 64)->index();
                $table->decimal('current_balance', 12, 2);
                $table->decimal('new_balance', 12, 2);
                $table->string('reason', 255);
                $table->uuid('requested_by_user_id')->nullable();
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
            foreach (['coupon_adjustments.view', 'coupon_adjustments.approve', 'coupon_security_alerts.view', 'coupon_security_alerts.resolve'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de auditoría.
    }
};

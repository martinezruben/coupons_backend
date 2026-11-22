<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cierres de caja por localidad: una foto inmutable de lo cobrado en un periodo (turno), con su detalle por cajero y surtidor. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_closings')) {
            Schema::create('cash_closings', function (Blueprint $table) {
                $table->id();
                $table->uuid('location_id')->index();
                $table->timestamp('period_from');
                $table->timestamp('period_to');
                $table->decimal('redeemed', 14, 2)->default(0);
                $table->decimal('reversed', 14, 2)->default(0);
                $table->decimal('net', 14, 2)->default(0);
                $table->decimal('topups', 14, 2)->default(0);
                $table->unsignedInteger('redemptions')->default(0);
                $table->json('details')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('closed_by_admin_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['location_id', 'period_to']);
            });
        }

        if (Schema::hasTable('permissions')) {
            foreach (['coupon_cash_close.view', 'coupon_cash_close.close', 'coupon_cash_close.export'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de auditoría.
    }
};

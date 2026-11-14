<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de cupones, clientes y órdenes de activación.
 *
 * Ciclo de vida de un cupón (`status`): GENERATED → ASSIGNED → ACTIVE, con INACTIVE (desactivado a mano)
 * y VOID (anulado). `coupon_active` se mantiene sincronizado (1 solo cuando status = ACTIVE) para que
 * el cobro, el saldo y la app sigan funcionando igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 150);
                $table->string('document', 50)->nullable()->unique();
                $table->string('email', 150)->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('coupon_batches')) {
            Schema::create('coupon_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code', 30)->unique();
                $table->string('name', 150);
                $table->string('coupon_type', 10); // QRCODE | CODBAR
                $table->unsignedInteger('quantity');
                $table->decimal('amount', 12, 2);
                $table->string('prefix', 6)->nullable();
                $table->date('expire_date')->nullable();
                // generating | ready | failed | void
                $table->string('status', 12)->default('generating')->index();
                $table->unsignedInteger('generated_count')->default(0);
                $table->text('notes')->nullable();
                $table->text('error')->nullable();
                $table->unsignedBigInteger('created_by_admin_id')->index();
                $table->timestamp('generated_at')->nullable();
                // Archivo CSV con los tokens: único lugar donde existen en claro.
                $table->string('export_path')->nullable();
                $table->timestamp('export_expires_at')->nullable();
                $table->timestamp('export_downloaded_at')->nullable();
                $table->unsignedSmallInteger('export_downloads')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('activation_orders')) {
            Schema::create('activation_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('batch_id')->index();
                $table->uuid('customer_id')->index();
                // assign: reserva para el cliente | now: activa al instante | scheduled: activa en `activate_at`
                $table->string('mode', 10);
                $table->timestamp('activate_at')->nullable()->index();
                // pending (programada) | applied | cancelled
                $table->string('status', 10)->index();
                $table->string('selection_type', 10); // all | range | quantity | list
                $table->json('selection')->nullable();
                $table->unsignedInteger('coupons_count');
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('requested_by_admin_id')->index();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by_admin_id')->nullable();
                $table->timestamps();
            });
        }

        foreach (['coupons', 'coupons_codbar'] as $name) {
            if (! Schema::hasColumn($name, 'status')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->uuid('batch_id')->nullable()->index();
                    $table->unsignedInteger('seq')->nullable();
                    $table->string('status', 10)->default('ACTIVE')->index();
                    $table->uuid('customer_id')->nullable()->index();
                    $table->uuid('activation_order_id')->nullable()->index();
                    $table->timestamp('assigned_at')->nullable();
                    $table->timestamp('activated_at')->nullable();
                    $table->index(['batch_id', 'seq']);
                });
                // Los cupones que ya existían: activos o desactivados según su bandera.
                DB::table($name)->where('coupon_active', 0)->update(['status' => 'INACTIVE']);
            }
        }

        foreach (['coupon_audit_logs'] as $name) {
            if (! Schema::hasColumn($name, 'batch_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->uuid('batch_id')->nullable()->index();
                    $table->uuid('activation_order_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de negocio.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de cupones (migrado desde couponsapi). Los IDs numéricos se conservan para poder
 * importar los datos existentes con `php artisan coupons:import-legacy`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['coupons' => true, 'coupons_codbar' => false] as $name => $hasToken) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($hasToken) {
                $table->bigIncrements('coupon_id');
                $table->string('coupon_number', 64)->unique();
                if ($hasToken) {
                    $table->string('coupon_token', 128)->nullable();
                }
                $table->boolean('coupon_active')->default(true);
                $table->date('coupon_date')->nullable();
                $table->date('coupon_expire_date')->nullable();
                $table->decimal('coupon_amount', 12, 2)->default(0);
                $table->decimal('coupon_balance', 12, 2)->default(0);
            });

            // El saldo nunca puede quedar negativo (SQLite no admite ADD CONSTRAINT).
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `{$name}` ADD CONSTRAINT `chk_{$name}_balance` CHECK (coupon_balance >= 0)");
            }
        }

        if (! Schema::hasTable('coupon_transactions')) {
            Schema::create('coupon_transactions', function (Blueprint $table) {
                $table->bigIncrements('transaction_id');
                $table->unsignedBigInteger('coupon_id')->index();
                $table->string('coupon_number', 64)->index();
                $table->decimal('transaction_amount', 12, 2); // negativo en reversas
                $table->string('coupon_type', 10); // QRCODE | CODBAR
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('device_id')->nullable()->index();
                $table->uuid('location_id')->nullable()->index();
                // Filas importadas: device_id / site_id numéricos que enviaba el cliente.
                $table->unsignedInteger('legacy_device_id')->nullable();
                $table->unsignedInteger('legacy_site_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->unsignedBigInteger('reversed_of')->nullable()->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->uuid('user_id');
                $table->string('key', 100);
                $table->string('route', 100);
                $table->string('request_hash', 64);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'key']);
            });
        }

        if (! Schema::hasTable('coupon_audit_logs')) {
            Schema::create('coupon_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('user_id')->nullable()->index();
                $table->string('action', 40)->index();
                $table->string('coupon_type', 10)->nullable();
                $table->string('coupon_number', 64)->nullable()->index();
                $table->string('old_value', 40)->nullable();
                $table->string('new_value', 40)->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: estas tablas guardan datos de negocio.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Límites por rol (null = sin límite propio; sigue valiendo el máximo del sistema).
        if (! Schema::hasTable('role_limits')) {
            Schema::create('role_limits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id')->unique();
                $table->unsignedInteger('max_activation_size')->nullable();
                $table->unsignedInteger('max_batch_quantity')->nullable();
                $table->decimal('max_batch_value', 14, 2)->nullable(); // cantidad × monto del lote
                $table->timestamps();
            });
        }

        // Alcance por cliente: 'all' ve y opera todos; 'selected' solo los de admin_user_customers.
        if (! Schema::hasColumn('admin_users', 'customer_scope')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->string('customer_scope', 10)->default('all');
            });
        }
        if (! Schema::hasTable('admin_user_customers')) {
            Schema::create('admin_user_customers', function (Blueprint $table) {
                $table->unsignedBigInteger('admin_user_id');
                $table->uuid('customer_id');
                $table->primary(['admin_user_id', 'customer_id']);
                $table->index('customer_id');
            });
        }

        // Cada movimiento recuerda a qué cliente pertenecía el cupón (para reportes y alcance).
        if (! Schema::hasColumn('coupon_transactions', 'customer_id')) {
            Schema::table('coupon_transactions', function (Blueprint $table) {
                $table->uuid('customer_id')->nullable()->index();
            });
            foreach (['QRCODE' => 'coupons', 'CODBAR' => 'coupons_codbar'] as $type => $coupons) {
                DB::statement("UPDATE coupon_transactions SET customer_id = (SELECT {$coupons}.customer_id FROM {$coupons} WHERE {$coupons}.coupon_id = coupon_transactions.coupon_id) WHERE coupon_type = '{$type}'");
            }
        }

        // Evita que dos workers generen el mismo lote si el job se reintenta.
        if (! Schema::hasColumn('coupon_batches', 'generation_started_at')) {
            Schema::table('coupon_batches', function (Blueprint $table) {
                $table->timestamp('generation_started_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de negocio.
    }
};

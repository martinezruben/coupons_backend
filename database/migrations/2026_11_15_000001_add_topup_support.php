<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipo de movimiento: REDEEM (cobro), TOPUP (recarga), REVERSAL (reversa de un cobro).
        if (! Schema::hasColumn('coupon_transactions', 'kind')) {
            Schema::table('coupon_transactions', function (Blueprint $table) {
                $table->string('kind', 10)->default('REDEEM')->index();
            });
            DB::table('coupon_transactions')->whereNotNull('reversed_of')->update(['kind' => 'REVERSAL']);
        }

        if (! Schema::hasTable('topup_rules')) {
            Schema::create('topup_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                // location: solo se puede recargar en esas localidades
                // weekday: solo se puede recargar en esos días de la semana
                // daily_amount: tope de recargas por cupón y por día
                $table->string('type', 20)->index();
                $table->string('coupon_type', 10)->nullable(); // QRCODE | CODBAR | null = ambos
                $table->uuid('location_id')->nullable();
                $table->string('weekdays', 20)->nullable(); // ISO: 1 = lunes … 7 = domingo, "1,2,3"
                $table->decimal('max_daily_amount', 12, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de negocio.
    }
};

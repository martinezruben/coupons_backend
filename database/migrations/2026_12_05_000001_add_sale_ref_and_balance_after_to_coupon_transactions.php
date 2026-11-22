<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_transactions', function (Blueprint $table) {
            // Agrupa los movimientos de una misma venta (un cobro repartido en varios cupones). Es la Idempotency-Key
            // de la app cuando la envía, así la app puede recuperar una venta cuya respuesta se perdió.
            if (! Schema::hasColumn('coupon_transactions', 'sale_ref')) {
                $table->string('sale_ref', 100)->nullable()->index();
            }
            // Saldo del cupón justo después del movimiento (para reimprimir el recibo con el saldo de ese momento)
            if (! Schema::hasColumn('coupon_transactions', 'balance_after')) {
                $table->decimal('balance_after', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intencionalmente vacío.
    }
};

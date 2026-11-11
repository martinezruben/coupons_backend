<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->after('price');
        });

        // Costo unitario congelado al sincronizar la venta: cambiar el costo del producto después
        // no reescribe el margen de ventas pasadas. Null = el producto no tenía costo entonces.
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

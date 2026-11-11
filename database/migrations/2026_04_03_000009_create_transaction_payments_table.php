<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->string('payment_method', 30);
            $table->decimal('amount', 10, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions');
            $table->index(['transaction_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_payments');
    }
};

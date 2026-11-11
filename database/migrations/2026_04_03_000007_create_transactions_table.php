<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id', 100);
            $table->uuid('location_id');
            $table->uuid('device_id');
            $table->string('shift_id', 100)->nullable();
            $table->uuid('user_id');
            $table->unsignedInteger('turn_number');
            $table->enum('status', ['PENDING', 'PAID', 'VOIDED'])->default('PAID');
            $table->decimal('total', 12, 2);
            $table->timestamp('occurred_at');
            $table->boolean('is_synced')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->foreign('user_id')->references('id')->on('users');

            $table->unique(['device_id', 'external_id']);
            $table->index(['location_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
            $table->index(['is_synced', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

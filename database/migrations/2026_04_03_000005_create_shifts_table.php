<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id');
            $table->uuid('device_id');
            $table->uuid('user_id');
            $table->unsignedInteger('shift_number');
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->decimal('opening_balance', 10, 2)->nullable();
            $table->decimal('closing_balance', 10, 2)->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['location_id', 'shift_number']);
            $table->index(['location_id', 'device_id', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};

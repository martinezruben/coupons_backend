<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('location_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->enum('operation', ['PUSH', 'PULL']);
            $table->string('entity', 80);
            $table->unsignedInteger('records_count')->default(0);
            $table->enum('status', ['SUCCESS', 'FAILED'])->default('SUCCESS');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('device_id')->references('id')->on('devices');
            $table->index(['device_id', 'operation', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('method', 12);
            $table->string('path', 255);
            $table->longText('parameters')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->text('response_summary')->nullable();
            $table->uuid('location_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('device_fingerprint', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->index(['created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index('response_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};

<?php

/**
 * Tabla de secuencias NCF.
 * Soporta Ecuador (01/04/05/07) y República Dominicana (E31/E32/E33/E34).
 * Cada tipo tiene su contador cuando mode=by_location (por localidad)
 * o location_id=NULL cuando mode=global.
 *
 * Nota: transactions.ncf se agrega al final (no after 'pos_number' porque
 * esa columna no existe en la tabla transaccional base).
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ncf_sequences')) {
            Schema::create('ncf_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('type', 6)->comment('01/04/05/07 EC | E31/E32/E33/E34 DO');
                $table->uuid('location_id')->nullable()->comment('NULL cuando ncf.mode=global');
                $table->string('establishment', 3)->default('001');
                $table->unsignedInteger('start')->default(1);
                $table->unsignedInteger('end')->default(999999999);
                $table->unsignedInteger('current')->default(1);
                $table->timestamps();

                $table->unique(['type', 'location_id']);
                $table->index(['type', 'location_id', 'current']);
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('transactions', 'ncf')) {
                    $table->string('ncf', 18)->nullable();
                }
                if (! Schema::hasColumn('transactions', 'ncf_type')) {
                    $table->string('ncf_type', 6)->nullable();
                }
            });
        }

        // notifications (ya existe la tabla original de Pulse, skip si existe)
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('driver')->default('database');
                $table->morphs('notifiable');
                $table->string('title');
                $table->text('message');
                $table->string('level')->default('warning');
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['ncf', 'ncf_type']);
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('ncf_sequences');
    }
};

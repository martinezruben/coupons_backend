<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de prefijos para los números de cupón
        Schema::create('coupon_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 6)->unique();
            $table->string('description', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Los prefijos que ya se usaron en lotes pasan al catálogo
        DB::table('coupon_batches')->whereNotNull('prefix')->distinct()->pluck('prefix')->each(function ($prefix) {
            DB::table('coupon_prefixes')->insertOrIgnore(['prefix' => strtoupper($prefix), 'description' => 'Importado de lotes existentes', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });

        // Bitácora completa: cada visita y cada acción del panel, con ruta, método, resumen y datos enviados
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->string('entity_id', 64)->nullable()->change(); // ids numéricos y uuid
            $table->string('route', 120)->nullable()->index()->after('ip');
            $table->string('method', 10)->nullable()->after('route');
            $table->string('url', 500)->nullable()->after('method');
            $table->string('summary', 255)->nullable()->after('url');
            $table->json('input')->nullable()->after('summary');
            $table->string('user_agent', 255)->nullable()->after('input');
            $table->unsignedSmallInteger('status_code')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['route']);
            $table->dropColumn(['route', 'method', 'url', 'summary', 'input', 'user_agent', 'status_code']);
        });
        Schema::dropIfExists('coupon_prefixes');
    }
};

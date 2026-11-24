<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos por localidad. Cada localidad tiene siempre un turno abierto; cerrarlo fija la hora de corte (hasta ahí entra
 * en este turno) y abre el siguiente a partir de esa hora. Cada movimiento (`coupon_transactions.shift_id`) queda
 * almacenado en su turno. Los cierres de caja anteriores (`cash_closings`) pasan a ser turnos cerrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table) {
                $table->id();
                $table->uuid('location_id')->index();
                $table->unsignedInteger('number');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->string('status', 10)->default('open')->index(); // open | closed
                $table->unsignedBigInteger('closed_by_admin_id')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->decimal('redeemed', 14, 2)->default(0);
                $table->decimal('reversed', 14, 2)->default(0);
                $table->decimal('net', 14, 2)->default(0);
                $table->decimal('topups', 14, 2)->default(0);
                $table->unsignedInteger('redemptions')->default(0);
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['location_id', 'number']);
            });
        }

        if (! Schema::hasColumn('coupon_transactions', 'shift_id')) {
            Schema::table('coupon_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('shift_id')->nullable()->index();
            });
        }

        // Los cierres de caja que ya existían son los primeros turnos cerrados de su localidad
        if (Schema::hasTable('cash_closings') && DB::table('shifts')->count() === 0) {
            $numbers = [];
            foreach (DB::table('cash_closings')->orderBy('period_to')->orderBy('id')->get() as $c) {
                $n = $numbers[$c->location_id] = ($numbers[$c->location_id] ?? 0) + 1;
                $id = DB::table('shifts')->insertGetId([
                    'location_id' => $c->location_id, 'number' => $n, 'starts_at' => $c->period_from, 'ends_at' => $c->period_to, 'status' => 'closed',
                    'closed_by_admin_id' => $c->closed_by_admin_id, 'closed_at' => $c->created_at, 'note' => $c->note,
                    'redeemed' => $c->redeemed, 'reversed' => $c->reversed, 'net' => $c->net, 'topups' => $c->topups, 'redemptions' => $c->redemptions,
                    'details' => $c->details, 'created_at' => $c->created_at,
                ]);
                DB::table('coupon_transactions')->where('location_id', $c->location_id)->whereNull('shift_id')
                    ->where('created_at', '>=', Carbon::parse($c->period_from))->where('created_at', '<', Carbon::parse($c->period_to))->update(['shift_id' => $id]);
            }
        }

        if (Schema::hasTable('permissions')) {
            // Mismo permiso de siempre (coupon_cash_close.*): quien podía cerrar caja ahora cierra turnos
            foreach (['coupon_cash_close.view', 'coupon_cash_close.close', 'coupon_cash_close.export'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: guarda datos de auditoría.
    }
};

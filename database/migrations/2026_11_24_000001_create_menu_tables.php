<?php

use App\Support\MenuStructure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Menú del panel administrable: grupos (padres) y opciones (hijos), sembrados con el menú actual. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 60);
            $table->string('icon', 60)->default('squares-2x2');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_group_id')->constrained('menu_groups')->cascadeOnDelete();
            $table->string('kind', 10); // screen | special | link
            $table->string('ref', 60)->nullable(); // clave de la pantalla o del acceso especial
            $table->string('label', 60)->nullable(); // vacío = el nombre original
            $table->string('icon', 60)->nullable();  // vacío = el icono original
            $table->string('url', 500)->nullable(); // solo enlaces
            $table->string('permission', 100)->nullable(); // solo enlaces: permiso para verlo
            $table->boolean('new_tab')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->index(['menu_group_id', 'position']);
        });

        MenuStructure::saveStructure(MenuStructure::defaults());
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_groups');
    }
};

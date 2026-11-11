<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

abstract class TestCase extends BaseTestCase
{
    /**
     * Los tests corren en America/Santo_Domingo (GMT-4, sin DST), igual que
     * producción. Se fuerza a nivel PHP + config porque el entrypoint de
     * Docker puede cargar un .env con otra zona que anule phpunit.xml.
     */
    protected function setUp(): void
    {
        // Las variables DB_* del entorno (p. ej. una BD real) NO deben llegar a los tests:
        // RefreshDatabase borra tablas. Se fuerza SQLite en memoria antes de arrancar la app.
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''] as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::setUp();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('Los tests solo pueden correr contra SQLite en memoria.');
        }

        // Fuerza zona horaria UTC para tests (consistente con phpunit.xml)
        date_default_timezone_set('America/Santo_Domingo');
        config(['app.timezone' => 'America/Santo_Domingo']);
        Date::setTestNow(now());

        // Limpia cualquier config cache generado por entrypoint
        Artisan::call('config:clear');
        Artisan::call('optimize:clear');

        // Excluye todas las rutas de CSRF
        PreventRequestForgery::except(['*']);
    }
}

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
        parent::setUp();

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

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="0;url=/admin">
        <title>{{ config('app.name', 'Tap&Go') }}</title>
        <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
                   font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: #f8fafc; color: #334155; }
            .card { text-align: center; padding: 2.5rem 3rem; background: #fff; border: 1px solid #e2e8f0;
                    border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
            h1 { margin: 0 0 .25rem; font-size: 1.25rem; color: #0f172a; }
            p { margin: 0 0 1.25rem; font-size: .875rem; color: #64748b; }
            a { display: inline-block; padding: .5rem 1.25rem; background: #4f46e5; color: #fff; font-size: .875rem;
                font-weight: 600; border-radius: 8px; text-decoration: none; }
            a:hover { background: #4338ca; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>{{ config('app.name', 'Tap&Go') }} — Panel de administración</h1>
            <p>Si no eres redirigido automáticamente, entra al panel:</p>
            <a href="/admin">Ir al panel</a>
        </div>
    </body>
</html>

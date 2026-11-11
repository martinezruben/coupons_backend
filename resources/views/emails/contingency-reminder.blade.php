<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f59e0b; color: white; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .header h1 { margin: 0; }
        .content { background-color: #f9fafb; padding: 20px; border-radius: 4px; }
        .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        .detail { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #f59e0b; }
        .highlight { background-color: #fef3c7; padding: 15px; border-radius: 4px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏰ Recordatorio de Contingencia</h1>
            <p>El sitio {{ $location->name }} continúa en contingencia</p>
        </div>

        <div class="content">
            <p>Estimado equipo,</p>

            <p>Se recuerda que el siguiente sitio continúa en contingencia:</p>

            <div class="detail">
                <strong>Sitio:</strong> {{ $location->name }}<br>
                <strong>Dirección:</strong> {{ $location->address ?? 'No especificada' }}<br>
                <strong>En contingencia desde:</strong> {{ $location->contingency_started_at->format('d/m/Y H:i:s') }}<br>
                <strong>Tiempo transcurrido:</strong> {{ $hoursInContingency }} hora(s)<br>
                <strong>Zona Horaria:</strong> {{ config('app.timezone') }}
            </div>

            <div class="highlight">
                <strong>⚠️ Acción requerida:</strong> El sitio sigue en contingencia. Por favor verifique el estado y tome las acciones necesarias para resolverla.
            </div>

            <p>Continuaremos enviando recordatorios cada cierto tiempo hasta que la contingencia sea resuelta.</p>

            <p style="margin-top: 30px;">
                Saludos,<br>
                Sistema Tap&Go
            </p>
        </div>

        <div class="footer">
            <p>Este es un correo automático. Por favor no responda directamente a este mensaje.</p>
            <p>Si tiene preguntas, comuníquese con el equipo de administración.</p>
        </div>
    </div>
</body>
</html>

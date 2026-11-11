<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #dc2626; color: white; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .header h1 { margin: 0; }
        .content { background-color: #f9fafb; padding: 20px; border-radius: 4px; }
        .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        .detail { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #dc2626; }
        .btn { display: inline-block; background-color: #2563eb; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 Entrada en Contingencia</h1>
            <p>El sitio {{ $location->name }} ha entrado en contingencia</p>
        </div>

        <div class="content">
            <p>Estimado equipo,</p>

            <p>Se notifica que el siguiente sitio ha entrado en contingencia:</p>

            <div class="detail">
                <strong>Sitio:</strong> {{ $location->name }}<br>
                <strong>Dirección:</strong> {{ $location->address ?? 'No especificada' }}<br>
                <strong>Fecha y Hora:</strong> {{ $activatedAt->format('d/m/Y H:i:s') }}<br>
                <strong>Zona Horaria:</strong> {{ config('app.timezone') }}
            </div>

            <p><strong>Acción requerida:</strong> Por favor, tome las acciones necesarias para resolver la contingencia lo antes posible.</p>

            <p>Recibirá recordatorios periódicos sobre el estado de esta contingencia.</p>

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

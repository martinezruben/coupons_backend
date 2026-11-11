<?php

namespace App\Services;

use App\Models\SystemParameter;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class MailConfigurationService
{
    protected SystemParameter $settings;

    public function __construct()
    {
        $this->settings = SystemParameter::current();
    }

    public function getActiveDriver(): string
    {
        return $this->settings->mail_driver ?? 'smtp';
    }

    public function getMailFromAddress(): ?string
    {
        return $this->settings->mail_from_address;
    }

    public function getMailFromName(): ?string
    {
        return $this->settings->mail_from_name;
    }

    public function getSmtpConfig(): array
    {
        return [
            'host' => $this->settings->smtp_host,
            'port' => $this->settings->smtp_port,
            'username' => $this->settings->smtp_username,
            'password' => $this->settings->smtp_password,
            'encryption' => $this->settings->smtp_encryption,
        ];
    }

    public function getOffice365Config(): array
    {
        return [
            'tenant_id' => $this->settings->office365_tenant_id,
            'client_id' => $this->settings->office365_client_id,
            'client_secret' => $this->settings->office365_client_secret,
            'scopes' => $this->settings->office365_scopes ?? [],
        ];
    }

    public function validateSmtpConnection(): array
    {
        try {
            $config = $this->getSmtpConfig();

            if (! $config['host'] || ! $config['port'] || ! $config['username'] || ! $config['password']) {
                return ['success' => false, 'message' => 'Credenciales SMTP incompletas'];
            }

            // Symfony Mailer (SwiftMailer ya no existe en Laravel 13): conecta y autentica
            $transport = new EsmtpTransport($config['host'], (int) $config['port'], $config['encryption'] === 'ssl');
            $transport->setUsername((string) $config['username']);
            $transport->setPassword((string) $config['password']);
            $transport->start();
            $transport->stop();

            return ['success' => true, 'message' => 'Conexión SMTP válida'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error de conexión SMTP: '.$e->getMessage()];
        }
    }

    public function validateOffice365Connection(): array
    {
        try {
            $config = $this->getOffice365Config();

            if (! $config['tenant_id'] || ! $config['client_id'] || ! $config['client_secret']) {
                return ['success' => false, 'message' => 'Credenciales Office 365 incompletas'];
            }

            // In a real implementation, you would call Microsoft Graph API
            // to validate the token can be obtained. For now, just validate fields.
            return ['success' => true, 'message' => 'Credenciales Office 365 válidas (validación básica)'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error de validación Office 365: '.$e->getMessage()];
        }
    }

    public function testEmail(string $toAddress): array
    {
        try {
            $driver = $this->getActiveDriver();

            if ($driver === 'smtp') {
                return $this->testSmtpEmail($toAddress);
            } elseif ($driver === '365') {
                return $this->testOffice365Email($toAddress);
            }

            return ['success' => false, 'message' => 'Driver de correo no soportado'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error al enviar correo de prueba: '.$e->getMessage()];
        }
    }

    /**
     * Configura el mailer de Laravel con el SMTP de «Parámetros del sistema»,
     * para que los correos (contingencia, pruebas) salgan por ahí y no por el
     * mailer del .env. Devuelve false si el SMTP no está completo o el driver
     * elegido es Microsoft 365, que aún no tiene envío implementado.
     */
    public function applyToMailer(): bool
    {
        if ($this->getActiveDriver() !== 'smtp') {
            Log::warning('Correo: el driver Microsoft 365 no tiene envío implementado; se usa el mailer del .env.');

            return false;
        }

        $config = $this->getSmtpConfig();
        if (! $config['host'] || ! $config['port']) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                // ssl = SMTPS (465); tls o vacío = SMTP con STARTTLS si el servidor lo ofrece
                'scheme' => $config['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $config['host'],
                'port' => (int) $config['port'],
                'username' => $config['username'],
                'password' => $config['password'],
                'timeout' => 20,
            ],
            'mail.from.address' => $this->getMailFromAddress() ?: $config['username'],
            'mail.from.name' => $this->getMailFromName() ?: config('app.name'),
        ]);
        Mail::purge('smtp');

        return true;
    }

    private function testSmtpEmail(string $toAddress): array
    {
        try {
            if (! $this->applyToMailer()) {
                return ['success' => false, 'message' => 'Configuración SMTP incompleta'];
            }

            Mail::raw('Este es un correo de prueba de la configuración SMTP de Tap&Go.', function ($message) use ($toAddress): void {
                $message->to($toAddress)->subject('Prueba de conexión - Tap&Go');
            });

            return ['success' => true, 'message' => "Correo de prueba enviado a {$toAddress}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error al enviar correo SMTP: '.$e->getMessage()];
        }
    }

    private function testOffice365Email(string $toAddress): array
    {
        // This would require proper Office 365 / Microsoft Graph integration
        // For now, return a placeholder response
        return [
            'success' => false,
            'message' => 'Prueba de Office 365 requiere configuración adicional de Microsoft Graph SDK',
        ];
    }
}

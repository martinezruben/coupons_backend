<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemParameter;
use App\Services\MailConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PanelMailerTest extends TestCase
{
    use RefreshDatabase;

    private function smtpSettings(array $overrides = []): void
    {
        SystemParameter::current()->update(array_merge([
            'mail_driver' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_username' => 'alertas@example.com',
            'smtp_password' => 'secreto',
            'smtp_encryption' => 'ssl',
            'mail_from_address' => 'alertas@example.com',
            'mail_from_name' => 'Tap&Go',
        ], $overrides));
    }

    public function test_panel_smtp_settings_become_the_default_mailer(): void
    {
        config(['mail.default' => 'log']);
        $this->smtpSettings();

        $this->assertTrue(app(MailConfigurationService::class)->applyToMailer());

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('alertas@example.com', config('mail.from.address'));
    }

    public function test_incomplete_smtp_or_office365_keeps_env_mailer(): void
    {
        config(['mail.default' => 'log']);

        $this->smtpSettings(['smtp_host' => null]);
        $this->assertFalse(app(MailConfigurationService::class)->applyToMailer());

        $this->smtpSettings(['mail_driver' => '365']);
        $this->assertFalse(app(MailConfigurationService::class)->applyToMailer());

        $this->assertSame('log', config('mail.default'));
    }

    public function test_test_email_button_sends_with_system_settings_permission(): void
    {
        Mail::fake();
        $this->smtpSettings();
        Permission::findOrCreate('system_settings.edit', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('system_settings.edit');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.mail.test'), ['to_address' => 'destino@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}

<?php

namespace Tests\Feature;

use App\Models\SystemParameter;
use App\Services\MailConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemParameter::factory()->create([
            'mail_driver' => 'smtp',
            'mail_from_address' => 'noreply@tapandgo.local',
            'mail_from_name' => 'Tap&Go',
            'smtp_host' => null,
            'smtp_port' => null,
            'smtp_username' => null,
            'smtp_password' => null,
            'smtp_encryption' => null,
        ]);
    }

    public function test_system_parameter_model_has_mail_fields()
    {
        $param = SystemParameter::find(1);

        $this->assertNotNull($param);
        $this->assertNotNull($param->mail_driver);
        $this->assertIsString($param->mail_driver);
    }

    public function test_mail_driver_can_be_updated_directly()
    {
        $param = SystemParameter::find(1);
        $param->update(['mail_driver' => '365']);

        $this->assertDatabaseHas('system_parameters', [
            'id' => 1,
            'mail_driver' => '365',
        ]);
    }

    public function test_smtp_configuration_can_be_saved_directly()
    {
        $param = SystemParameter::find(1);
        $param->update([
            'mail_driver' => 'smtp',
            'mail_from_address' => 'test@example.com',
            'mail_from_name' => 'Test Sender',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_username' => 'test@gmail.com',
            'smtp_password' => 'secretpass',
            'smtp_encryption' => 'tls',
        ]);

        $this->assertDatabaseHas('system_parameters', [
            'id' => 1,
            'mail_driver' => 'smtp',
            'mail_from_address' => 'test@example.com',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
        ]);
    }

    public function test_office365_configuration_can_be_saved_directly()
    {
        $param = SystemParameter::find(1);
        $param->update([
            'mail_driver' => '365',
            'office365_tenant_id' => 'tenant-123',
            'office365_client_id' => 'client-456',
            'office365_client_secret' => 'secret-789',
        ]);

        $this->assertDatabaseHas('system_parameters', [
            'id' => 1,
            'mail_driver' => '365',
            'office365_tenant_id' => 'tenant-123',
        ]);
    }

    public function test_sensitive_fields_are_encrypted()
    {
        $param = SystemParameter::find(1);
        $param->update([
            'mail_driver' => 'smtp',
            'smtp_password' => 'mypassword123',
            'office365_client_secret' => 'mysecret456',
        ]);

        $refreshed = SystemParameter::find(1);

        // Values should be encrypted in database
        $this->assertNotEquals('mypassword123', $refreshed->getRawOriginal('smtp_password'));
        $this->assertNotEquals('mysecret456', $refreshed->getRawOriginal('office365_client_secret'));

        // But accessible decrypted through model
        $this->assertEquals('mypassword123', $refreshed->smtp_password);
        $this->assertEquals('mysecret456', $refreshed->office365_client_secret);
    }

    public function test_mail_configuration_service_validates_smtp()
    {
        $service = new MailConfigurationService;
        $result = $service->validateSmtpConnection();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
    }

    public function test_mail_configuration_service_validates_office365()
    {
        $service = new MailConfigurationService;
        $result = $service->validateOffice365Connection();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }
}

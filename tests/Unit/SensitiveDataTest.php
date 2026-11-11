<?php

namespace Tests\Unit;

use App\Support\SensitiveData;
use PHPUnit\Framework\TestCase;

class SensitiveDataTest extends TestCase
{
    public function test_masks_credentials_in_any_naming_style_and_depth(): void
    {
        $masked = SensitiveData::mask([
            'license_key' => 'abc',
            'licenseKey' => 'abc',
            'token' => 'Bearer x',
            'pairingToken' => 'p',
            'users' => [['username' => 'caja1', 'pin' => 'h1', 'pin4' => 'h2', 'pinSha384' => 'h3']],
            'shipping' => 'keep',
            'device_fingerprint' => 'fp-1',
        ]);

        $this->assertSame('***', $masked['license_key']);
        $this->assertSame('***', $masked['licenseKey']);
        $this->assertSame('***', $masked['token']);
        $this->assertSame('***', $masked['pairingToken']);
        $this->assertSame(['username' => 'caja1', 'pin' => '***', 'pin4' => '***', 'pinSha384' => '***'], $masked['users'][0]);
        $this->assertSame('keep', $masked['shipping']);
        $this->assertSame('fp-1', $masked['device_fingerprint']);
    }

    public function test_mask_json_masks_before_truncating(): void
    {
        $json = json_encode(['padding' => str_repeat('x', 3000), 'token' => 'secret-token']);

        $out = SensitiveData::maskJson($json, 2000);

        $this->assertStringNotContainsString('secret-token', $out);
        $this->assertSame('…', mb_substr($out, -1));
        $this->assertNull(SensitiveData::maskJson('', 2000));
        $this->assertSame('[respuesta no JSON no guardada]', SensitiveData::maskJson('<html>token=abc</html>', 2000));
    }
}

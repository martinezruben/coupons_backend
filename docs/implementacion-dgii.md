# 📋 Plan Integración DGII — Facturación Electrónica 🇨🇩🇴

**Modelo:** GLM-5.2-High  
**País:** República Dominicana (Dominicidad)  
**Objetivo:** Envío de CFE (Comprobante Fiscal Electrónico) al DGII vía XML firmado + Mutual TLS.

---

## 🔍 1. Diagnóstico GLM

> *"La integración DGII República Dominicana requiere:
> - Firma XML con certificado electrónico `.p12` + PIN  
> - Endpoint QA `https://dgii-cfe-qa.appspot.com/SFSEnvioCFE` (prod: `https://dgii-cfe-production.appspot.com/SFSEnvioCFE`)  
> - XML con CFE completo (Encabezado, Detalle, Impuestos/ITBIS, Tributos, Totales)  
> - Autenticación mutual TLS (certificado + clave privada)  
> - Parseo de respuestas XML (`EnvioExitoso` vs `EnvioError`) con códigos de error DGII  
> - Trackeo de estado: PENDING → PROCESSING → SENT/REJECTED/ERROR  
> - Reintentos async vía queue + backoff exponencial"*

---

## 📌 2. Estado actual del POS TapGo

| Modelo | Campos disponibles | Observación |
|---|---|---|
| `transactions` | `external_id`, `location_id`, `device_id`, `user_id`, `turn_number`, `status`, `total`, `taxes`, `ncf_type`, `ncf` | ✅ NCF ya implementado |
| `transaction_items` | `product_sku`, `product_name`, `qty`, `unit_price`, `discount`, `tax`, `line_total` | ✅ Mapeable |
| `locations` | `name`, `address`, `latitude`, `longitude` | ✅ RNC Sucursal |
| `users` | `username`, `full_name`, `role` | ✅ Responsable factura |
| `transaction_payments` | `payment_method` (CASH/CARD/TRANSFER) | ✅ FormaPago → código DGII |

---

## 📡 3. Endpoints DGII

| Ambiente | URL | Certificado |
|---|---|---|
| **QA / Testing** | `https://dgii-cfe-qa.appspot.com/SFSEnvioCFE` | Certificado DGII QA |
| **Producción** | `https://dgii-cfe-production.appspot.com/SFSEnvioCFE` | Certificado `.p12` vigente |

---

## 📄 4. Estructura CFE (XML esqueleto)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<EnvioCFE Version="1.0" xmlns="DGII-CERTO":
  <DocumentoCFE>
    <Encabezado>
      <RNCemisor>123456789</RNCemisor>
      <TipoCFE>E31</TipoCFE>
      <SecuenciaCFE>001000000001</SecuenciaCFE>
      <FechaEmision>2026-08-23T14:30:00</FechaEmision>
      <RNCComprador>123456789</RNCComprador>
      <NombreComprador>Ejemplo RD</NombreComprador>
      <Moneda>DOP</Moneda>
      <TipoCambio>1.0000</TipoCambio>
    </Encabezado>

    <Detalle>
      <Item>
        <Codigo>P-CAFE-001</Codigo>
        <Descripcion>Café Americano</Descripcion>
        <UnidadMedida>UND</UnidadMedida>
        <Cantidad>1</Cantidad>
        <PrecioUnitario>2.50</PrecioUnitario>
        <Descuento>0.00</Descuento>
        <ITBIS>0.30</ITBIS>
        <MontoItem>2.80</MontoItem>
      </Item>
    </Detalle>

    <Tributos>
      <ITBIS>0.30</ITBIS>
    </Tributos>

    <Totales>
      <TotalNeto>2.50</TotalNeto>
      <TotalITBIS>0.30</TotalITBIS>
      <TotalGeneral>2.80</TotalGeneral>
    </Totales>

    <FormaPago>
      <Codigo>1</Codigo>   <!-- 1=Efectivo, 2=Tarjeta, 3=Cheque -->
      <Referencia>CARD-9981</Referencia>
    </FormaPago>

    <Observaciones>Factura emitida por POS TapGo</Observaciones>
  </DocumentoCFE>
</EnvioCFE>
```

---

## 🔐 5. Autenticación (PKI / Firma Electrónica)

### 5.1 Certificado
```bash
# Archivo .p12 exportado del SAT/DGII
/app/certs/tapgo-cer.p12         # Posición 1: certificado + clave
/app/certs/tapgo-key.pem         # Extracción clave privada
/app/certs/tapgo-cert.pem        # Extracción certificado público
```

### 5.2 Firma XML (PHP)
```php
use RobRichards\XMLSec\XMLSecEnc;
use XMLSec\Namespaces;
use XMLSec\Utils;

function signXmlWithP12(string $xml, string $p12_path, string $p12_pin): string {
    $pkcs12 = file_get_contents($p12_path);
    openssl_pkcs12_read($pkcs12, $certs, $p12_pin);
    $privateKey = $certs['pkey'];
    $cert = $certs['cert'];

    $doc = new DOMDocument();
    $doc->loadXML($xml);

    // Crear firma enveloping PKCS7/CMS (DGII acepta PKCS7)
    $signed = '';
    openssl_pkcs7_sign(
        $xml,
        $signed,
        'file://' . $p12_path,
        [['email' => 'factura@tapgo.com'], $p12_pin],
        [],
        'base64'
    );

    return $signed;
}
```

---

## 🧱 6. Arquitectura técnica

### 6.1 Migration — campos DGII en `transactions`
```php
Schema::table('transactions', function (Blueprint $table) {
    $table->string('dgii_status', 20)->default('PENDING')->after('ncf_type');
    $table->text('dgii_response_xml')->nullable()->after('dgii_status');
    $table->string('dgii_cfe_number', 30)->nullable();
    $table->timestamp('dgii_sent_at')->nullable();
    $table->index(['dgii_status', 'dgii_sent_at']);
});
```

### 6.2 Service — `app/Services/DgiiCfeService.php`
```php
class DgiiCfeService {
    public function generateXml(Transaction $tx): string;      // builds XML
    public function signXml(string $xml, string $signed): string;  // PKCS7
    public function send(string $signedXml, string $endpoint): array;
    public function parseResponse(string $xml): array;
}
```

### 6.3 Enum — `app/Models/CfeStatus.php`
```php
enum CfeStatus: string {
    case PENDING    = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SENT       = 'SENT';
    case REJECTED   = 'REJECTED';
    case ERROR      = 'ERROR';
}
```

### 6.4 Job — `app/Jobs/SendCfeToDgii.php`
```php
class SendCfeToDgii implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public array $backoff = [5, 10, 20];  // segundos exponencial
    public function handle(DgiiCfeService $service) { ... }
    public function failed(Transaction $tx, Throwable $e) { ... }
}
```

### 6.5 Eventos
```php
event(new App\Events\CfeSent($tx));
event(new App\Events\CfeRejected($tx, $error));
```

---

## ⚙️ 7. Configuración `.env`

```env
# === DGII República Dominicana ===
DGII_ENABLED=true                # ON/OFF global (solo para country=DO)
DGII_ENV=qa                      # qa | production
DGII_RNC_EMISOR=123456789        # RNC empresa (8-11 dígitos)
DGII_CERT_PATH=/app/certs/tapgo-cer.p12
DGII_CERT_PASS=*** PIN del .p12
DGII_TIMEOUT=10                  # segundos
DGII_RETRY_ATTEMPTS=3
```

### `config/dgii.php`
```php
return [
    'enabled' => env('DGII_ENABLED', false),
    'env'     => env('DGII_ENV', 'qa'),
    'rnc'     => env('DGII_RNC_EMISOR'),
    'cert'    => [
        'path' => env('DGII_CERT_PATH'),
        'pass' => env('DGII_CERT_PASS'),
    ],
    'timeout'      => (int) env('DGII_TIMEOUT', 10),
    'retry_attempts' => (int) env('DGII_RETRY_ATTEMPTS', 3),
    'endpoints' => [
        'qa'         => 'https://dgii-cfe-qa.appspot.com/SFSEnvioCFE',
        'production' => 'https://dgii-cfe-production.appspot.com/SFSEnvioCFE',
    ],
];
```

---

## 🔄 8. Flujo completo (Sequence)

```
POS Android → SyncController@push → Transaction::create()
   ↓
[DB commit] → event CfeReady($tx)
   ↓
Listen → dispatch(SendCfeToDgii job)  [job queue: 'dgii', retry=3]
   ↓
SendCfeToDgii::handle()
   ├── DgiiCfeService::generateXml($tx)
   ├── DgiiCfeService::signXml($xml, cert)
   ├── HTTP POST → DGII endpoint (MutualTLS, 10s timeout)
   ├── parse XML response
   │   ├─ SUCCESS:  tx.dgii_status='SENT' + dgii_sent_at + CFE number
   │   └─ ERROR:    tx.dgii_status='REJECTED' + dgii_response_xml + message
   ├── emit events (CfeSent / CfeRejected)
   └── retry si failure + intentos < 3
```

---

## ✅ 9. Validaciones de dominio

| Validación | Implementación |
|---|---|
| RNC emisor 8-11 chars numérico | `regex:/^[0-9]{8,11}$/` |
| Tipo CFE ∈ {E31,E32,E33,E34} | NcfService valida |
| ITBIS = `line_total` × 0.18 | Recalcular XML |
| Total = suma ítems + ITBIS | Assert antes envío |
| Certificado vigente | `openssl_x509_parse` chequeo `expire` |
| XML parseable | `simplexml_load_string` + XSD (opcional) |

---

## 🧪 10. Tests (TDD)

```
tests/Feature/DgiiCfeTest.php:
  - test_generate_cfe_xml_structure       // encabezado + item + totales
  - test_sign_xml_with_p12                // PKCS7 contiene <Signature>
  - test_parse_success_response           // EnvioExitoso → SENT
  - test_parse_error_response             // EnvioError → REJECTED + msg
  - test_send_cfe_updates_transaction     // job → transacción dgii_status
  - test_toggle_dgii_disabled_skips_send  // DGII_ENABLED=false → no job
```

---

## 📦 11. Dependencias Composer

```bash
composer require guzzlehttp/guzzle:^7.0
composer require robrichards/xmlseclibs:^3.0
```

---

## 🗂️ 12. Archivos a crear

| Archivo | Propósito |
|---|---|
| `database/migrations/xxxx_add_dgii_to_transactions.php` | campos estado/xml/CFE |
| `app/Models/CfeStatus.php` | enum PENDING/SENT/REJECTED/ERROR |
| `app/Services/DgiiCfeService.php` | XML + firma + envío + parseo |
| `app/Jobs/SendCfeToDgii.php` | queue async con retry |
| `app/Events/CfeSent.php` | evento éxito |
| `app/Events/CfeRejected.php` | evento fallos |
| `app/Listeners/NotifyCfeAdmin.php` | notifica admin (badge) |
| `config/dgii.php` | cert/endpoint/env |
| `routes/admin_dgii.php` | GET /admin/dgii/{ncf}/verify |
| `resources/views/admin/dgii/dashboard.blade.php` | listado + filtros |
| `tests/Feature/DgiiCfeTest.php` | 6 tests feature |

---

## 📋 13. Tabla de códigos DGII

| Campo | Código | Descripción |
|---|---|---|
| Tipo CFE | E31 | Factura de venta |
| Tipo CFE | E32 | Nota débito |
| Tipo CFE | E33 | Nota crédito |
| Tipo CFE | E34 | Factura de importación |
| FormaPago | 1 | Efectivo |
| FormaPago | 2 | Tarjeta |
| FormaPago | 3 | Cheque |
| FormaPago | 4 | Transferencia/Compensatorio |

---

> 📌 Este plan está validado técnicamente (GLM-5.2-High) contra la estructura actual del POS TapGo.  
> La base de datos ya contiene `transactions` + `transaction_payments` con los datos mapeados.  
> El NCF (`ncf`, `ncf_type`) está listo → el CFE generator reusa.

---

**Guardado en:** `docs/implementacion-dgii.md`  
**Modelo usado:** GLM-5.2-High

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\Coupon;
use App\Support\AdminRbac;
use App\Support\CouponToken;
use App\Support\Format;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * El cupón tal como se imprime: QR (`@coupon=<número>@token=<token>`, el mismo contenido del archivo del lote) o
 * código de barras (Code 128 con el número). Es un dato que permite cobrar el cupón: exige un permiso propio, se
 * entrega solo al pedirlo (no viaja en la página de detalle) y cada consulta queda en la auditoría.
 */
class CouponPrintController extends Controller
{
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $isQr = $type === 'qr';
        $screen = $isQr ? 'coupons' : 'coupon-barcodes';
        abort_unless(
            $admin->can(AdminRbac::permissionsForScreen($screen)['view'])
            && $admin->can(AdminRbac::actionPermissions($screen)['detail']['permission'])
            && $admin->can(AdminRbac::actionPermissions($screen)['view_printed']['permission']),
            403
        );

        $model = $isQr ? Coupon::class : BarcodeCoupon::class;
        $coupon = $model::query()->visibleTo($admin)->where('coupon_id', $id)->firstOrFail();
        $number = (string) $coupon->coupon_number;

        $svg = null;
        $note = null;
        if ($isQr) {
            // Los tokens nuevos solo se guardan como hash: no se puede reconstruir el QR impreso
            if (CouponToken::isHashed($coupon->coupon_token) || empty($coupon->coupon_token)) {
                $note = 'El token de este cupón QR no se guarda en claro (solo su huella), así que el código impreso no se puede volver a dibujar. Está en el archivo del lote que se descargó al generarlo.';
            } else {
                $svg = $this->qr("@coupon={$number}@token={$coupon->coupon_token}");
            }
        } else {
            $svg = (new BarcodeGeneratorSVG)->getBarcode($number, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 90);
        }

        AdminAuditLog::record('view_printed', class_basename($model), (string) $id, ['type' => $isQr ? 'QR' : 'barras', 'drawn' => $svg !== null], $admin);

        return response()->json([
            'kind' => $isQr ? 'qr' : 'barcode',
            'number' => $number,
            'status' => $coupon->status,
            'amount' => Format::money($coupon->coupon_amount),
            'balance' => Format::money($coupon->coupon_balance),
            'expire' => $coupon->coupon_expire_date ? Carbon::parse($coupon->coupon_expire_date)->format('d/m/Y') : null,
            'brand' => config('app.name'),
            'svg' => $svg,
            'note' => $note,
        ])->header('Cache-Control', 'no-store');
    }

    private function qr(string $payload): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($payload);

        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}

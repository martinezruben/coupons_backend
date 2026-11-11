<?php

namespace App\Services;

use App\Events\NcfRangeLow;
use App\Models\NcfSequence;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Genera NCF secuencial según país y modo de asignación.
 *
 * Ecuador: 01|04|05|07 + 001 (est) + 9 dígitos → 13 chars
 * República Dominicana: E31|E32|E33|E34 + 001 (est) + 9 dígitos → 17 chars
 */
class NcfService
{
    public function generate(string $type, ?int $locationId = null): ?string
    {
        if (! (bool) Config::get('ncf.enabled', false)) {
            return null;
        }

        $mode = Config::get('ncf.mode', 'by_location');
        $locId = $mode === 'global' ? null : $locationId;
        $country = Config::get('ncf.country', 'EC');

        $seq = NcfSequence::where('type', $type)
            ->where('location_id', $locId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->checkLowThreshold($seq, $type);

        if ($seq->current > $seq->end) {
            throw new RuntimeException("Rango NCF {$type} agotado ({$seq->current} > {$seq->end}).");
        }

        $value = $seq->current++;
        $seq->save();

        return $this->format($type, $seq->establishment, $value, $country);
    }

    /**
     * Formatea el NCF según el país.
     */
    protected function format(string $type, string $est, int $value, string $country): string
    {
        $seq = str_pad((string) $value, 9, '0', STR_PAD_LEFT);

        return match ($country) {
            'DO' => $type.$est.$seq,          // E310010000000001 (13 chars)
            default => $type.$est.$seq,       // 01001000000001   (13 chars)
        };
    }

    /**
     * Notifica cuando el rango NCF se acerque al agotamiento.
     */
    protected function checkLowThreshold(NcfSequence $seq, string $type): void
    {
        $threshold = (int) Config::get('ncf.low_threshold', 100);
        $remaining = $seq->end - $seq->current + 1;

        if ($remaining <= $threshold && $remaining > 0) {
            $msg = "Rango NCF {$type} ".($seq->location_id ? "loc {$seq->location_id}" : 'global')
                   ." agotándose: {$remaining} restantes (límite {$seq->end}).";

            // Notificación broadcast via event — el handler admin la muestra en Dashboard
            event(new NcfRangeLow($seq, $type, $remaining));
        }
    }
}

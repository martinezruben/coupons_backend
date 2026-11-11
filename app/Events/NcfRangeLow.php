<?php

namespace App\Events;

use App\Models\NcfSequence;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: rango NCF se acerca al agotamiento.
 * Listeners: notifican al admin (Dashboard badge + POS warning).
 */
class NcfRangeLow
{
    use Dispatchable, SerializesModels;

    public NcfSequence $sequence;

    public string $type;

    public int $remaining;

    public function __construct(NcfSequence $sequence, string $type, int $remaining)
    {
        $this->sequence = $sequence;
        $this->type = $type;
        $this->remaining = $remaining;
    }
}

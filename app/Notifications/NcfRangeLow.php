<?php

namespace App\Notifications;

use App\Models\NcfSequence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NcfRangeLow extends Notification implements ShouldQueue
{
    use Queueable;

    public NcfSequence $sequence;

    public string $type;

    public int $remaining;

    public function __construct(NcfSequence $sequence, string $type, int $remaining)
    {
        $this->sequence = $sequence;
        $this->type = $type;
        $this->remaining = $remaining;
    }

    /**
     * Scope: admin-dashboard (database + broadcast).
     */
    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Dashboard badge (red, CRITICAL) + grid row highlight.
     */
    public function toDatabase($notifiable): array
    {
        return [
            'title' => '⚠️ Rango NCF agotándose',
            'message' => "NCF {$this->type} (".($this->sequence->location_id ? 'loc' : 'global').") con {$this->remaining} restantes.",
            'level' => 'critical',
            'sequence_id' => $this->sequence->id,
        ];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => $this->type,
            'remaining' => $this->remaining,
            'sequence' => $this->sequence->only(['id', 'type', 'current', 'end']),
        ];
    }
}

<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'api_request_logs';

    protected $fillable = [
        'method',
        'path',
        'parameters',
        'response_status',
        'response_summary',
        'location_id',
        'device_id',
        'device_fingerprint',
        'ip_address',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}

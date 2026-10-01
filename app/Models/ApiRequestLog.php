<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Public Rate API call (see AuthenticateApiClient / PublicRateController). */
#[Fillable(['api_client_id', 'endpoint', 'reference', 'ip', 'end_user_ip', 'destination_country', 'total_weight', 'pieces', 'result_count', 'lowest_price', 'cached', 'status_code', 'duration_ms', 'error', 'created_at'])]
class ApiRequestLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Kept for a year of usage stats (rows hold visitor IPs, so not forever). */
    public const RETENTION_DAYS = 365;

    protected function casts(): array
    {
        return [
            'total_weight' => 'float',
            'lowest_price' => 'float',
            'cached' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}

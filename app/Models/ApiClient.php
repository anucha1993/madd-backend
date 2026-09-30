<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An external site allowed to call the Public Rate API (see the create_api_clients_tables
 * migration). The plain API key exists only in the return value of issueKey().
 */
#[Fillable(['name', 'branch_id', 'origin_city', 'origin_postcode', 'carriers', 'max_results', 'price_rounding', 'rate_limit_per_minute', 'end_user_limit_per_minute', 'allowed_ips', 'browser_origins', 'allow_rates', 'allow_tracking', 'status', 'created_by'])]
class ApiClient extends Model
{
    use Auditable;

    private const KEY_PREFIX_LENGTH = 12;

    protected $hidden = ['key_hash'];

    protected array $auditExclude = ['key_hash', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'carriers' => 'array',
            'allowed_ips' => 'array',
            'browser_origins' => 'array',
            'status' => 'boolean',
            'allow_rates' => 'boolean',
            'allow_tracking' => 'boolean',
            'max_results' => 'integer',
            'price_rounding' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'end_user_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    /** Generates a new key (replacing any previous one) and returns it — the only time it's readable. */
    public function issueKey(): string
    {
        $key = 'madd_'.Str::random(40);
        $this->forceFill(['key_prefix' => substr($key, 0, self::KEY_PREFIX_LENGTH), 'key_hash' => hash('sha256', $key)])->save();
        app(\App\Services\AuditLogger::class)->record('key_issued', $this, [], $this->name);

        return $key;
    }

    public static function findByKey(string $key): ?self
    {
        $client = static::where('key_prefix', substr($key, 0, self::KEY_PREFIX_LENGTH))->first();

        return $client && hash_equals($client->key_hash, hash('sha256', $key)) ? $client : null;
    }
}

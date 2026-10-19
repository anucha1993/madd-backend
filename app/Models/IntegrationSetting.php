<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['key', 'value'])]
class IntegrationSetting extends Model
{
    use Auditable;

    protected array $auditExclude = ['value'];

    public static function get(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Read-modify-write of a JSON setting under a row lock — for settings saved one part at a
     * time (a menu name, one section's layout), so two admins saving at once can't drop each
     * other's change. $fn gets the stored array ([] if none) and returns the new one.
     */
    public static function mutateJson(string $key, callable $fn): array
    {
        static::firstOrCreate(['key' => $key], ['value' => null]);

        return DB::transaction(function () use ($key, $fn) {
            $row = static::where('key', $key)->lockForUpdate()->first();
            $current = json_decode((string) $row->value, true);
            $next = $fn(is_array($current) ? $current : []);
            $row->update(['value' => json_encode($next, JSON_UNESCAPED_UNICODE)]);

            return $next;
        });
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
}

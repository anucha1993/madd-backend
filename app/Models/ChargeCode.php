<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'code', 'label', 'display_name', 'display_rule', 'description', 'category', 'is_custom', 'is_pinned'])]
class ChargeCode extends Model
{
    use Auditable, HasFactory;

    public const DISPLAY_RULE_OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'is_pinned' => 'boolean',
            'display_rule' => 'array',
        ];
    }

    /** Codes that have a display name and/or a conditional display rule. */
    public function scopeWithDisplayName($query)
    {
        return $query->where(fn ($q) => $q->where(fn ($n) => $n->whereNotNull('display_name')->where('display_name', '!=', ''))->orWhereNotNull('display_rule'));
    }

    /**
     * Staff-configured display names (/config/charge-names), keyed "PROVIDER|CODE". Load once per
     * request/loop and pass to displayNameFor() — never query per charge line.
     *
     * @return array<string, array{name: ?string, rule: ?array}>
     */
    public static function displayNameMap(): array
    {
        return static::query()
            ->withDisplayName()
            ->get(['provider', 'code', 'display_name', 'display_rule'])
            ->mapWithKeys(fn (self $c) => [strtoupper($c->provider).'|'.$c->code => ['name' => $c->display_name ?: null, 'rule' => $c->display_rule]])
            ->all();
    }

    /**
     * The name to show for one charge line: the rule's name when its condition holds against the
     * same quote's amounts ($amountsByCode, missing code = 0), else display_name, else $fallback
     * (the carrier's own description).
     *
     * @param  array<string, float>  $amountsByCode
     */
    public static function displayNameFor(array $map, ?string $provider, $code, string $fallback, array $amountsByCode = []): string
    {
        if ($code === null || $code === '' || ! $provider) {
            return $fallback;
        }

        $entry = $map[strtoupper($provider).'|'.$code] ?? null;
        if (! $entry) {
            return $fallback;
        }

        $rule = $entry['rule'];
        if (! empty($rule['code']) && ! empty($rule['name'])
            && self::ruleMatches((float) ($amountsByCode[(string) $rule['code']] ?? 0), $rule['op'] ?? '>', (float) ($rule['value'] ?? 0))) {
            return $rule['name'];
        }

        return $entry['name'] ?? $fallback;
    }

    /** @param  iterable<array{code?: mixed, amount?: mixed}>  $lines  a chargeBreakdown */
    public static function amountsByCode(iterable $lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            if (isset($line['code'])) {
                $amounts[(string) $line['code']] = ($amounts[(string) $line['code']] ?? 0) + (float) ($line['amount'] ?? 0);
            }
        }

        return $amounts;
    }

    private static function ruleMatches(float $amount, string $op, float $value): bool
    {
        return match ($op) {
            '>=' => $amount >= $value,
            '<' => $amount < $value,
            '<=' => $amount <= $value,
            '=' => abs($amount - $value) < 0.005,
            '!=' => abs($amount - $value) >= 0.005,
            default => $amount > $value,
        };
    }
}

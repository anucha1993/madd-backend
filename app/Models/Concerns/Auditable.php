<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records created / updated / deleted of this model to audit_logs (see AuditLogger — only
 * for an authenticated user's actions). A using model may declare:
 * - `protected array $auditExclude` — attributes never diffed (big JSON blobs, noise)
 * - `auditLabel(): ?string` — how the row is named in the log (default: name / id)
 */
trait Auditable
{
    private const AUDIT_ALWAYS_EXCLUDED = ['created_at', 'updated_at', 'remember_token'];

    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->record('created', $model, $model->auditDiff(array_keys($model->getAttributes()), true), $model->auditLabel());
        });

        static::updated(function (Model $model) {
            $diff = $model->auditDiff(array_keys($model->getChanges()), false);
            if ($diff) {
                app(AuditLogger::class)->record('updated', $model, $diff, $model->auditLabel());
            }
        });

        static::deleted(function (Model $model) {
            app(AuditLogger::class)->record('deleted', $model, [], $model->auditLabel());
        });
    }

    public function auditLabel(): ?string
    {
        foreach (['name', 'tracking_number', 'username', 'code', 'username_acc', 'key'] as $attribute) {
            if (! empty($this->attributes[$attribute])) {
                return (string) $this->attributes[$attribute];
            }
        }

        return '#'.$this->getKey();
    }

    /** @return array<string, array{old:mixed, new:mixed}> */
    protected function auditDiff(array $fields, bool $isCreate): array
    {
        $exclude = array_merge(self::AUDIT_ALWAYS_EXCLUDED, $this->auditExclude ?? []);
        $diff = [];
        foreach ($fields as $field) {
            if (in_array($field, $exclude, true)) {
                continue;
            }
            // Raw (stored) values — casts would decrypt secrets; AuditLogger masks by name anyway.
            $new = $this->getAttributes()[$field] ?? null;
            $old = $isCreate ? null : ($this->getRawOriginal($field));
            if (! $isCreate && $old == $new) {
                continue;
            }
            if ($isCreate && $new === null) {
                continue;
            }
            $diff[$field] = ['old' => $this->auditValue($old), 'new' => $this->auditValue($new)];
        }

        return $diff;
    }

    private function auditValue(mixed $value): mixed
    {
        if (is_string($value) && ($decoded = json_decode($value, true)) !== null && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return $decoded;
        }

        return $value;
    }
}

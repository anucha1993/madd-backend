<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit_logs rows. Only records actions by an authenticated user — system jobs
 * (tracking sync, scheduled reports) would otherwise drown the human changes. Values of any
 * field that looks like a secret are always masked.
 */
class AuditLogger
{
    private const SECRET_PATTERN = '/(password|secret|token|api_key|apikey|private_key)/i';

    public function record(string $event, Model|string $subject, array $changes = [], ?string $label = null, ?int $subjectId = null): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $isModel = $subject instanceof Model;
        AuditLog::create([
            'user_id' => $user->id,
            'event' => $event,
            'subject_type' => $isModel ? class_basename($subject) : $subject,
            'subject_id' => $subjectId ?? ($isModel ? $subject->getKey() : null),
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'changes' => $changes ? $this->mask($changes) : null,
            'ip' => request()?->ip(),
        ]);
    }

    private function mask(array $changes): array
    {
        foreach ($changes as $field => $diff) {
            if (preg_match(self::SECRET_PATTERN, (string) $field)) {
                $changes[$field] = ['old' => $diff['old'] === null ? null : '***', 'new' => $diff['new'] === null ? null : '***'];
            }
        }

        return $changes;
    }
}

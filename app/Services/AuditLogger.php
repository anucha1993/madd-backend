<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit_logs rows. Records actions by an authenticated user, and — as user_id null =
 * "System" — changes made by scheduled jobs / artisan commands (tracking sync marking a
 * shipment delivered, pickup overdue flags...). Unauthenticated HTTP requests are skipped unless
 * an actor is passed explicitly (login). Values of any field that looks like a secret are always
 * masked.
 */
class AuditLogger
{
    private const SECRET_PATTERN = '/(password|secret|token|api_key|apikey|private_key)/i';

    /**
     * @param  User|false|null  $actor  null = the authenticated user (or System in console);
     *                                  false = record with no actor (e.g. a failed login)
     */
    public function record(string $event, Model|string $subject, array $changes = [], ?string $label = null, ?int $subjectId = null, User|false|null $actor = null): void
    {
        if ($actor === null) {
            $actor = auth()->user();
            if (! $actor && ! app()->runningInConsole()) {
                return;
            }
        }

        $isModel = $subject instanceof Model;
        AuditLog::create([
            'user_id' => $actor ? $actor->id : null,
            'event' => $event,
            'subject_type' => $isModel ? class_basename($subject) : $subject,
            'subject_id' => $subjectId ?? ($isModel ? $subject->getKey() : null),
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'changes' => $changes ? $this->mask($changes) : null,
            'ip' => app()->runningInConsole() ? null : request()?->ip(),
        ]);
    }

    /** "Someone opened / downloaded this" — no field diff, just what was accessed. */
    public function accessed(string $event, Model|string $subject, array $details = [], ?string $label = null): void
    {
        $this->record($event, $subject, collect($details)->map(fn ($v) => ['old' => null, 'new' => $v])->all(), $label ?? ($subject instanceof Model ? $subject->auditLabel() : null));
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

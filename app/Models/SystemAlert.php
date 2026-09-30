<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * A failure an admin should look at (see the create_system_alerts_table migration). Recording
 * never throws — an alert that can't be saved must not break the request/job that failed.
 */
#[Fillable(['level', 'source', 'message', 'context', 'fingerprint', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_by'])]
class SystemAlert extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Folds into the matching OPEN alert (same source + message) instead of adding a new row. */
    public static function record(string $source, string $message, array $context = [], string $level = 'error'): void
    {
        try {
            $message = mb_substr($message, 0, 1000);
            $fingerprint = hash('sha256', $source.'|'.$message);
            $open = static::where('fingerprint', $fingerprint)->whereNull('resolved_at')->first();
            if ($open) {
                $open->update(['occurrences' => $open->occurrences + 1, 'last_seen_at' => now(), 'context' => $context ?: $open->context]);

                return;
            }
            static::create([
                'level' => $level, 'source' => $source, 'message' => $message, 'context' => $context ?: null,
                'fingerprint' => $fingerprint, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let alerting itself fail the caller (e.g. DB down while reporting).
        }
    }

    public static function recordException(Throwable $e): void
    {
        $request = app()->runningInConsole() ? null : request();
        static::record('exception', class_basename($e).': '.$e->getMessage(), array_filter([
            'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()).':'.$e->getLine(),
            'url' => $request ? $request->method().' '.$request->path() : null,
            'user_id' => $request?->user()?->id,
            'command' => app()->runningInConsole() ? implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)) : null,
        ]));
    }
}

<?php

namespace App\Support;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\DB;

/**
 * The SQL the Administrator AI assistant (/ai-assistant) may run on the real database.
 *
 * - One statement at a time. SELECT / WITH / SHOW / DESCRIBE / EXPLAIN read; INSERT / UPDATE /
 *   DELETE / REPLACE write. Anything else (DROP, ALTER, TRUNCATE, CREATE, GRANT …) is refused —
 *   the table structure belongs to migrations, not to a chat.
 * - UPDATE / DELETE need a WHERE.
 * - Secrets never reach the model: login / session / token tables are off limits, columns named
 *   like a password / secret / token / API key come back as "[hidden]", and so does any value equal
 *   to a stored integration secret. Writes may not touch those columns either.
 */
class AssistantSql
{
    public const READ = ['select', 'with', 'show', 'describe', 'desc', 'explain'];

    public const WRITE = ['insert', 'update', 'delete', 'replace'];

    public const MAX_ROWS = 200;

    // A write touching more rows than this, or any DELETE, or one of these tables, waits for the
    // Administrator to press "ยืนยัน" (AdminAssistantController::confirm).
    public const CONFIRM_ABOVE_ROWS = 20;

    public const CONFIRM_TABLES = ['users', 'roles', 'role_user', 'integration_settings', 'agent_accounts', 'api_clients'];

    private const BLOCKED_TABLES = ['personal_access_tokens', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks'];

    private const SECRET_WORDS = 'pass(word)?|secret|token|api_?key|private_?key|credential|remember';

    private const SECRET_COLUMN = '/'.self::SECRET_WORDS.'/i';

    /** "read" | "write", or throws with the reason in Thai (shown to the model and the admin). */
    public static function classify(string $sql): string
    {
        $clean = self::stripComments($sql);
        if ($clean === '') {
            throw new \InvalidArgumentException('ไม่มีคำสั่ง SQL');
        }
        if (str_contains($clean, ';')) {
            throw new \InvalidArgumentException('ส่งได้ครั้งละ 1 คำสั่ง (ห้ามมี ;)');
        }
        $verb = strtolower(strtok($clean, " \t\r\n("));
        foreach (self::BLOCKED_TABLES as $table) {
            if (preg_match('/\b'.$table.'\b/i', $clean)) {
                throw new \InvalidArgumentException("ตาราง {$table} เก็บข้อมูลเข้าสู่ระบบ — ผู้ช่วย AI เข้าถึงไม่ได้");
            }
        }
        if (in_array($verb, self::READ, true)) {
            // WITH … UPDATE / DELETE (MySQL 8) is still a write.
            if ($verb === 'with' && preg_match('/\b(update|delete|insert)\b/i', $clean)) {
                return self::write($clean, 'update');
            }

            return 'read';
        }
        if (in_array($verb, self::WRITE, true)) {
            return self::write($clean, $verb);
        }

        throw new \InvalidArgumentException('คำสั่ง '.strtoupper($verb).' ไม่อนุญาต — ผู้ช่วย AI อ่าน/เพิ่ม/แก้/ลบข้อมูลได้ แต่แก้โครงสร้างตาราง (DROP, ALTER, TRUNCATE, CREATE …) ไม่ได้');
    }

    private static function write(string $clean, string $verb): string
    {
        if (in_array($verb, ['update', 'delete'], true) && ! preg_match('/\bwhere\b/i', $clean)) {
            throw new \InvalidArgumentException(strtoupper($verb).' ต้องมี WHERE — ไม่ให้แก้/ลบทั้งตาราง');
        }
        if (preg_match('/\b\w*('.self::SECRET_WORDS.')\w*\b/i', $clean, $m)) {
            throw new \InvalidArgumentException("ผู้ช่วย AI แก้คอลัมน์ข้อมูลลับ ({$m[0]}) ไม่ได้");
        }

        return 'write';
    }

    /** Why this write must be confirmed first, or null when it can run straight away. */
    public static function confirmReason(string $sql, int $affectedRows): ?string
    {
        $clean = self::stripComments($sql);
        if (preg_match('/^\s*delete\b/i', $clean)) {
            return 'ลบข้อมูล';
        }
        if ($affectedRows > self::CONFIRM_ABOVE_ROWS) {
            return "มีผลกับ {$affectedRows} แถว (เกิน ".self::CONFIRM_ABOVE_ROWS.')';
        }
        foreach (self::CONFIRM_TABLES as $table) {
            if (preg_match('/\b'.$table.'\b/i', $clean)) {
                return "แก้ตาราง {$table} (สิทธิ์ / การเชื่อมต่อ)";
            }
        }

        return null;
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    public static function select(string $sql): array
    {
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            // A 15 s cap on one query — MariaDB (this server) and MySQL name it differently.
            $mariaDb = str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS v')->v), 'mariadb');
            DB::statement($mariaDb ? 'SET SESSION max_statement_time = 15' : 'SET SESSION MAX_EXECUTION_TIME = 15000');
            DB::statement('START TRANSACTION READ ONLY');
        }
        try {
            $rows = DB::select(self::stripComments($sql));
        } finally {
            if ($mysql) {
                DB::statement('ROLLBACK');
            }
        }

        $secrets = self::secretValues();
        $rows = array_map(function ($row) use ($secrets) {
            $out = [];
            foreach ((array) $row as $column => $value) {
                $out[$column] = preg_match(self::SECRET_COLUMN, (string) $column) || (is_string($value) && isset($secrets[$value])) ? '[hidden]' : $value;
            }

            return $out;
        }, $rows);

        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
    }

    /** Runs a write in a transaction; commits only when $commit (else rolls back after counting). */
    public static function execute(string $sql, bool $commit): int
    {
        DB::beginTransaction();
        try {
            $affected = DB::affectingStatement(self::stripComments($sql));
            $commit ? DB::commit() : DB::rollBack();

            return $affected;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sql = preg_replace('/(--|#)[^\n]*/', ' ', $sql);

        return rtrim(trim($sql), "; \t\r\n");
    }

    /** Stored integration secrets (API keys …) — as a lookup set, to blank them out of results. */
    private static function secretValues(): array
    {
        $values = [];
        foreach (IntegrationSetting::query()->toBase()->get(['key', 'value']) as $setting) {
            if (preg_match(self::SECRET_COLUMN, (string) $setting->key) && is_string($setting->value) && strlen($setting->value) >= 6) {
                $values[$setting->value] = true;
            }
        }

        return $values;
    }
}

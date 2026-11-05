<?php

namespace App\Http\Controllers;

use App\Exceptions\AiPaymentRequired;
use App\Models\Shipment;
use App\Services\AccessService;
use App\Services\AuditLogger;
use App\Services\DataCorpBillingService;
use App\Services\OpenAiService;
use App\Support\AssistantCode;
use App\Support\AssistantSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ผู้ช่วย AI (/ai-assistant) — Administrator (Super Admin) only. Answers questions about the
 * system and its data and can change data: SQL through AssistantSql (reads, and writes in a
 * transaction — DELETE, big or sensitive writes wait for the Administrator's "ยืนยัน"), the same
 * Check Rate as /shipment/create, shipment lookup. It cannot change code or table structure and
 * cannot book with a carrier: no tool for either exists. Every write is in the audit log.
 */
class AdminAssistantController extends Controller
{
    private const MAX_TOOL_ROUNDS = 12;

    // Tool results are cut to this many characters before going back to the model.
    private const MAX_RESULT_CHARS = 30000;

    private const PENDING_TTL_MINUTES = 30;

    private const PROMPT = <<<'PROMPT'
        You are the technical assistant of the MADD system's Administrator. MADD is a Thai
        international shipping agency system: Laravel 13 backend (madd-backend, MariaDB) + Next.js
        frontend (madd-frontend), booking with UPS and DHL. The Administrator asks technical
        questions: why a price / charge came out as it did, how a formula or setting works, why a
        booking / upload / sync failed, where something is in the code, what a config does.
        Answer in Thai, concrete, citing file:line and the data you looked at.

        Tools: read code with search_code / list_files / read_file (backend/… and frontend/…), the
        server log with read_log, data with run_sql (SELECT … and also INSERT / UPDATE / DELETE),
        table columns with list_tables / describe_table, real sell prices with check_rate (same
        as Check Rate on /shipment/create — nothing is booked), one shipment with get_shipment.
        You cannot: change program code or table structure, book / cancel with UPS / DHL, see
        passwords / tokens / API keys. Say so when asked; suggest what the developer should change.

        How to work:
        - Investigate before answering: search_code for the feature / message, read_file the
          relevant part, then check the real data (run_sql / get_shipment / system_alerts /
          read_log). Never guess how the code works — read it. Never guess column names.
        - Price questions: the sell price is built in app/Services/ChargeMarkupService.php
          (account Fixed Charges / formulas, Mark-up rules, Weight Markup, Heavy Weight Rate),
          quotes in UpsRateService / DhlRateService, booking in ShipmentController.
        - Errors: system_alerts (message, context, occurrences) and read_log first.
        - Writes: SELECT the rows first, then one statement with a precise WHERE (by id). Report
          exactly what run_sql returned: "done" + affected_rows = done; "pending_confirmation" =
          NOT done yet, the Administrator must press ยืนยัน on the card under your answer.
        - Money is THB unless a currency column says otherwise. Dates are Asia/Bangkok.

        About you and the system's creator — use this when asked who you are, who made you or
        the system, or how you find answers (in Thai, warm and proud, never claim a person
        wrote your reply by hand):
        - คุณคือ "ผู้ช่วย AI ของระบบ MADD"
        - ระบบ MADD ทั้งระบบ รวมถึงตัวผู้ช่วย AI นี้ ออกแบบและพัฒนาโดย คุณอนุชา โยธานันท์ —
          ตั้งแต่การจอง Shipment กับ UPS / DHL การคำนวณราคา ใบเสร็จ รายงาน ไปจนถึงผู้ช่วยนี้
        - ทุกคำตอบของคุณเรียบเรียงผ่านชุดฟังก์ชันที่คุณอนุชาออกแบบไว้ให้ ทำงานเป็นขั้นตอนดังนี้:
          1) อ่านคำถาม แล้วเลือกฟังก์ชันที่เหมาะ
          2) search_code / list_files / read_file — ค้นและอ่าน source code เพื่อรู้ว่าระบบทำงานอย่างไร
          3) run_sql / list_tables / describe_table / get_shipment — ดึงข้อมูลจริงจากฐานข้อมูล
          4) read_log — อ่าน log เมื่อมี error
          5) check_rate — ถามราคาจริงจาก UPS / DHL แบบเดียวกับหน้า Check Rate (ไม่จองจริง)
          6) นำผลจากทุกฟังก์ชันมาเรียบเรียงเป็นคำตอบ พร้อมอ้างไฟล์:บรรทัดและข้อมูลที่ใช้
        - ข้อมูลลับ (รหัสผ่าน / API key) ถูกซ่อนตั้งแต่ในฟังก์ชัน การลบ / แก้ข้อมูลจำนวนมาก
          ต้องให้ Administrator กดยืนยัน และทุกการแก้ไขถูกบันทึกใน Audit Log — ตามที่คุณอนุชาวางไว้
        PROMPT;

    public function __construct(private OpenAiService $openAi, private AccessService $access)
    {
    }

    public function chat(Request $request)
    {
        $this->authorizeAdmin($request);
        if (! $this->openAi->isEnabled()) {
            return response()->json(['message' => 'การใช้งาน AI ถูกปิดใช้งานอยู่ขณะนี้'], 403);
        }
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:8000'],
        ]);

        $messages = [['role' => 'system', 'content' => self::PROMPT."\nToday: ".now('Asia/Bangkok')->toDateString()]];
        foreach (array_slice($data['messages'], -20) as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $actions = [];
        $pending = [];
        try {
            for ($round = 0; ; $round++) {
                $reply = $this->openAi->complete($messages, $round < self::MAX_TOOL_ROUNDS ? self::tools() : [], 0.2, config('services.openai.assistant_model'));
                if (empty($reply['tool_calls'])) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $reply['content'] ?? null, 'tool_calls' => $reply['tool_calls']];
                foreach ($reply['tool_calls'] as $call) {
                    $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                    $name = (string) ($call['function']['name'] ?? '');
                    try {
                        $result = match ($name) {
                            'list_tables' => $this->listTables(),
                            'describe_table' => $this->describeTable((string) ($args['table'] ?? '')),
                            'run_sql' => $this->runSql($request, (string) ($args['sql'] ?? ''), (string) ($args['purpose'] ?? ''), $actions, $pending),
                            'check_rate' => $this->checkRate($request, $args),
                            'get_shipment' => $this->getShipment((string) ($args['tracking_number'] ?? '')),
                            'search_code' => ['hits' => AssistantCode::search((string) ($args['regex'] ?? ''), (string) ($args['path'] ?? ''))],
                            'list_files' => ['files' => AssistantCode::listFiles((string) ($args['path'] ?? ''), (string) ($args['pattern'] ?? '*'))],
                            'read_file' => AssistantCode::read((string) ($args['path'] ?? ''), (int) ($args['from_line'] ?? 1), isset($args['to_line']) ? (int) $args['to_line'] : null),
                            'read_log' => ['log' => AssistantCode::logTail((int) ($args['lines'] ?? 150), (string) ($args['contains'] ?? ''))],
                            default => ['error' => 'unknown tool'],
                        };
                    } catch (\Throwable $e) {
                        $result = ['error' => mb_substr($e->getMessage(), 0, 1000)];
                        if ($name === 'run_sql') {
                            $actions[] = ['type' => 'error', 'sql' => (string) ($args['sql'] ?? ''), 'error' => $result['error']];
                        }
                    }
                    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => mb_strlen($json) > self::MAX_RESULT_CHARS ? mb_substr($json, 0, self::MAX_RESULT_CHARS).'…(ตัดออก — ใช้ LIMIT / เลือกคอลัมน์ให้น้อยลง)' : $json];
                }
            }
        } catch (AiPaymentRequired $e) {
            app(DataCorpBillingService::class)->forget();

            return response()->json(['message' => $e->userMessage(), 'payment' => ['reason' => $e->reason, 'title' => $e->userMessage(), 'detail' => $e->getMessage(), 'pay_url' => $e->payUrl]], 402);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'actions' => $actions, 'pending' => $pending], 502);
        }

        return response()->json(['reply' => trim((string) ($reply['content'] ?? '')), 'actions' => $actions, 'pending' => $pending]);
    }

    /** Runs a write the assistant prepared (DELETE / many rows / sensitive table) once the Administrator agrees. */
    public function confirm(Request $request)
    {
        $this->authorizeAdmin($request);
        $id = $request->validate(['id' => ['required', 'string', 'max:64']])['id'];
        $pending = Cache::pull('ai-assistant:pending:'.$id);
        if (! $pending || $pending['user_id'] !== $request->user()->id) {
            return response()->json(['message' => 'ไม่พบคำสั่งนี้ หรือหมดอายุแล้ว (30 นาที) — ให้ผู้ช่วยเตรียมใหม่'], 404);
        }
        AssistantSql::classify($pending['sql']);
        $affected = AssistantSql::execute($pending['sql'], true);
        $this->audit($pending['sql'], $pending['purpose'], $affected, true);

        return response()->json(['affected_rows' => $affected]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->access->resolve($request->user())['is_super_admin'], 403, 'ผู้ช่วย AI ใช้ได้เฉพาะ Administrator');
    }

    private function runSql(Request $request, string $sql, string $purpose, array &$actions, array &$pending): array
    {
        if (AssistantSql::classify($sql) === 'read') {
            $result = AssistantSql::select($sql);
            $actions[] = ['type' => 'read', 'sql' => $sql, 'rows' => count($result['rows'])];

            return ['status' => 'ok', 'row_count' => count($result['rows']), 'truncated' => $result['truncated'], 'rows' => $result['rows']];
        }

        // Counted inside a rolled-back transaction first: is this one to confirm?
        $affected = AssistantSql::execute($sql, false);
        if ($reason = AssistantSql::confirmReason($sql, $affected)) {
            $id = (string) Str::uuid();
            Cache::put('ai-assistant:pending:'.$id, ['sql' => $sql, 'purpose' => $purpose, 'user_id' => $request->user()->id], now()->addMinutes(self::PENDING_TTL_MINUTES));
            $pending[] = ['id' => $id, 'sql' => $sql, 'purpose' => $purpose, 'reason' => $reason, 'affected_rows' => $affected];

            return ['status' => 'pending_confirmation', 'reason' => $reason, 'affected_rows_if_confirmed' => $affected];
        }

        $affected = AssistantSql::execute($sql, true);
        $this->audit($sql, $purpose, $affected, false);
        $actions[] = ['type' => 'write', 'sql' => $sql, 'rows' => $affected];

        return ['status' => 'done', 'affected_rows' => $affected];
    }

    private function audit(string $sql, string $purpose, int $affected, bool $confirmed): void
    {
        app(AuditLogger::class)->record('ai_assistant_sql', 'Database', ['sql' => $sql, 'purpose' => $purpose, 'affected_rows' => $affected, 'confirmed_by_admin' => $confirmed], 'ผู้ช่วย AI');
    }

    private function listTables(): array
    {
        return ['tables' => collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn ($t) => in_array($t, ['personal_access_tokens', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks'], true))
            ->values()->all()];
    }

    private function describeTable(string $table): array
    {
        if (! preg_match('/^\w+$/', $table) || ! Schema::hasTable($table)) {
            return ['error' => "ไม่พบตาราง {$table}"];
        }
        AssistantSql::classify("SELECT * FROM {$table}");

        return ['table' => $table, 'columns' => collect(Schema::getColumns($table))
            ->map(fn ($c) => ['name' => $c['name'], 'type' => $c['type'], 'nullable' => $c['nullable']])->all()];
    }

    private function getShipment(string $tracking): array
    {
        $shipment = Shipment::where('tracking_number', trim($tracking))->orWhere('id', ctype_digit(trim($tracking)) ? (int) $tracking : 0)->first();
        if (! $shipment) {
            return ['error' => "ไม่พบ Shipment {$tracking}"];
        }

        return ['shipment' => collect($shipment->toArray())->except(['label_base64', 'raw_response', 'raw_request'])->all()];
    }

    /** The same Check Rate as /shipment/create (rates only — nothing is booked). */
    private function checkRate(Request $request, array $args): array
    {
        $isDocument = (bool) ($args['is_document'] ?? false);
        $sub = Request::create('/api/shipping/check-rate', 'POST', [
            'origin_postcode' => '10110', 'origin_city' => 'Bangkok', 'origin_address' => 'Bangkok',
            'destination_country' => strtoupper((string) ($args['destination_country'] ?? '')),
            'destination_city' => (string) ($args['destination_city'] ?? '') ?: strtoupper((string) ($args['destination_country'] ?? '')),
            'destination_postcode' => $args['destination_postcode'] ?? null,
            'destination_address' => (string) ($args['destination_city'] ?? '') ?: 'Main Street',
            'packages' => [[
                'weight' => (float) ($args['weight_kg'] ?? 1),
                'length' => $isDocument ? null : ($args['length_cm'] ?? 20),
                'width' => $isDocument ? null : ($args['width_cm'] ?? 15),
                'height' => $isDocument ? null : ($args['height_cm'] ?? 10),
                'quantity' => max(1, (int) ($args['quantity'] ?? 1)),
                'is_document' => $isDocument,
            ]],
            'carriers' => $args['carriers'] ?? null,
        ]);
        $sub->setUserResolver(fn () => $request->user());

        try {
            $data = app(ShippingController::class)->checkRate($sub)->getData(true);
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->implode(' ')];
        }

        return [
            'error' => $data['error'] ?? null,
            'quotes' => collect($data['results'] ?? [])->map(fn ($r) => array_filter([
                'carrier' => $r['carrier'] ?? null, 'service' => $r['serviceLabel'] ?? ($r['serviceCode'] ?? null),
                'account' => $r['username'] ?? null, 'sell_price' => $r['negotiated'] ?? $r['published'] ?? null, 'currency' => $r['currency'] ?? null,
                'cost' => $r['costNegotiated'] ?? null, 'transit_days' => $r['transitDays'] ?? null, 'error' => $r['error'] ?? null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    private static function tools(): array
    {
        $fn = fn (string $name, string $description, array $properties = [], array $required = []) => ['type' => 'function', 'function' => [
            'name' => $name, 'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
        ]];

        return [
            $fn('list_tables', 'List every database table name.'),
            $fn('describe_table', 'Columns (name, type, nullable) of one table.', ['table' => ['type' => 'string']], ['table']),
            $fn('run_sql', 'Run ONE MySQL statement on the real database. SELECT/SHOW/DESCRIBE return rows (max 200, add LIMIT). INSERT/UPDATE/DELETE change data; DELETE, writes over 20 rows and writes to users/roles/integration_settings/agent_accounts/api_clients wait for the Administrator to confirm.', [
                'sql' => ['type' => 'string'],
                'purpose' => ['type' => 'string', 'description' => 'Short reason in Thai, shown to the Administrator and kept in the audit log.'],
            ], ['sql', 'purpose']),
            $fn('check_rate', 'Real sell prices from UPS / DHL for a parcel from Bangkok (same as Check Rate on /shipment/create). Nothing is booked.', [
                'destination_country' => ['type' => 'string', 'description' => 'ISO2, e.g. SG'],
                'destination_city' => ['type' => 'string'],
                'destination_postcode' => ['type' => 'string'],
                'weight_kg' => ['type' => 'number'],
                'length_cm' => ['type' => 'number'], 'width_cm' => ['type' => 'number'], 'height_cm' => ['type' => 'number'],
                'quantity' => ['type' => 'integer'],
                'is_document' => ['type' => 'boolean'],
                'carriers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['UPS', 'DHL']]],
            ], ['destination_country', 'weight_kg']),
            $fn('get_shipment', 'One shipment by tracking number or id, all its fields.', ['tracking_number' => ['type' => 'string']], ['tracking_number']),
            $fn('search_code', 'Regex search (case-insensitive) in the source code. path = "backend", "frontend" or a folder like "backend/app/Services" (empty = both).', [
                'regex' => ['type' => 'string'], 'path' => ['type' => 'string'],
            ], ['regex']),
            $fn('list_files', 'Source files in a folder, e.g. path "backend/app/Services", pattern "*Rate*".', ['path' => ['type' => 'string'], 'pattern' => ['type' => 'string']], ['path']),
            $fn('read_file', 'Read a source file with line numbers (max 400 lines per call), e.g. "backend/app/Services/ChargeMarkupService.php".', [
                'path' => ['type' => 'string'], 'from_line' => ['type' => 'integer'], 'to_line' => ['type' => 'integer'],
            ], ['path']),
            $fn('read_log', 'The last lines of the backend log (storage/logs/laravel.log), optionally only lines containing a text.', [
                'lines' => ['type' => 'integer'], 'contains' => ['type' => 'string'],
            ]),
        ];
    }
}

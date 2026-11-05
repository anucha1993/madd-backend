<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\IntegrationSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\AssistantSql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAssistantTest extends TestCase
{
    use RefreshDatabase;

    private array $sentToModel = [];

    protected function setUp(): void
    {
        parent::setUp();
        IntegrationSetting::set('openai_api_key', 'sk-test-secret-key');
    }

    private function actingAsAdmin(bool $superAdmin): User
    {
        $role = Role::create(['key' => 'r'.random_int(1, 99999), 'name' => 'R', 'is_super_admin' => $superAdmin, 'permissions' => ['ai.rate_chat'], 'field_access' => [], 'data_scopes' => []]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** The model calls run_sql once with $sql, then answers "เสร็จ". */
    private function fakeModel(string $sql): void
    {
        $round = 0;
        Http::fake(['*/chat/completions' => function ($request) use (&$round, $sql) {
            $this->sentToModel[] = $request->data();

            return Http::response(['choices' => [['message' => $round++ === 0
                ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'run_sql', 'arguments' => json_encode(['sql' => $sql, 'purpose' => 'ทดสอบ'])]]]]
                : ['role' => 'assistant', 'content' => 'เสร็จ']]]]);
        }]);
    }

    private function ask(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/ai/admin-assistant', ['messages' => [['role' => 'user', 'content' => 'ช่วยหน่อย']]]);
    }

    public function test_only_the_administrator_can_use_it(): void
    {
        $this->actingAsAdmin(false);
        $this->ask()->assertForbidden();
        $this->postJson('/api/ai/admin-assistant/confirm', ['id' => 'x'])->assertForbidden();
    }

    public function test_reads_come_back_with_secrets_hidden(): void
    {
        $this->actingAsAdmin(true);
        $this->fakeModel('SELECT id, email, password, remember_token FROM users');

        $this->ask()->assertOk()->assertJsonPath('reply', 'เสร็จ')->assertJsonPath('actions.0.type', 'read');
        $toolResult = json_decode(collect($this->sentToModel[1]['messages'])->firstWhere('role', 'tool')['content'], true);
        $this->assertSame('[hidden]', $toolResult['rows'][0]['password']);
        $this->assertSame('[hidden]', $toolResult['rows'][0]['remember_token']);
        $this->assertNotSame('[hidden]', $toolResult['rows'][0]['email']);

        // A stored API key never reaches the model, whatever the query.
        $this->sentToModel = [];
        $this->fakeModel("SELECT `key`, value FROM integration_settings WHERE `key` = 'openai_api_key'");
        $this->ask()->assertOk();
        $this->assertStringNotContainsString('sk-test-secret-key', json_encode($this->sentToModel[1]));
    }

    public function test_a_small_update_runs_and_is_audited(): void
    {
        $this->actingAsAdmin(true);
        $branch = Branch::create(['name' => 'Old', 'code' => 'OLD', 'status' => true]);
        $this->fakeModel("UPDATE branches SET name = 'New' WHERE id = {$branch->id}");

        $this->ask()->assertOk()->assertJsonPath('actions.0.type', 'write')->assertJsonPath('actions.0.rows', 1)->assertJsonPath('pending', []);
        $this->assertSame('New', $branch->fresh()->name);
        $this->assertSame(1, AuditLog::where('event', 'ai_assistant_sql')->count());
    }

    public function test_a_delete_waits_for_confirmation(): void
    {
        $this->actingAsAdmin(true);
        $branch = Branch::create(['name' => 'Gone', 'code' => 'GONE', 'status' => true]);
        $this->fakeModel("DELETE FROM branches WHERE id = {$branch->id}");

        $id = $this->ask()->assertOk()->assertJsonPath('pending.0.affected_rows', 1)->json('pending.0.id');
        $this->assertNotNull($branch->fresh()); // nothing deleted yet

        $this->postJson('/api/ai/admin-assistant/confirm', ['id' => $id])->assertOk()->assertJsonPath('affected_rows', 1);
        $this->assertNull($branch->fresh());
        $this->postJson('/api/ai/admin-assistant/confirm', ['id' => $id])->assertNotFound(); // once only
    }

    public function test_code_can_be_read_and_searched_but_secrets_and_outside_files_cannot(): void
    {
        $file = \App\Support\AssistantCode::read('backend/app/Support/AssistantSql.php', 1, 5);
        $this->assertSame('backend/app/Support/AssistantSql.php', $file['path']);
        $this->assertStringStartsWith('1: <?php', $file['content']);
        $this->assertNotEmpty(\App\Support\AssistantCode::search('class AssistantSql', 'backend/app'));
        $this->assertContains('backend/app/Services/HeavyRateService.php', \App\Support\AssistantCode::listFiles('backend/app/Services', '*Heavy*'));

        foreach (['backend/.env', '.env', 'backend/../../Windows/win.ini', 'backend/vendor/autoload.php', 'backend/storage/logs/laravel.log', 'backend/composer.lock'] as $path) {
            try {
                \App\Support\AssistantCode::read($path);
                $this->fail("Readable: {$path}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], array_filter(\App\Support\AssistantCode::search('APP_KEY=', 'backend'), fn ($hit) => preg_match('~/\.env[^/:]*:~', $hit)));
    }

    public function test_the_log_comes_back_with_tokens_hidden(): void
    {
        $log = storage_path('logs/laravel.log');
        $before = is_file($log) ? file_get_contents($log) : null;
        file_put_contents($log, "x\n[2026-11-05] local.ERROR: UPS failed Authorization: Bearer abcDEF123456789xyz {\"client_secret\":\"s3cr3tvalue\"} key dca_sk_ABCDEFGH12345678\n", FILE_APPEND);
        try {
            $tail = \App\Support\AssistantCode::logTail(5, 'UPS failed');
            $this->assertStringContainsString('UPS failed', $tail);
            foreach (['abcDEF123456789xyz', 's3cr3tvalue', 'dca_sk_ABCDEFGH12345678'] as $secret) {
                $this->assertStringNotContainsString($secret, $tail);
            }
        } finally {
            $before === null ? unlink($log) : file_put_contents($log, $before);
        }
    }

    public function test_structure_changes_and_unsafe_writes_are_refused(): void
    {
        foreach ([
            'DROP TABLE shipments',
            'ALTER TABLE shipments ADD x int',
            'TRUNCATE shipments',
            'DELETE FROM shipments',
            "UPDATE users SET name = 'x'",
            "UPDATE users SET password = 'x' WHERE id = 1",
            'SELECT 1; DELETE FROM shipments WHERE id = 1',
            'SELECT * FROM personal_access_tokens',
        ] as $sql) {
            try {
                AssistantSql::classify($sql);
                $this->fail("Allowed: {$sql}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('read', AssistantSql::classify('SELECT * FROM shipments -- ; drop'));
        $this->assertSame('write', AssistantSql::classify("UPDATE shipments SET remark = 'x' WHERE id = 1"));
    }
}

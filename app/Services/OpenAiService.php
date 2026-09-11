<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class OpenAiService
{
    private const SETTING_KEY = 'openai_api_key';
    private const ENABLED_KEY = 'openai_enabled';

    public function getApiKey(): ?string
    {
        return IntegrationSetting::get(self::SETTING_KEY) ?: config('services.openai.api_key');
    }

    public function setApiKey(?string $apiKey): void
    {
        IntegrationSetting::set(self::SETTING_KEY, $apiKey);
    }

    public function isConfigured(): bool
    {
        return (bool) $this->getApiKey();
    }

    // Defaults to enabled when never explicitly toggled (no row yet in the DB).
    public function isEnabled(): bool
    {
        $value = IntegrationSetting::get(self::ENABLED_KEY);

        return $value === null ? true : $value === '1';
    }

    public function setEnabled(bool $enabled): void
    {
        IntegrationSetting::set(self::ENABLED_KEY, $enabled ? '1' : '0');
    }

    public function maskedApiKey(): ?string
    {
        $key = $this->getApiKey();
        if (! $key) return null;

        return strlen($key) <= 8 ? str_repeat('•', strlen($key)) : substr($key, 0, 7) . str_repeat('•', 8) . substr($key, -4);
    }

    /**
     * Ask the model to answer strictly as JSON (no markdown fences) — used for
     * structured extraction tasks like parsing a pasted address into form fields.
     */
    public function chatJson(string $systemPrompt, string $userMessage): array
    {
        $content = $this->chat($systemPrompt, $userMessage, ['type' => 'json_object']);

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI ตอบกลับในรูปแบบที่ไม่ถูกต้อง');
        }

        return $decoded;
    }

    public function chatText(string $systemPrompt, string $userMessage): string
    {
        return $this->chat($systemPrompt, $userMessage, null);
    }

    private function chat(string $systemPrompt, string $userMessage, ?array $responseFormat): string
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey) {
            throw new \RuntimeException('ยังไม่ได้ตั้งค่า OpenAI API Key');
        }

        $payload = [
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => 0.2,
        ];
        if ($responseFormat) {
            $payload['response_format'] = $responseFormat;
        }

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post(rtrim(config('services.openai.base_url'), '/') . '/chat/completions', $payload);

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? "OpenAI request failed (HTTP {$response->status()})";
            throw new \RuntimeException($message);
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('OpenAI ไม่ได้ส่งคำตอบกลับมา');
        }

        return $content;
    }
}

<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

/**
 * OCRs carrier invoice PDFs via Google Cloud Vision's `files:annotate` REST endpoint (plain API
 * key auth — no service-account JSON / OAuth / Guzzle-version SDK dependency needed, same
 * "call the REST API directly" approach used for Google Drive elsewhere in this project).
 * `files:annotate` accepts the whole PDF as base64 and is limited to 5 pages per request, so a
 * multi-page invoice is OCR'd in chunks of 5 and the text is concatenated back in page order.
 */
class GoogleVisionService
{
    private const API_KEY_SETTING = 'google_vision_api_key';

    private const PAGES_PER_REQUEST = 5;

    public function getApiKey(): ?string
    {
        return IntegrationSetting::get(self::API_KEY_SETTING) ?: config('services.google_vision.api_key');
    }

    public function setApiKey(?string $apiKey): void
    {
        IntegrationSetting::set(self::API_KEY_SETTING, $apiKey);
    }

    public function isConfigured(): bool
    {
        return (bool) $this->getApiKey();
    }

    /**
     * @return string the concatenated DOCUMENT_TEXT_DETECTION text of every page, in order.
     */
    public function extractPdfText(string $pdfContent, int $pageCount): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Google Vision API key is not configured.');
        }

        $base64 = base64_encode($pdfContent);
        $pageText = [];

        for ($start = 1; $start <= $pageCount; $start += self::PAGES_PER_REQUEST) {
            $pages = range($start, min($start + self::PAGES_PER_REQUEST - 1, $pageCount));

            $response = Http::timeout(120)->post('https://vision.googleapis.com/v1/files:annotate?key='.$this->getApiKey(), [
                'requests' => [[
                    'inputConfig' => ['mimeType' => 'application/pdf', 'content' => $base64],
                    'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                    'pages' => array_values($pages),
                ]],
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Google Vision API error: '.$response->body());
            }

            $responses = $response->json('responses.0.responses', []);
            foreach ($responses as $pageResponse) {
                $pageText[] = $pageResponse['fullTextAnnotation']['text'] ?? '';
            }
        }

        return implode("\n", $pageText);
    }
}

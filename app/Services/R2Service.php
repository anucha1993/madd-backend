<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads/downloads shipment label files to/from Cloudflare R2 (S3-compatible) — credentials
 * are configurable from the Integrations settings UI (/config/integrations), falling back to
 * .env only if never set there, same pattern as OpenAiService/RestCountriesService.
 */
class R2Service
{
    private const ACCESS_KEY_ID_KEY = 'r2_access_key_id';
    private const SECRET_ACCESS_KEY_KEY = 'r2_secret_access_key';
    private const BUCKET_KEY = 'r2_bucket';
    private const ENDPOINT_KEY = 'r2_endpoint';

    public function getAccessKeyId(): ?string
    {
        return IntegrationSetting::get(self::ACCESS_KEY_ID_KEY) ?: config('services.r2.access_key_id');
    }

    public function getSecretAccessKey(): ?string
    {
        return IntegrationSetting::get(self::SECRET_ACCESS_KEY_KEY) ?: config('services.r2.secret_access_key');
    }

    public function getBucket(): ?string
    {
        return IntegrationSetting::get(self::BUCKET_KEY) ?: config('services.r2.bucket');
    }

    public function getEndpoint(): ?string
    {
        return IntegrationSetting::get(self::ENDPOINT_KEY) ?: config('services.r2.endpoint');
    }

    public function setCredentials(?string $accessKeyId, ?string $secretAccessKey, ?string $bucket, ?string $endpoint): void
    {
        IntegrationSetting::set(self::ACCESS_KEY_ID_KEY, $accessKeyId);
        if ($secretAccessKey !== null) {
            IntegrationSetting::set(self::SECRET_ACCESS_KEY_KEY, $secretAccessKey);
        }
        IntegrationSetting::set(self::BUCKET_KEY, $bucket);
        IntegrationSetting::set(self::ENDPOINT_KEY, $endpoint);
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->getAccessKeyId() && $this->getSecretAccessKey() && $this->getBucket() && $this->getEndpoint());
    }

    /**
     * Built fresh on every call (not a static config/filesystems.php disk) so admins can change
     * credentials from the UI without needing a config cache clear / app restart.
     */
    private function disk()
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Cloudflare R2 is not configured.');
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $this->getAccessKeyId(),
            'secret' => $this->getSecretAccessKey(),
            'region' => 'auto',
            'bucket' => $this->getBucket(),
            'endpoint' => $this->getEndpoint(),
            'use_path_style_endpoint' => true,
            // Surface real S3 exceptions instead of silently returning false/null on failure.
            'throw' => true,
        ]);
    }

    /**
     * @return array{key:string}
     */
    public function upload(string $filename, string $content, string $mimeType): array
    {
        $key = 'shipment-labels/'.now()->format('Y/m').'/'.bin2hex(random_bytes(8)).'-'.$filename;

        $this->disk()->put($key, $content, ['ContentType' => $mimeType]);

        return ['key' => $key];
    }

    public function download(string $key): string
    {
        return $this->disk()->get($key);
    }
}

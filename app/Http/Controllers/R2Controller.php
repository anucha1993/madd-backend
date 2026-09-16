<?php

namespace App\Http\Controllers;

use App\Services\R2Service;
use Illuminate\Http\Request;

class R2Controller extends Controller
{
    public function __construct(private R2Service $r2)
    {
    }

    public function settings()
    {
        return response()->json([
            'is_configured' => $this->r2->isConfigured(),
            'access_key_id' => $this->r2->getAccessKeyId(),
            'bucket' => $this->r2->getBucket(),
            'endpoint' => $this->r2->getEndpoint(),
            // The secret key itself is never sent back to the browser once saved.
            'has_secret_access_key' => (bool) $this->r2->getSecretAccessKey(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'access_key_id' => ['required', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:255'],
            'bucket' => ['required', 'string', 'max:255'],
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        if (empty($data['secret_access_key']) && ! $this->r2->getSecretAccessKey()) {
            return response()->json(['error' => 'กรุณาระบุ Secret Access Key (ยังไม่เคยตั้งค่าไว้ก่อนหน้านี้)'], 422);
        }

        $this->r2->setCredentials($data['access_key_id'], $data['secret_access_key'] ?? null, $data['bucket'], $data['endpoint']);

        return response()->json([
            'message' => 'บันทึกการตั้งค่า Cloudflare R2 เรียบร้อย',
            'is_configured' => $this->r2->isConfigured(),
            'access_key_id' => $this->r2->getAccessKeyId(),
            'bucket' => $this->r2->getBucket(),
            'endpoint' => $this->r2->getEndpoint(),
            'has_secret_access_key' => (bool) $this->r2->getSecretAccessKey(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\SmtpSettingService;
use Illuminate\Http\Request;

class SmtpSettingController extends Controller
{
    public function __construct(private SmtpSettingService $smtpSettingService)
    {
    }

    public function show()
    {
        return response()->json([
            'gmail_address' => $this->smtpSettingService->getAddress(),
            'has_app_password' => $this->smtpSettingService->hasAppPassword(),
            'enabled' => $this->smtpSettingService->isEnabled(),
            'is_configured' => $this->smtpSettingService->isConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'gmail_address' => ['required', 'email', 'max:255'],
            // 16-character Gmail "App Password" (spaces allowed, e.g. "abcd efgh ijkl mnop") —
            // not the user's real Google account password (Gmail no longer accepts that for SMTP).
            'gmail_app_password' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);

        if (empty($data['gmail_app_password']) && ! $this->smtpSettingService->hasAppPassword()) {
            return response()->json(['message' => 'กรุณาระบุ App Password (ยังไม่เคยตั้งค่าไว้ก่อนหน้านี้)'], 422);
        }

        $appPassword = ! empty($data['gmail_app_password']) ? str_replace(' ', '', $data['gmail_app_password']) : null;
        $this->smtpSettingService->updateSettings($data['gmail_address'], $appPassword, (bool) ($data['enabled'] ?? false));

        return $this->show();
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        try {
            $this->smtpSettingService->sendTest($data['to']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ส่งอีเมลทดสอบไม่สำเร็จ: '.$e->getMessage()], 422);
        }

        return response()->json(['message' => 'ส่งอีเมลทดสอบเรียบร้อย กรุณาตรวจสอบกล่องจดหมาย']);
    }
}

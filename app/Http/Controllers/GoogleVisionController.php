<?php

namespace App\Http\Controllers;

use App\Services\GoogleVisionService;
use Illuminate\Http\Request;

class GoogleVisionController extends Controller
{
    public function __construct(private GoogleVisionService $vision)
    {
    }

    public function settings()
    {
        return response()->json([
            'is_configured' => $this->vision->isConfigured(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $this->vision->setApiKey($data['api_key']);

        return response()->json([
            'message' => 'บันทึก Google Vision API Key เรียบร้อย',
            'is_configured' => $this->vision->isConfigured(),
        ]);
    }
}

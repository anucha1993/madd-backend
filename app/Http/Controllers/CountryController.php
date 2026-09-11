<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Services\RestCountriesService;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    public function __construct(private RestCountriesService $restCountriesService)
    {
    }

    public function index()
    {
        return Country::orderBy('name')->get();
    }

    public function sync()
    {
        $count = $this->restCountriesService->sync();

        return response()->json([
            'message' => "ซิงค์ข้อมูลประเทศเรียบร้อย ({$count} ประเทศ)",
            'count' => $count,
        ]);
    }

    public function update(Request $request, Country $country)
    {
        $data = $request->validate([
            'status' => ['required', 'boolean'],
        ]);

        $country->update($data);

        return $country;
    }

    public function settings()
    {
        return response()->json([
            'is_configured' => $this->restCountriesService->isConfigured(),
            'masked_api_key' => $this->restCountriesService->maskedApiKey(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:255'],
        ]);

        $this->restCountriesService->setApiKey($data['api_key']);

        return response()->json([
            'message' => 'บันทึก API Key เรียบร้อย',
            'is_configured' => true,
            'masked_api_key' => $this->restCountriesService->maskedApiKey(),
        ]);
    }
}

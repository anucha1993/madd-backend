<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\DhlRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AgentAccountController extends Controller
{
    public function __construct(private DhlRateService $dhlRateService)
    {
    }

    public function index(Request $request)
    {
        $query = AgentAccount::with('agent')->orderBy('agent_id')->orderBy('username_acc');

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->integer('agent_id'));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_id' => ['required', 'exists:agents,id'],
            'username_acc' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
            'basic_auth_username' => ['nullable', 'string'],
            'basic_auth_password' => ['nullable', 'string'],
            'status' => ['boolean'],
            'mode' => ['nullable', 'in:test,production'],
        ]);

        $account = AgentAccount::create($data);

        return response()->json($account->load('agent'), 201);
    }

    public function show(AgentAccount $agentAccount)
    {
        return $agentAccount->load('agent');
    }

    public function update(Request $request, AgentAccount $agentAccount)
    {
        $data = $request->validate([
            'agent_id' => ['sometimes', 'required', 'exists:agents,id'],
            'username_acc' => ['sometimes', 'required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
            'basic_auth_username' => ['nullable', 'string'],
            'basic_auth_password' => ['nullable', 'string'],
            'status' => ['boolean'],
            'mode' => ['nullable', 'in:test,production'],
        ]);

        // Blank secret fields mean "leave unchanged", not "clear the value".
        foreach (['client_secret', 'basic_auth_password'] as $secretField) {
            if (array_key_exists($secretField, $data) && $data[$secretField] === '') {
                unset($data[$secretField]);
            }
        }

        $agentAccount->update($data);

        return $agentAccount->load('agent');
    }

    public function destroy(AgentAccount $agentAccount)
    {
        $agentAccount->delete();

        return response()->json(['message' => 'ลบบัญชี Agent เรียบร้อย']);
    }

    public function test(AgentAccount $agentAccount)
    {
        $agentCode = $agentAccount->agent->agent_code;

        return match ($agentCode) {
            'UPS' => $this->testUps($agentAccount),
            'DHL' => $this->testDhl($agentAccount),
            default => response()->json(['success' => false, 'message' => "ไม่รองรับการทดสอบ Agent: {$agentCode}"], 422),
        };
    }

    /**
     * Lists this DHL account's real available product codes/names (via a lightweight reference
     * rate call) so admins setting BranchCarrierAccount.allowed_service_codes see actual product
     * names instead of guessing raw codes — DHL has no fixed product list like UPS.
     */
    public function dhlProducts(AgentAccount $agentAccount)
    {
        if ($agentAccount->agent->agent_code !== 'DHL') {
            return response()->json(['message' => 'บัญชีนี้ไม่ใช่ DHL'], 422);
        }
        if (! $agentAccount->basic_auth_username || ! $agentAccount->basic_auth_password) {
            return response()->json(['message' => 'บัญชีนี้ไม่มี Basic Auth Username / Password'], 422);
        }

        try {
            $products = $this->dhlRateService->listAvailableProducts([
                'basic_auth_username' => $agentAccount->basic_auth_username,
                'basic_auth_password' => $agentAccount->basic_auth_password,
                'mode' => $agentAccount->mode,
            ]);

            return response()->json($products);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ดึงรายการ Product ของ DHL ไม่สำเร็จ: ' . $e->getMessage()], 422);
        }
    }

    private function testUps(AgentAccount $account)
    {
        if (! $account->client_id || ! $account->client_secret) {
            return response()->json(['success' => false, 'message' => 'บัญชีนี้ไม่มี Client ID / Client Secret'], 422);
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($account->client_id, $account->client_secret)
                ->timeout(15)
                ->post($account->mode === 'test' ? config('services.ups.oauth_url_test') : config('services.ups.oauth_url'), ['grant_type' => 'client_credentials']);

            if ($response->successful() && $response->json('access_token')) {
                return response()->json(['success' => true, 'message' => 'เชื่อมต่อ UPS OAuth สำเร็จ — Client ID/Secret ใช้งานได้']);
            }

            return response()->json([
                'success' => false,
                'message' => 'เชื่อมต่อ UPS ไม่สำเร็จ (HTTP ' . $response->status() . ')',
                'detail' => $response->json('response.errors.0.message') ?? $response->json('error_description'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'เกิดข้อผิดพลาดขณะเชื่อมต่อ UPS: ' . $e->getMessage()], 200);
        }
    }

    private function testDhl(AgentAccount $account)
    {
        if (! $account->basic_auth_username || ! $account->basic_auth_password) {
            return response()->json(['success' => false, 'message' => 'บัญชีนี้ไม่มี Basic Auth Username / Password'], 422);
        }

        $body = [
            'customerDetails' => [
                'shipperDetails' => ['postalCode' => '10110', 'cityName' => 'Bangkok', 'countryCode' => 'TH'],
                'receiverDetails' => ['postalCode' => '238874', 'cityName' => 'Singapore', 'countryCode' => 'SG'],
            ],
            'accounts' => [['typeCode' => 'shipper', 'number' => $account->username_acc]],
            'plannedShippingDateAndTime' => now()->toIso8601String(),
            'unitOfMeasurement' => 'metric',
            'isCustomsDeclarable' => true,
            'productCode' => 'P',
            'packages' => [['weight' => 1, 'dimensions' => ['length' => 10, 'width' => 10, 'height' => 10]]],
        ];

        try {
            $response = Http::withBasicAuth($account->basic_auth_username, $account->basic_auth_password)
                ->timeout(15)
                ->post(($account->mode === 'test' ? config('services.dhl.api_url_test') : config('services.dhl.api_url')) . '/rates', $body);

            if ($response->successful() && $response->json('products')) {
                return response()->json(['success' => true, 'message' => 'เชื่อมต่อ DHL สำเร็จ — Basic Auth ใช้งานได้']);
            }

            return response()->json([
                'success' => false,
                'message' => 'เชื่อมต่อ DHL ไม่สำเร็จ (HTTP ' . $response->status() . ')',
                'detail' => $response->json('detail') ?? $response->json('title'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'เกิดข้อผิดพลาดขณะเชื่อมต่อ DHL: ' . $e->getMessage()], 200);
        }
    }
}

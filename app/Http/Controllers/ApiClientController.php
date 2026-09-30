<?php

namespace App\Http\Controllers;

use App\Http\Controllers\PublicApi\RateController;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Services\PublicRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Config › Public API (`config.api_clients`): API keys for external sites + their usage. */
class ApiClientController extends Controller
{
    public function index()
    {
        $since = now()->subDays(30);
        $usage = ApiRequestLog::where('created_at', '>=', $since)
            ->groupBy('api_client_id')
            ->selectRaw('api_client_id, COUNT(*) as calls, SUM(CASE WHEN status_code = 200 THEN 0 ELSE 1 END) as failed')
            ->get()->keyBy('api_client_id');

        return ApiClient::with('branch:id,name,code')->orderBy('name')->get()->map(fn (ApiClient $c) => $c->toArray() + [
            'calls_30d' => (int) ($usage[$c->id]->calls ?? 0),
            'failed_30d' => (int) ($usage[$c->id]->failed ?? 0),
        ]);
    }

    /** Returns the plain `api_key` once — it can't be read back later. */
    public function store(Request $request)
    {
        $client = ApiClient::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['client' => $client->load('branch:id,name,code'), 'api_key' => $client->issueKey()], 201);
    }

    public function update(Request $request, ApiClient $apiClient)
    {
        $apiClient->update($this->validated($request));

        return $apiClient->load('branch:id,name,code');
    }

    /** Replaces the key — the old one stops working immediately. */
    public function regenerate(ApiClient $apiClient)
    {
        return response()->json(['client' => $apiClient->load('branch:id,name,code'), 'api_key' => $apiClient->issueKey()]);
    }

    public function destroy(ApiClient $apiClient)
    {
        $apiClient->delete();

        return response()->noContent();
    }

    public function logs(Request $request)
    {
        $data = $request->validate(['api_client_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:ok,failed'], 'endpoint' => ['nullable', 'in:rates,tracking']]);
        $query = ApiRequestLog::with('apiClient:id,name')->latest('created_at')->latest('id');
        foreach (['api_client_id', 'endpoint'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (($data['status'] ?? null) === 'ok') {
            $query->where('status_code', 200);
        } elseif (($data['status'] ?? null) === 'failed') {
            $query->where('status_code', '!=', 200);
        }

        return response()->json($query->paginate(30));
    }

    /** Runs a quote exactly as the public endpoint would, for checking the setup from the UI. */
    public function test(Request $request, ApiClient $apiClient, PublicRateService $rates)
    {
        $input = $request->validate([
            'destination.country' => ['required', 'string', 'size:2'],
            'destination.city' => ['nullable', 'string', 'max:100'],
            'destination.postcode' => ['nullable', 'string', 'max:20'],
            'shipment_type' => ['required', 'in:document,parcel'],
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.weight' => ['required', 'numeric', 'min:0.1'],
            'packages.*.length' => ['nullable', 'numeric'],
            'packages.*.width' => ['nullable', 'numeric'],
            'packages.*.height' => ['nullable', 'numeric'],
            'packages.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['options' => $rates->quote($apiClient, $input), 'disclaimer' => RateController::DISCLAIMER]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'origin_city' => ['required', 'string', 'max:100'],
            'origin_postcode' => ['required', 'regex:/^\d{5}$/'],
            'carriers' => ['nullable', 'array'],
            'carriers.*' => [Rule::in(['UPS', 'DHL'])],
            'max_results' => ['required', 'integer', 'min:1', 'max:20'],
            'price_rounding' => ['required', 'integer', Rule::in([0, 1, 5, 10, 50, 100])],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'end_user_limit_per_minute' => ['required', 'integer', 'min:0', 'max:1000'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['string', 'max:50', function ($attribute, $value, $fail) {
                [$ip, $mask] = array_pad(explode('/', $value, 2), 2, null);
                if (! filter_var($ip, FILTER_VALIDATE_IP) || ($mask !== null && ! ctype_digit($mask))) {
                    $fail("{$value} ไม่ใช่ IP / CIDR ที่ถูกต้อง");
                }
            }],
            'allow_rates' => ['boolean'],
            'allow_tracking' => ['boolean'],
            'status' => ['boolean'],
        ]);
        $data['carriers'] = $data['carriers'] ?? null ?: null;
        $data['allowed_ips'] = array_values(array_filter($data['allowed_ips'] ?? [])) ?: null;

        return $data;
    }
}

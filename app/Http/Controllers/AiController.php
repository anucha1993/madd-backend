<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Services\DhlRateService;
use App\Services\OpenAiService;
use App\Services\UpsRateService;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private OpenAiService $openAi,
        private UpsRateService $upsRateService,
        private DhlRateService $dhlRateService,
    ) {
    }

    public function settings()
    {
        return response()->json([
            'is_configured' => $this->openAi->isConfigured(),
            'masked_api_key' => $this->openAi->maskedApiKey(),
            'is_enabled' => $this->openAi->isEnabled(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['api_key' => ['required', 'string', 'max:255']]);
        $this->openAi->setApiKey($data['api_key']);

        return response()->json([
            'message' => 'บันทึก API Key เรียบร้อย',
            'is_configured' => true,
            'masked_api_key' => $this->openAi->maskedApiKey(),
            'is_enabled' => $this->openAi->isEnabled(),
        ]);
    }

    public function toggleEnabled(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->openAi->setEnabled($data['enabled']);

        return response()->json([
            'message' => $data['enabled'] ? 'เปิดใช้งาน AI แล้ว' : 'ปิดใช้งาน AI แล้ว',
            'is_configured' => $this->openAi->isConfigured(),
            'masked_api_key' => $this->openAi->maskedApiKey(),
            'is_enabled' => $this->openAi->isEnabled(),
        ]);
    }

    /**
     * Parse a freely-pasted address (any language/format) into structured shipment
     * form fields, using the AI model instead of the fixed Thai-postcode regex parser.
     */
    public function parseAddress(Request $request)
    {
        if (! $this->openAi->isEnabled()) {
            return response()->json(['message' => 'การใช้งาน AI ถูกปิดใช้งานอยู่ขณะนี้'], 403);
        }

        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        $systemPrompt = <<<'PROMPT'
            You extract shipping address fields from freely-formatted, possibly messy text
            (any language, Thai or international). Respond with ONLY a JSON object with these
            exact keys (use null for anything not present, never omit a key):
            {
              "contact_name": string|null,
              "company": string|null,
              "address1": string|null,
              "address2": string|null,
              "subdistrict": string|null,
              "district": string|null,
              "city": string|null,
              "province": string|null,
              "postal_code": string|null,
              "country_iso2": string|null,
              "phone": string|null,
              "email": string|null
            }
            For Thai addresses, "subdistrict" is the ตำบล/แขวง (e.g. "Phlapphla") and "district" is
            the อำเภอ/เขต (e.g. "Wang Thonglang") — extract these as their OWN separate fields, do
            NOT fold them into "address2" or "city". "address2" should only contain building/floor/
            room/moo/soi-level details that are not already captured by address1, subdistrict, or
            district. "city" is the province/state-level area (e.g. "Bangkok"); for non-Thai
            addresses without a subdistrict/district concept, leave those two keys null.
            country_iso2 must be a 2-letter ISO 3166-1 alpha-2 code (e.g. "TH", "SG", "US") if a
            country can be determined, otherwise null. Do not invent information not implied by
            the input text.
            PROMPT;

        try {
            $fields = $this->openAi->chatJson($systemPrompt, $data['text']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($fields);
    }

    /**
     * Natural-language rate Q&A: extract destination/package details from the user's
     * message, quote live UPS/DHL rates from a fixed Bangkok origin, then have the
     * model turn the quotes into a conversational answer to the original question.
     */
    public function rateChat(Request $request)
    {
        if (! $this->openAi->isEnabled()) {
            return response()->json(['message' => 'การใช้งาน AI ถูกปิดใช้งานอยู่ขณะนี้'], 403);
        }

        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        $extractPrompt = <<<'PROMPT'
            Extract shipping-rate-quote parameters from the user's message (Thai or English).
            Respond with ONLY a JSON object with these exact keys (use null when not stated):
            {
              "destination_country_iso2": string|null,
              "destination_city": string|null,
              "destination_postal_code": string|null,
              "weight_kg": number|null,
              "length_cm": number|null,
              "width_cm": number|null,
              "height_cm": number|null,
              "quantity": number|null,
              "is_document": boolean|null
            }
            destination_country_iso2 must be a 2-letter ISO 3166-1 alpha-2 code (e.g. "SG", "US")
            if a destination country can be determined, otherwise null. Never invent values not
            implied by the text.
            PROMPT;

        try {
            $extracted = $this->openAi->chatJson($extractPrompt, $data['message']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $destinationCountry = strtoupper((string) ($extracted['destination_country_iso2'] ?? ''));
        if (strlen($destinationCountry) !== 2 || $destinationCountry === 'TH') {
            return response()->json([
                'reply' => 'ขอโทษครับ กรุณาระบุประเทศปลายทางให้ชัดเจน เช่น "ส่งไปสิงคโปร์ น้ำหนัก 2 กก. ราคาเท่าไหร่"',
                'extracted' => $extracted,
                'quotes' => [],
            ]);
        }

        $isDocument = (bool) ($extracted['is_document'] ?? false);
        $weight = (float) ($extracted['weight_kg'] ?? 1);
        $package = [
            'weight' => $weight > 0 ? $weight : 1,
            'length' => $extracted['length_cm'] ?? ($isDocument ? null : 20),
            'width' => $extracted['width_cm'] ?? ($isDocument ? null : 15),
            'height' => $extracted['height_cm'] ?? ($isDocument ? null : 10),
            'quantity' => max(1, (int) ($extracted['quantity'] ?? 1)),
            'isDocument' => $isDocument,
        ];

        // Quick estimate uses a fixed Bangkok origin; the full Create Shipment form
        // gives an exact quote once the real pickup address is known.
        $destinationCity = $extracted['destination_city'] ?? $destinationCountry;
        $shipment = [
            'from' => ['country' => 'TH', 'city' => 'Bangkok', 'postcode' => '10110', 'address' => 'Bangkok'],
            'to' => [
                'country' => $destinationCountry,
                'city' => $destinationCity,
                'postcode' => $extracted['destination_postal_code'] ?? '',
                // DHL's rate API rejects an empty addressLine1 — the city name is a
                // reasonable placeholder since no street address exists at this stage.
                'address' => $destinationCity,
            ],
            'packages' => [$package],
        ];

        $accounts = AgentAccount::with('agent')->where('status', true)->get();
        $upsAccounts = $accounts->filter(fn ($a) => $a->agent?->agent_code === 'UPS' && $a->client_id && $a->client_secret);
        $dhlAccounts = $accounts->filter(fn ($a) => $a->agent?->agent_code === 'DHL' && $a->basic_auth_username && $a->basic_auth_password);

        $serviceCodes = array_map('strval', array_keys($this->upsRateService->serviceLabels()));

        $upsResults = $this->upsRateService->quoteAccounts(
            $upsAccounts->map(fn ($a) => [
                'id' => $a->id,
                'username_acc' => $a->username_acc,
                'client_id' => $a->client_id,
                'client_secret' => $a->client_secret,
                'mode' => $a->mode,
            ])->values()->all(),
            $shipment,
            $serviceCodes,
        );

        $dhlResults = $this->dhlRateService->quoteAccounts(
            $dhlAccounts->map(fn ($a) => [
                'id' => $a->id,
                'username_acc' => $a->username_acc,
                'basic_auth_username' => $a->basic_auth_username,
                'basic_auth_password' => $a->basic_auth_password,
                'mode' => $a->mode,
            ])->values()->all(),
            $shipment,
        );

        $okResults = array_values(array_filter([...$upsResults, ...$dhlResults], fn ($r) => empty($r['error'])));
        usort($okResults, fn ($a, $b) => ($a['negotiated'] ?? $a['published'] ?? PHP_INT_MAX) <=> ($b['negotiated'] ?? $b['published'] ?? PHP_INT_MAX));

        // Pick the cheapest few PER CARRIER so both UPS and DHL show up in the reply
        // whenever both have valid quotes, instead of one carrier drowning out the other.
        $upsTop = array_slice(array_values(array_filter($okResults, fn ($r) => $r['carrier'] === 'UPS')), 0, 3);
        $dhlTop = array_slice(array_values(array_filter($okResults, fn ($r) => $r['carrier'] === 'DHL')), 0, 3);
        $topResults = [...$upsTop, ...$dhlTop];
        usort($topResults, fn ($a, $b) => ($a['negotiated'] ?? $a['published'] ?? PHP_INT_MAX) <=> ($b['negotiated'] ?? $b['published'] ?? PHP_INT_MAX));

        $replyPrompt = <<<'PROMPT'
            You are a friendly shipping-rate assistant for a Thai logistics company. Answer in
            Thai, conversational but concise (3-8 sentences). You are given a JSON list of
            shipping quotes (carrier, service, account username, price, currency, transitDays,
            estimatedDelivery) plus the user's original question. ALWAYS show BOTH carriers
            (UPS and DHL) separately if both appear in the data — never omit one carrier just
            because the other is cheaper. For each carrier, mention the cheapest option's
            service name, the account username it came from, the price, and if transitDays or
            estimatedDelivery is present, mention the estimated transit time / delivery date
            too — but ONLY mention transit time/delivery date when the transitDays or
            estimatedDelivery field is actually present and non-null for that specific quote;
            if both are null/absent, do not state or guess a transit time for it. If a carrier
            has no valid quotes in the data, say so briefly for that carrier instead of skipping
            it silently. Mention this is an estimate from a standard Bangkok origin (actual
            price and transit time may vary with the real pickup address). If the list is empty
            entirely, apologize and explain briefly why (e.g. no active carrier accounts, or
            destination not understood). Never invent a price, transit time, delivery date,
            carrier, or account not present in the data.
            PROMPT;

        $context = json_encode([
            'destination' => $destinationCountry,
            'package' => $package,
            'hasUpsAccounts' => $upsAccounts->isNotEmpty(),
            'hasDhlAccounts' => $dhlAccounts->isNotEmpty(),
            'quotes' => $topResults,
        ], JSON_UNESCAPED_UNICODE);


        try {
            $reply = $this->openAi->chatText($replyPrompt, "User question: {$data['message']}\n\nData: {$context}");
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'reply' => $reply,
            'extracted' => $extracted,
            'quotes' => $topResults,
        ]);
    }
}

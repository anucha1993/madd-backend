<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\Country;
use App\Services\OpenAiService;
use App\Services\PublicRateService;
use App\Services\PublicTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/public/v1/ai-chat — the website's chat assistant (called server-to-server by the MADD
 * WordPress site with its API key; the visitor's IP comes in X-End-User-IP).
 *
 * The site sends the conversation plus a plain-text digest of its own pages ("knowledge"). The model
 * answers from that digest and, for live data, calls two tools that run the same code as the public
 * endpoints: get_shipping_rates (PublicRateService — the key's sell prices, never made up) and
 * track_shipment (PublicTrackingService, MADD shipments and — if the key allows — any UPS/DHL number).
 * Tool results are also returned as structured data so the site can show quote / tracking cards.
 *
 * Needs "allow_rates" on the key (tracking additionally needs "allow_tracking"). Spend is capped per
 * visitor IP and per site per day.
 */
class AiChatController extends Controller
{
    private const PER_IP_PER_MINUTE = 10;

    private const PER_IP_PER_DAY = 80;

    private const PER_SITE_PER_DAY = 3000;

    private const MAX_TOOL_ROUNDS = 4;

    private const PROMPT = <<<'PROMPT'
        You are the friendly online assistant of MADD Co., Ltd — an official UPS Authorized Shipping
        Outlet in Bangkok (since 1999) that ships parcels and documents FROM Thailand to worldwide
        destinations with UPS, DHL Express and Sagawa Express (Japan), with 6 branches in Bangkok.

        How to answer:
        - Reply in the customer's language (Thai or English), naturally and briefly, like a helpful
          shop assistant. Use short paragraphs or a few bullets; no long essays.
        - Answer questions about MADD from the WEBSITE CONTENT below only. If the answer isn't there,
          say so and suggest contacting the team (LINE madd.co.th, tel. +66 2 629 4441-2).
        - When a page in the website content is relevant, link it with a markdown link [title](url).
        - PRICES: never state, estimate or recall a price yourself. To give a price, call
          get_shipping_rates. As soon as you know the destination country and a weight, CALL THE
          TOOL RIGHT AWAY — do not ask the customer to confirm, and never wait for the box size (it
          is optional; mention afterwards that a box size makes the price more accurate). Only ask a
          question when the country or the weight is missing. If the customer names the items but no
          weight, DO NOT ask — estimate a realistic packed weight and box yourself (e.g. a pair of
          shoes ≈ 1.2 kg in 35×25×13 cm, a smartphone ≈ 0.5 kg, a T-shirt ≈ 0.25 kg, documents ≈
          0.5 kg as "document"), call the tool, and say what you assumed. The website
          shows every option the tool returns as a price card under your reply, so DON'T list them
          all: in 2–3 sentences give the cheapest option (carrier, service, THB, days), what you
          assumed, and that prices are estimates confirmed at the counter.
        - TRACKING: call track_shipment with the number the customer gives (UPS "1Z…" or DHL 10
          digits). Summarise the status, latest event and estimated delivery in plain words.
        - Origin is always Thailand. Never invent services, branches, opening hours or policies.
        PROMPT;

    public function __construct(private OpenAiService $openAi, private PublicRateService $rates, private PublicTrackingService $tracking) {}

    public function chat(Request $request)
    {
        /** @var ApiClient $client */
        $client = $request->attributes->get('api_client');
        $ip = (string) $request->attributes->get('end_user_ip');
        $log = ['api_client_id' => $client->id, 'endpoint' => 'ai_chat', 'ip' => $request->ip(), 'end_user_ip' => $ip];

        if (! $client->allow_rates) {
            return $this->fail($log, 403, 'endpoint_not_allowed');
        }
        if (! $this->openAi->isEnabled()) {
            return $this->fail($log, 503, 'ai_disabled');
        }
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
            'knowledge' => ['nullable', 'string', 'max:60000'],
        ]);
        $log['reference'] = mb_substr((string) end($data['messages'])['content'], 0, 50);

        foreach ([
            ["ai-chat:ip-min:{$ip}", self::PER_IP_PER_MINUTE, 60],
            ["ai-chat:ip-day:{$ip}", self::PER_IP_PER_DAY, 86400],
            ["ai-chat:site-day:{$client->id}", self::PER_SITE_PER_DAY, 86400],
        ] as [$bucket, $limit, $decay]) {
            if (! RateLimiter::attempt($bucket, $limit, fn () => true, $decay)) {
                return $this->fail($log, 429, 'rate_limited');
            }
        }

        $messages = [['role' => 'system', 'content' => self::PROMPT."\n\nWEBSITE CONTENT:\n".($data['knowledge'] ?? '(none)')]];
        foreach (array_slice($data['messages'], -12) as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $quotes = [];
        $tracking = [];
        try {
            for ($round = 0; ; $round++) {
                $reply = $this->openAi->complete($messages, $round < self::MAX_TOOL_ROUNDS ? $this->tools($client) : []);
                if (empty($reply['tool_calls'])) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $reply['content'] ?? null, 'tool_calls' => $reply['tool_calls']];
                foreach ($reply['tool_calls'] as $call) {
                    $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                    $result = match ($call['function']['name'] ?? '') {
                        'get_shipping_rates' => $this->rateTool($client, $args, $quotes),
                        'track_shipment' => $this->trackTool($client, $args, $tracking),
                        default => ['error' => 'unknown tool'],
                    };
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($log, 502, 'ai_unavailable', $e->getMessage());
        }

        $this->log($log + ['status_code' => 200, 'result_count' => count($quotes) + count($tracking)]);

        return response()->json([
            'reply' => trim((string) ($reply['content'] ?? '')),
            'quotes' => $quotes,
            'tracking' => $tracking,
        ]);
    }

    private function tools(ApiClient $client): array
    {
        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'get_shipping_rates',
                'description' => 'Live MADD selling prices (THB) for UPS / DHL from Thailand to a destination.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'country' => ['type' => 'string', 'description' => 'Destination ISO 3166-1 alpha-2 code, not TH'],
                        'city' => ['type' => 'string'],
                        'postcode' => ['type' => 'string'],
                        'shipment_type' => ['type' => 'string', 'enum' => ['parcel', 'document']],
                        'packages' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'weight_kg' => ['type' => 'number'], 'length_cm' => ['type' => 'number'], 'width_cm' => ['type' => 'number'],
                                    'height_cm' => ['type' => 'number'], 'quantity' => ['type' => 'integer'],
                                ],
                                'required' => ['weight_kg'],
                            ],
                        ],
                    ],
                    'required' => ['country', 'shipment_type', 'packages'],
                ],
            ],
        ]];
        if ($client->allow_tracking) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'track_shipment',
                    'description' => 'Current status and scan history of a UPS or DHL shipment.',
                    'parameters' => ['type' => 'object', 'properties' => ['tracking_number' => ['type' => 'string']], 'required' => ['tracking_number']],
                ],
            ];
        }

        return $tools;
    }

    /** Same input rules and cache as POST /public/v1/rates; the model's arguments are clamped, never trusted. */
    private function rateTool(ApiClient $client, array $args, array &$quotes): array
    {
        $num = fn ($v, float $min, float $max) => is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : null;
        $country = strtoupper((string) ($args['country'] ?? ''));
        if (! preg_match('/^[A-Z]{2}$/', $country) || $country === 'TH' || ! Country::where('iso2', $country)->where('status', true)->exists()) {
            return ['error' => 'Unknown or unsupported destination country — ask the customer which country.'];
        }
        $isDocument = ($args['shipment_type'] ?? '') === 'document';
        $packages = [];
        foreach (array_slice((array) ($args['packages'] ?? []), 0, 20) as $p) {
            $w = $num($p['weight_kg'] ?? null, 0.1, 300);
            if ($w === null) {
                continue;
            }
            $packages[] = array_filter([
                'weight' => $w,
                'length' => $isDocument ? null : $num($p['length_cm'] ?? null, 1, 300),
                'width' => $isDocument ? null : $num($p['width_cm'] ?? null, 1, 300),
                'height' => $isDocument ? null : $num($p['height_cm'] ?? null, 1, 300),
                'quantity' => (int) max(1, min(50, (int) ($p['quantity'] ?? 1))),
            ], fn ($v) => $v !== null);
        }
        if (! $packages) {
            return ['error' => 'Weight is missing — ask the customer how heavy the parcel is.'];
        }
        $input = [
            'destination' => array_filter([
                'country' => $country,
                'city' => isset($args['city']) && is_string($args['city']) ? mb_substr($args['city'], 0, 100) : null,
                'postcode' => isset($args['postcode']) && is_scalar($args['postcode']) ? mb_substr((string) $args['postcode'], 0, 20) : null,
            ]),
            'shipment_type' => $isDocument ? 'document' : 'parcel',
            'packages' => $packages,
        ];
        $cacheKey = 'public-rates:'.$client->id.':'.$client->updated_at?->timestamp.':'.md5(json_encode($input));
        $options = Cache::get($cacheKey);
        if ($options === null) {
            $options = $this->rates->quote($client, $input);
            if ($options) {
                Cache::put($cacheKey, $options, now()->addMinutes(15));
            }
        }
        $quotes[] = ['destination' => $input['destination'], 'shipment_type' => $input['shipment_type'], 'packages' => $packages, 'options' => $options];
        if (! $options) {
            return ['error' => 'No price available for this shipment right now — suggest contacting the team.'];
        }

        return ['currency' => 'THB', 'note' => RateController::DISCLAIMER, 'options' => array_map(fn ($o) => [
            'carrier' => $o['carrier'], 'service' => $o['service_name'], 'price' => $o['price'], 'transit_days' => $o['transit_days'], 'estimated_delivery' => $o['estimated_delivery'],
        ], $options)];
    }

    /** Same rules as GET /public/v1/tracking/{number}, including the external-number daily cap. */
    private function trackTool(ApiClient $client, array $args, array &$tracking): array
    {
        $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($args['tracking_number'] ?? '')));
        if (! preg_match('/^[A-Z0-9]{8,40}$/', $number)) {
            return ['error' => 'That does not look like a tracking number.'];
        }
        try {
            $shipment = $this->tracking->find($number);
            if ($shipment) {
                $key = 'public-tracking:'.$number.':'.$shipment->status;
                $data = Cache::remember($key, now()->addMinutes(10), fn () => $this->tracking->track($shipment, $number));
            } else {
                if (! $client->track_any_number || ! $this->tracking->detectCarrier($number)) {
                    return ['error' => 'Tracking number not found.'];
                }
                $key = 'public-tracking-ext:'.$number;
                $data = Cache::get($key);
                if ($data === null) {
                    if ($client->external_tracking_daily_limit > 0 && ! RateLimiter::attempt("external-tracking:{$client->id}", $client->external_tracking_daily_limit, fn () => true, 86400)) {
                        return ['error' => 'Tracking is busy today — suggest the Check Status page or contacting the team.'];
                    }
                    $data = $this->tracking->trackExternal($number) ?? false;
                    Cache::put($key, $data, now()->addMinutes(10));
                }
                if ($data === false) {
                    return ['error' => 'Tracking number not found.'];
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'The carrier did not answer — try again later.'];
        }
        $tracking[] = $data;
        $data['events'] = array_slice($data['events'] ?? [], 0, 5); // the model only needs the latest scans

        return $data;
    }

    private function fail(array $log, int $status, string $code, ?string $detail = null)
    {
        $this->log($log + ['status_code' => $status, 'error' => mb_substr($detail ?? $code, 0, 500)]);

        return response()->json(['error' => ['code' => $code]], $status);
    }

    private function log(array $attributes): void
    {
        try {
            ApiRequestLog::create($attributes);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

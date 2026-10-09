<?php

namespace App\Support;

use App\Models\AgentAccount;
use App\Models\IntegrationSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rate Book settings, stored as one JSON IntegrationSetting. Missing pieces are filled from
 * defaults() every time they're read, so a new zone in /config/countries or a never-saved
 * install still gets a sensible setup.
 *
 * Bands follow the business's pre-system rate book: each band either lists a price every
 * `step` kg (e.g. 0.5, 1.0 ... 5.0) or — when step is null — one price PER KG, quoted at the
 * band's first whole kg. Above 10 kg nothing is sold at a fractional weight, so those points are
 * always whole kg (10.01 → 11). Each band names the account whose sell price it shows (the
 * cheapest account for that weight is the one actually sold, e.g. ax3173 up to 10 kg);
 * account_rules override it for one zone from a weight up (e.g. DHL zone 7 from 14 kg).
 */
class RateBookSettings
{
    private const KEY = 'rate_book.settings';

    public const LAST_RUN_KEY = 'rate_book.last_run_at';

    public const REQUESTED_KEY = 'rate_book.requested_by';

    public const CARRIERS = ['UPS', 'DHL'];

    public const PACKAGE_TYPES = ['document', 'box'];

    /**
     * Representative destination per zone — the rate API needs a real city/postcode. First
     * country in this list that sits in a zone wins; staff can change it per zone.
     */
    private const PREFERRED_ADDRESSES = [
        ['iso2' => 'HK', 'city' => 'Hong Kong', 'postcode' => '', 'state_code' => ''],
        ['iso2' => 'SG', 'city' => 'Singapore', 'postcode' => '238874', 'state_code' => ''],
        ['iso2' => 'MY', 'city' => 'Kuala Lumpur', 'postcode' => '50450', 'state_code' => ''],
        ['iso2' => 'CN', 'city' => 'Shanghai', 'postcode' => '200000', 'state_code' => ''],
        ['iso2' => 'TW', 'city' => 'Taipei', 'postcode' => '100', 'state_code' => ''],
        ['iso2' => 'JP', 'city' => 'Tokyo', 'postcode' => '100-0001', 'state_code' => ''],
        ['iso2' => 'KR', 'city' => 'Seoul', 'postcode' => '04524', 'state_code' => ''],
        ['iso2' => 'AU', 'city' => 'Sydney', 'postcode' => '2000', 'state_code' => 'NSW'],
        ['iso2' => 'US', 'city' => 'New York', 'postcode' => '10118', 'state_code' => 'NY'],
        ['iso2' => 'GB', 'city' => 'London', 'postcode' => 'EC1A 1BB', 'state_code' => ''],
        ['iso2' => 'DE', 'city' => 'Berlin', 'postcode' => '10115', 'state_code' => ''],
        ['iso2' => 'FR', 'city' => 'Paris', 'postcode' => '75001', 'state_code' => ''],
        ['iso2' => 'CA', 'city' => 'Toronto', 'postcode' => 'M5H 2N2', 'state_code' => 'ON'],
        ['iso2' => 'IN', 'city' => 'Mumbai', 'postcode' => '400001', 'state_code' => ''],
        ['iso2' => 'AE', 'city' => 'Dubai', 'postcode' => '', 'state_code' => ''],
        ['iso2' => 'VN', 'city' => 'Ho Chi Minh City', 'postcode' => '700000', 'state_code' => ''],
        ['iso2' => 'PH', 'city' => 'Manila', 'postcode' => '1000', 'state_code' => ''],
        ['iso2' => 'ID', 'city' => 'Jakarta', 'postcode' => '10110', 'state_code' => ''],
        ['iso2' => 'NZ', 'city' => 'Auckland', 'postcode' => '1010', 'state_code' => ''],
        ['iso2' => 'NL', 'city' => 'Amsterdam', 'postcode' => '1012 JS', 'state_code' => ''],
        ['iso2' => 'IT', 'city' => 'Rome', 'postcode' => '00184', 'state_code' => ''],
        ['iso2' => 'ES', 'city' => 'Madrid', 'postcode' => '28001', 'state_code' => ''],
        ['iso2' => 'CH', 'city' => 'Zurich', 'postcode' => '8001', 'state_code' => ''],
        ['iso2' => 'BD', 'city' => 'Dhaka', 'postcode' => '1000', 'state_code' => ''],
        ['iso2' => 'KH', 'city' => 'Phnom Penh', 'postcode' => '120000', 'state_code' => ''],
        ['iso2' => 'MM', 'city' => 'Yangon', 'postcode' => '11181', 'state_code' => ''],
        ['iso2' => 'SA', 'city' => 'Riyadh', 'postcode' => '11564', 'state_code' => ''],
        ['iso2' => 'MX', 'city' => 'Mexico City', 'postcode' => '06000', 'state_code' => ''],
        ['iso2' => 'BR', 'city' => 'Sao Paulo', 'postcode' => '01310-100', 'state_code' => ''],
        ['iso2' => 'ZA', 'city' => 'Johannesburg', 'postcode' => '2000', 'state_code' => ''],
        ['iso2' => 'TR', 'city' => 'Istanbul', 'postcode' => '34000', 'state_code' => ''],
        ['iso2' => 'EG', 'city' => 'Cairo', 'postcode' => '11511', 'state_code' => ''],
        ['iso2' => 'KE', 'city' => 'Nairobi', 'postcode' => '00100', 'state_code' => ''],
        ['iso2' => 'NG', 'city' => 'Lagos', 'postcode' => '100001', 'state_code' => ''],
    ];

    public static function get(): array
    {
        $saved = json_decode((string) IntegrationSetting::get(self::KEY), true);

        return self::withDefaults(is_array($saved) ? $saved : []);
    }

    public static function save(array $settings): array
    {
        $settings = self::withDefaults($settings);
        IntegrationSetting::set(self::KEY, json_encode($settings, JSON_UNESCAPED_UNICODE));

        return $settings;
    }

    /** Zones in use per carrier (from countries.ups_zone / dhl_zone), numeric-sorted. */
    public static function zones(string $carrier): array
    {
        $column = strtolower($carrier).'_zone';
        $zones = DB::table('countries')->whereNotNull($column)->where($column, '!=', '')->distinct()->pluck($column)->all();
        natsort($zones);

        return array_values(array_map('strval', $zones));
    }

    /** Is a scheduled run due now (settings enabled + day/time reached + not yet run this period)? */
    public static function isDue(array $settings, ?string $lastRunAt, Carbon $now): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }
        [$hour, $minute] = array_map('intval', explode(':', $settings['time'] ?? '02:00') + [0, 0]);
        $monthly = ($settings['frequency'] ?? 'weekly') === 'monthly';
        $slot = $monthly
            ? $now->copy()->startOfMonth()->addDays(min(max((int) ($settings['day_of_month'] ?? 1), 1), 28) - 1)
            : $now->copy()->startOfWeek()->addDays(min(max((int) ($settings['day_of_week'] ?? 1), 1), 7) - 1);
        $slot->setTime($hour, $minute);
        if ($now->lt($slot)) {
            return false;
        }

        return ! $lastRunAt || Carbon::parse($lastRunAt)->lt($slot);
    }

    private static function withDefaults(array $settings): array
    {
        $defaults = self::defaults();
        $merged = [
            'enabled' => (bool) ($settings['enabled'] ?? $defaults['enabled']),
            'frequency' => in_array($settings['frequency'] ?? null, ['weekly', 'monthly'], true) ? $settings['frequency'] : $defaults['frequency'],
            'day_of_week' => (int) ($settings['day_of_week'] ?? $defaults['day_of_week']),
            'day_of_month' => (int) ($settings['day_of_month'] ?? $defaults['day_of_month']),
            'time' => preg_match('/^\d{2}:\d{2}$/', (string) ($settings['time'] ?? '')) ? $settings['time'] : $defaults['time'],
            'vat_percent' => (float) ($settings['vat_percent'] ?? $defaults['vat_percent']),
            'carriers' => [],
        ];

        foreach (self::CARRIERS as $carrier) {
            $saved = $settings['carriers'][$carrier] ?? [];
            $default = $defaults['carriers'][$carrier];
            $extraColumns = array_values($saved['extra_columns'] ?? $default['extra_columns']);
            $zoneCountries = [];
            foreach (self::zones($carrier) as $zone) {
                $zoneCountries[$zone] = $saved['zone_countries'][$zone]
                    ?? self::defaultZoneAddress($carrier, $zone, array_column($extraColumns, 'iso2'));
            }
            $merged['carriers'][$carrier] = [
                'enabled' => (bool) ($saved['enabled'] ?? $default['enabled']),
                'service_codes' => array_merge($default['service_codes'], array_filter($saved['service_codes'] ?? [])),
                'bands' => [
                    'document' => $saved['bands']['document'] ?? $default['bands']['document'],
                    'box' => $saved['bands']['box'] ?? $default['bands']['box'],
                ],
                'zone_countries' => $zoneCountries,
                'account_rules' => array_values($saved['account_rules'] ?? $default['account_rules']),
                'extra_columns' => $extraColumns,
                'zone_labels' => $saved['zone_labels'] ?? $default['zone_labels'],
            ];
        }

        return $merged;
    }

    private static function defaults(): array
    {
        $ax = self::accountId('UPS', 'ax3173');
        $ups = self::accountId('UPS', '0279v4');
        $dhl = self::accountId('DHL', null);
        $band = fn ($min, $max, $step, $accountId) => ['min' => $min, 'max' => $max, 'step' => $step, 'account_id' => $accountId];

        return [
            'enabled' => false,
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'day_of_month' => 1,
            'time' => '02:00',
            'vat_percent' => 7,
            'carriers' => [
                'UPS' => [
                    'enabled' => true,
                    'service_codes' => ['document' => '65', 'box' => '65'],
                    'account_rules' => [],
                    // Destinations priced apart from their zone in the rate file (SAVE: 1 2 JP AU 3 4 5 USA PR 6 …).
                    'extra_columns' => [
                        self::extraColumn('JP', 'JP', 'Tokyo', '100-0001', '', '2'),
                        self::extraColumn('AU', 'AU', 'Sydney', '2000', 'NSW', 'JP'),
                        self::extraColumn('USA PR', 'US', 'New York', '10118', 'NY', '5'),
                    ],
                    'zone_labels' => [],
                    'bands' => [
                        'document' => [$band(0.01, 5, 0.5, $ax)],
                        'box' => [
                            $band(0.1, 5, 0.5, $ax),
                            $band(5.01, 10, 0.5, $ax),
                            $band(10.01, 20, 1, $ups),
                            $band(20.01, 44, null, $ups),
                            $band(44.01, 70, null, $ups),
                            $band(70.01, 99, null, $ups),
                            $band(99.01, 299, null, $ups),
                            $band(299.01, null, null, $ups),
                        ],
                    ],
                ],
                'DHL' => [
                    'enabled' => true,
                    // DHL Express Worldwide: D = documents, P = non-documents.
                    'service_codes' => ['document' => 'D', 'box' => 'P'],
                    // Zone 7 from 14 kg is cheaper on the second DHL account.
                    'account_rules' => [['zone' => '7', 'min_weight' => 14, 'account_id' => self::accountId('DHL', '566833742', false)]],
                    'extra_columns' => [self::extraColumn('AU NZ', 'AU', 'Sydney', '2000', 'NSW', '3')],
                    'zone_labels' => ['6' => 'Zone 6 US CA MX', '7' => 'Zone 7 EU'],
                    'bands' => [
                        'document' => [$band(0.01, 2, 0.5, $dhl)],
                        'box' => [
                            $band(0.1, 5, 0.5, $dhl),
                            $band(5.01, 10, 0.5, $dhl),
                            $band(10.01, 30, 1, $dhl),
                            $band(30.01, null, null, $dhl),
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function extraColumn(string $label, string $iso2, string $city, string $postcode, string $state, string $after): array
    {
        return ['label' => $label, 'iso2' => $iso2, 'city' => $city, 'postcode' => $postcode, 'state_code' => $state, 'after' => $after];
    }

    /**
     * The rate book's price columns in order: every zone, with the extra columns slotted in after
     * the column named in their `after` (a zone or another extra's label). `key` is what rows are
     * stored under (zone number, or the extra's label); `zone` is the real zone (for account rules).
     *
     * @return list<array{key:string, label:string, zone:string, extra:bool, address:array}>
     */
    public static function columns(array $settings, string $carrier): array
    {
        $carrierSettings = $settings['carriers'][$carrier] ?? [];
        $labels = $carrierSettings['zone_labels'] ?? [];
        $columns = [];
        foreach ($carrierSettings['zone_countries'] ?? [] as $zone => $address) {
            $columns[] = ['key' => (string) $zone, 'label' => $labels[(string) $zone] ?? "Zone {$zone}", 'zone' => (string) $zone, 'extra' => false, 'address' => $address];
        }
        $zoneColumn = strtolower($carrier).'_zone';
        foreach ($carrierSettings['extra_columns'] ?? [] as $extra) {
            if (empty($extra['label']) || empty($extra['iso2'])) {
                continue;
            }
            $column = [
                'key' => (string) $extra['label'],
                'label' => (string) $extra['label'],
                'zone' => (string) (DB::table('countries')->where('iso2', $extra['iso2'])->value($zoneColumn) ?? ''),
                'extra' => true,
                'address' => array_intersect_key($extra, array_flip(['iso2', 'city', 'postcode', 'state_code'])),
            ];
            $at = collect($columns)->search(fn ($c) => $c['key'] === (string) ($extra['after'] ?? ''));
            array_splice($columns, $at === false ? count($columns) : $at + 1, 0, [$column]);
        }

        return $columns;
    }

    private static function accountId(string $agentCode, ?string $username, bool $fallbackToFirst = true): ?int
    {
        $accounts = AgentAccount::with('agent')->where('status', true)->orderBy('id')->get()
            ->filter(fn ($a) => $a->agent?->agent_code === $agentCode);
        $match = $username !== null
            ? $accounts->first(fn ($a) => strcasecmp((string) $a->username_acc, $username) === 0)
            : null;

        return ($match ?? ($fallbackToFirst ? $accounts->first() : null))?->id;
    }

    /** A zone's representative destination — never a country that already has its own extra column. */
    private static function defaultZoneAddress(string $carrier, string $zone, array $exclude = []): array
    {
        $column = strtolower($carrier).'_zone';
        $inZone = DB::table('countries')->where($column, $zone)->orderBy('name')->pluck('iso2')->all();
        foreach ([$exclude, []] as $skip) {
            foreach (self::PREFERRED_ADDRESSES as $address) {
                if (in_array($address['iso2'], $inZone, true) && ! in_array($address['iso2'], $skip, true)) {
                    return $address;
                }
            }
        }

        // No well-known city in this zone — staff must fill in a real city/postcode.
        return ['iso2' => $inZone[0] ?? '', 'city' => '', 'postcode' => '', 'state_code' => ''];
    }
}

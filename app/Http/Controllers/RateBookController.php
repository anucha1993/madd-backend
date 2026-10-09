<?php

namespace App\Http\Controllers;

use App\Models\AgentAccount;
use App\Models\IntegrationSetting;
use App\Models\RateBookRun;
use App\Support\RateBookLauncher;
use App\Support\RateBookRateCard;
use App\Support\RateBookSettings;
use App\Support\RateBookWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rate Book (internal sell-price list): settings + schedule, "Sync now", run history and the
 * Excel export. The sync itself runs in the scheduler (see SyncRateBook).
 */
class RateBookController extends Controller
{
    public function settings()
    {
        $countries = DB::table('countries')->orderBy('name')->get(['iso2', 'name', 'ups_zone', 'dhl_zone']);

        return response()->json([
            'settings' => RateBookSettings::get(),
            'last_run_at' => IntegrationSetting::get(RateBookSettings::LAST_RUN_KEY),
            'sync_requested' => IntegrationSetting::get(RateBookSettings::REQUESTED_KEY) !== null,
            'accounts' => AgentAccount::with('agent')->where('status', true)->orderBy('id')->get()
                ->filter(fn ($a) => in_array($a->agent?->agent_code, RateBookSettings::CARRIERS, true))
                ->map(fn ($a) => ['id' => $a->id, 'carrier' => $a->agent->agent_code, 'username' => $a->username_acc, 'mode' => $a->mode])
                ->values(),
            'zone_countries' => collect(RateBookSettings::CARRIERS)->mapWithKeys(fn ($carrier) => [
                $carrier => $countries->filter(fn ($c) => ($c->{strtolower($carrier).'_zone'} ?? '') !== '')
                    ->groupBy(fn ($c) => (string) $c->{strtolower($carrier).'_zone'})
                    ->map(fn ($group) => $group->map(fn ($c) => ['iso2' => $c->iso2, 'name' => $c->name])->values()),
            ]),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'time' => ['required', 'date_format:H:i'],
            'vat_percent' => ['required', 'numeric', 'between:0,100'],
            'carriers' => ['required', 'array'],
            'carriers.*.enabled' => ['required', 'boolean'],
            'carriers.*.service_codes.document' => ['required', 'string', 'max:10'],
            'carriers.*.service_codes.box' => ['required', 'string', 'max:10'],
            'carriers.*.bands.document' => ['present', 'array'],
            'carriers.*.bands.box' => ['present', 'array'],
            'carriers.*.bands.*.*.min' => ['required', 'numeric', 'min:0'],
            'carriers.*.bands.*.*.max' => ['nullable', 'numeric', 'min:0'],
            'carriers.*.bands.*.*.step' => ['nullable', 'numeric', 'min:0.1'],
            'carriers.*.bands.*.*.account_id' => ['nullable', 'integer', 'exists:agent_accounts,id'],
            'carriers.*.account_rules' => ['present', 'array'],
            'carriers.*.account_rules.*.zone' => ['required', 'string', 'max:20'],
            'carriers.*.account_rules.*.min_weight' => ['required', 'numeric', 'min:0'],
            'carriers.*.account_rules.*.account_id' => ['required', 'integer', 'exists:agent_accounts,id'],
            'carriers.*.extra_columns' => ['present', 'array'],
            'carriers.*.extra_columns.*.label' => ['required', 'string', 'max:20'],
            'carriers.*.extra_columns.*.iso2' => ['required', 'string', 'size:2'],
            'carriers.*.extra_columns.*.city' => ['required', 'string', 'max:100'],
            'carriers.*.extra_columns.*.postcode' => ['nullable', 'string', 'max:20'],
            'carriers.*.extra_columns.*.state_code' => ['nullable', 'string', 'max:10'],
            'carriers.*.extra_columns.*.after' => ['nullable', 'string', 'max:20'],
            'carriers.*.zone_labels' => ['nullable', 'array'],
            'carriers.*.zone_labels.*' => ['nullable', 'string', 'max:40'],
            'carriers.*.zone_countries' => ['present', 'array'],
            'carriers.*.zone_countries.*.iso2' => ['nullable', 'string', 'size:2'],
            'carriers.*.zone_countries.*.city' => ['nullable', 'string', 'max:100'],
            'carriers.*.zone_countries.*.postcode' => ['nullable', 'string', 'max:20'],
            'carriers.*.zone_countries.*.state_code' => ['nullable', 'string', 'max:10'],
        ]);

        foreach ($data['carriers'] as $carrier => $carrierSettings) {
            foreach ($carrierSettings['bands'] as $packageType => $bands) {
                foreach ($bands as $band) {
                    if (isset($band['max']) && $band['max'] !== null && (float) $band['max'] <= (float) $band['min']) {
                        return response()->json(['message' => "{$carrier} {$packageType}: น้ำหนักสูงสุดต้องมากกว่าต่ำสุด ({$band['min']})"], 422);
                    }
                    if (! empty($band['step']) && ($band['max'] ?? null) === null) {
                        return response()->json(['message' => "{$carrier} {$packageType}: ช่วงที่ไม่มีน้ำหนักสูงสุดต้องแสดงเป็นราคาต่อ kg"], 422);
                    }
                }
            }
        }

        return response()->json(['settings' => RateBookSettings::save($data)]);
    }

    /** Leaves a request the scheduler picks up within a minute (see SyncRateBook). */
    /**
     * Leaves the request (who asked) and starts the sync right away in the background — no need to
     * wait for a scheduler tick, or for a scheduler at all. If one is already running, its lock wins.
     */
    public function requestSync(Request $request, RateBookLauncher $launcher)
    {
        IntegrationSetting::set(RateBookSettings::REQUESTED_KEY, (string) $request->user()->id);
        $launcher->launch();

        return response()->json(['message' => 'เริ่ม Sync แล้ว — ใช้เวลาประมาณ 20-30 นาที ดูความคืบหน้าได้ที่ประวัติการ Sync']);
    }

    public function runs()
    {
        RateBookSettings::closeStaleRuns();

        return response()->json(
            RateBookRun::with('requester:id,name')->latest('id')->limit(20)
                ->get(['id', 'status', 'trigger', 'requested_by', 'total_points', 'done_points', 'error_points', 'error', 'started_at', 'finished_at', 'updated_at'])
        );
    }

    /**
     * Stops a running sync. A live process sees the request within a few points and closes the run
     * itself; one whose process is already gone (no progress for 2+ minutes) is closed right here
     * and its lock freed, so "Sync now" works again immediately.
     */
    public function cancel(RateBookRun $rateBookRun)
    {
        if ($rateBookRun->status !== 'running') {
            return response()->json(['message' => 'รอบนี้ไม่ได้กำลัง Sync อยู่'], 422);
        }

        if ($rateBookRun->updated_at->lt(now()->subMinutes(2))) {
            $rateBookRun->update(['status' => 'failed', 'error' => 'ยกเลิกโดยผู้ใช้', 'finished_at' => now()]);
            if (! RateBookRun::where('status', 'running')->exists()) {
                Cache::lock(RateBookSettings::LOCK)->forceRelease();
            }

            return response()->json(['message' => 'ยกเลิกแล้ว']);
        }

        IntegrationSetting::set(RateBookSettings::CANCEL_KEY, (string) $rateBookRun->id);

        return response()->json(['message' => 'กำลังยกเลิก — จะหยุดภายในไม่กี่วินาที']);
    }

    /** One carrier's rows of a run, for the on-screen rate table (same data as the Excel). */
    public function rows(Request $request, RateBookRun $rateBookRun)
    {
        $carrier = strtoupper((string) $request->query('carrier', 'UPS'));
        abort_unless(in_array($carrier, RateBookSettings::CARRIERS, true), 422, 'carrier ต้องเป็น UPS หรือ DHL');

        $rows = $rateBookRun->rows()->where('carrier', $carrier)->orderBy('id')->get([
            'package_type', 'zone', 'country_iso2', 'band_label', 'weight', 'is_per_kg', 'account_username', 'service_code', 'full',
            'freight', 'fuel', 'surge', 'remote', 'peak', 'gogreen', 'other', 'cost', 'markup', 'rounding', 'sell', 'vat', 'total', 'error',
        ]);
        $countryNames = DB::table('countries')->whereIn('iso2', $rows->pluck('country_iso2')->unique())->pluck('name', 'iso2');

        return response()->json([
            'vat_percent' => $rateBookRun->settings['vat_percent'] ?? 7,
            // Price columns in rate-card order (zones + extras like JP / AU / USA PR), only those this run priced.
            'columns' => collect(RateBookSettings::columns($rateBookRun->settings ?? [], $carrier))
                ->filter(fn ($c) => $rows->contains('zone', $c['key']))
                ->map(fn ($c) => [
                    'key' => $c['key'],
                    'label' => $c['label'],
                    'zone' => $c['zone'],
                    'extra' => $c['extra'],
                    'iso2' => $rows->firstWhere('zone', $c['key'])?->country_iso2,
                    'country' => $countryNames[$rows->firstWhere('zone', $c['key'])?->country_iso2] ?? null,
                    // For the rate cards' COUNTRY block (same list as the Excel).
                    'countries' => RateBookRateCard::countryNames($carrier, $c),
                ])
                ->values(),
            'rows' => $rows,
        ]);
    }

    /** One carrier's workbook, laid out like the business's own rate files (see RateBookWorkbook). */
    public function export(Request $request, RateBookRun $rateBookRun): StreamedResponse
    {
        $carrier = strtoupper((string) $request->query('carrier', 'UPS'));
        abort_unless(in_array($carrier, RateBookSettings::CARRIERS, true), 422, 'carrier ต้องเป็น UPS หรือ DHL');

        $spreadsheet = RateBookWorkbook::build($rateBookRun, $carrier);
        $filename = "{$carrier} Rate Book ".($rateBookRun->finished_at ?? $rateBookRun->created_at)->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}

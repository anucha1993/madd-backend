<?php

namespace App\Http\Controllers;

use App\Models\WeightMarkupBand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /config/weight-markups — one band matrix per carrier × document/box × (all accounts | one
 * account): rows = weight bands, columns = the default rate + any zone / country overrides, cells =
 * THB per kg. Saved as a whole (bands change often; editing the matrix is simpler than row CRUD).
 */
class WeightMarkupController extends Controller
{
    public function show(Request $request)
    {
        $data = $this->scope($request);
        $bands = $this->query($data)->with('rates')->orderBy('sort_order')->orderBy('min_weight')->get();

        return response()->json([
            'bands' => $bands->map(fn ($b) => [
                'min_weight' => $b->min_weight,
                'max_weight' => $b->max_weight,
                'rates' => $b->rates->mapWithKeys(fn ($r) => [self::scopeKey($r->scope_type, $r->scope_value) => $r->rate_per_kg]),
            ]),
            // Accounts of this carrier/type that have their own set (instead of the carrier-wide one).
            'account_sets' => WeightMarkupBand::where('carrier', $data['carrier'])->where('package_type', $data['package_type'])
                ->whereNotNull('agent_account_id')->distinct()->pluck('agent_account_id'),
        ]);
    }

    public function save(Request $request)
    {
        $data = $this->scope($request) + $request->validate([
            'bands' => ['present', 'array'],
            'bands.*.min_weight' => ['required', 'numeric', 'min:0'],
            'bands.*.max_weight' => ['nullable', 'numeric', 'min:0'],
            'bands.*.rates' => ['present', 'array'],
            'bands.*.rates.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bands = collect($data['bands'])->sortBy('min_weight')->values();
        foreach ($bands as $i => $band) {
            if ($band['max_weight'] !== null && (float) $band['max_weight'] <= (float) $band['min_weight']) {
                return response()->json(['message' => "ช่วง {$band['min_weight']}: น้ำหนักสูงสุดต้องมากกว่าต่ำสุด"], 422);
            }
            if ($band['max_weight'] === null && $i < $bands->count() - 1) {
                return response()->json(['message' => 'ช่วงที่ไม่มีน้ำหนักสูงสุด ("ขึ้นไป") ต้องเป็นช่วงสุดท้าย'], 422);
            }
            foreach (array_keys($band['rates']) as $key) {
                if (! preg_match('/^(all|zone:[A-Z0-9-]{1,10}|country:[A-Z]{2})$/', (string) $key)) {
                    return response()->json(['message' => "คอลัมน์ไม่ถูกต้อง: {$key}"], 422);
                }
            }
        }

        DB::transaction(function () use ($data, $bands) {
            $this->query($data)->get()->each->delete(); // rates cascade
            foreach ($bands as $i => $band) {
                $model = WeightMarkupBand::create([
                    'carrier' => $data['carrier'],
                    'package_type' => $data['package_type'],
                    'agent_account_id' => $data['agent_account_id'] ?? null,
                    'min_weight' => $band['min_weight'],
                    'max_weight' => $band['max_weight'],
                    'sort_order' => $i,
                ]);
                foreach ($band['rates'] as $key => $rate) {
                    if ($rate === null || $rate === '') {
                        continue;
                    }
                    [$type, $value] = array_pad(explode(':', $key, 2), 2, null);
                    $model->rates()->create(['scope_type' => $type, 'scope_value' => $value, 'rate_per_kg' => $rate]);
                }
            }
        });

        return $this->show($request);
    }

    /** Drops an account's own set — that account falls back to the carrier-wide bands. */
    public function destroy(Request $request)
    {
        $data = $this->scope($request);
        abort_if(empty($data['agent_account_id']), 422, 'ลบได้เฉพาะชุดของบัญชี (ชุดกลางของ Carrier ลบไม่ได้)');
        DB::transaction(fn () => $this->query($data)->get()->each->delete());

        return response()->json(['message' => 'ลบแล้ว — บัญชีนี้กลับไปใช้ชุดกลางของ Carrier']);
    }

    private function scope(Request $request): array
    {
        return $request->validate([
            'carrier' => ['required', 'in:UPS,DHL'],
            'package_type' => ['required', 'in:document,box'],
            'agent_account_id' => ['nullable', 'integer', 'exists:agent_accounts,id'],
        ]);
    }

    private function query(array $data)
    {
        return WeightMarkupBand::where('carrier', $data['carrier'])
            ->where('package_type', $data['package_type'])
            ->when(
                ! empty($data['agent_account_id']),
                fn ($q) => $q->where('agent_account_id', $data['agent_account_id']),
                fn ($q) => $q->whereNull('agent_account_id'),
            );
    }

    private static function scopeKey(string $type, ?string $value): string
    {
        return $type === 'all' ? 'all' : "{$type}:{$value}";
    }
}

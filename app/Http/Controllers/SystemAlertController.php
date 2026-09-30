<?php

namespace App\Http\Controllers;

use App\Models\SystemAlert;
use Illuminate\Http\Request;

/** Config › System Alerts for `config.system_alerts` holders (see SystemAlert::record). */
class SystemAlertController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,resolved,all'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $query = SystemAlert::with('resolvedBy:id,name')->latest('last_seen_at')->latest('id');
        match ($data['status'] ?? 'open') {
            'open' => $query->whereNull('resolved_at'),
            'resolved' => $query->whereNotNull('resolved_at'),
            default => null,
        };
        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        return response()->json($query->paginate(30)->toArray() + [
            'sources' => SystemAlert::query()->distinct()->orderBy('source')->pluck('source'),
        ]);
    }

    /** Unresolved count for the Topbar badge. */
    public function summary()
    {
        return response()->json(['open' => SystemAlert::whereNull('resolved_at')->count()]);
    }

    public function resolve(Request $request, SystemAlert $systemAlert)
    {
        if (! $systemAlert->resolved_at) {
            $systemAlert->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);
        }

        return response()->json($systemAlert->fresh('resolvedBy:id,name'));
    }

    public function resolveAll(Request $request)
    {
        $count = SystemAlert::whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return response()->json(['resolved' => $count]);
    }
}

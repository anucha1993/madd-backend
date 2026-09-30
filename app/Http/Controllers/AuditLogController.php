<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/** Read-only view of audit_logs for `user.audit` holders (see AuditLogger). */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::with('user:id,name,username')->latest('created_at')->latest('id');
        foreach (['subject_type', 'subject_id', 'user_id', 'event'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['search'])) {
            $query->where('subject_label', 'like', '%'.$data['search'].'%');
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->json($query->paginate(30)->toArray() + [
            'subject_types' => AuditLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}

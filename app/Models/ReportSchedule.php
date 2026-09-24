<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'report_type', 'frequency', 'send_time', 'day_of_week', 'day_of_month',
    'report_range', 'branch_id', 'carrier', 'agent_account_id', 'recipients', 'is_active',
])]
class ReportSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(AgentAccount::class);
    }
}

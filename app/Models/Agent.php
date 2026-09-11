<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agent_name', 'agent_code', 'logo_url', 'status'])]
class Agent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(AgentAccount::class);
    }

    public function markupRules(): HasMany
    {
        return $this->hasMany(MarkupRule::class);
    }
}

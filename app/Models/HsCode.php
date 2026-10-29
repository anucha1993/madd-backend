<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One HS / HTS code for the Commercial Invoice HS Code lookup (/config/hs-codes). */
#[Fillable(['code', 'code_formatted', 'description', 'chapter', 'source', 'status'])]
class HsCode extends Model
{
    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }
}

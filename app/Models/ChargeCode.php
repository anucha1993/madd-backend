<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'code', 'label', 'description', 'category'])]
class ChargeCode extends Model
{
    use HasFactory;
}

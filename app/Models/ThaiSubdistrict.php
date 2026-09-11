<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tambon_id', 'name_th', 'name_en',
    'district_id', 'district_name_th', 'district_name_en',
    'province_id', 'province_name_th', 'province_name_en',
    'region', 'zip_code', 'zip_code_all',
])]
class ThaiSubdistrict extends Model
{
    //
}

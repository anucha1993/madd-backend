<?php

namespace App\Support;

/**
 * State/province codes sent to carriers are plain ASCII abbreviations (NY, ON, ...). Text pasted
 * from web pages, email or chat often carries invisible characters (zero-width spaces,
 * direction marks, BOM) that look like "NY" on screen but reach UPS as "??NY" and get rejected
 * with "is not a valid state" — so strip everything except A-Z/0-9.
 */
class StateCode
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));

        return $clean === '' ? null : $clean;
    }
}

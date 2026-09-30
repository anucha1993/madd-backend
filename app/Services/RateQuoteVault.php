<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keeps the FULL rate quote (carrier cost, markup detail, raw response) server-side, while the
 * browser only receives the copy AccessService::sanitizeRateQuote() allows plus a `quoteId`.
 * Booking (ShipmentController::store) looks the full quote back up by that id, so cost_amount
 * and the saved rate_quote come from what the carrier actually returned — never from numbers
 * the browser sent back (which a user without cost access doesn't even have).
 */
class RateQuoteVault
{
    /** Long enough to cover a slow booking form / a draft resumed later the same day. */
    private const TTL_HOURS = 12;

    public function remember(array $quote, User $user): string
    {
        $id = (string) Str::uuid();
        Cache::put($this->key($id), ['user_id' => $user->id, 'quote' => $quote], now()->addHours(self::TTL_HOURS));

        return $id;
    }

    /** The full quote, or null if unknown/expired/issued to a different user. */
    public function recall(?string $id, User $user): ?array
    {
        if (! $id) {
            return null;
        }
        $entry = Cache::get($this->key($id));

        return ($entry && $entry['user_id'] === $user->id) ? $entry['quote'] : null;
    }

    private function key(string $id): string
    {
        return "rate_quote:{$id}";
    }
}

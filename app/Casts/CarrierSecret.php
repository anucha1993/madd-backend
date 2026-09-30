<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Carrier API secrets (UPS client_secret, DHL basic-auth password) encrypted at rest with
 * APP_KEY — but rollout-safe for a database shared by several app servers:
 *
 * - Reading always works for BOTH legacy plaintext rows and encrypted ones (a value that
 *   doesn't decrypt is treated as plaintext).
 * - Writing only encrypts when services.carrier_secrets.encrypt (CARRIER_SECRETS_ENCRYPT) is
 *   on. Turn it on — and run `php artisan agent-accounts:encrypt-secrets` — only once EVERY
 *   server using this database has the SAME APP_KEY; a server with a different key would read
 *   ciphertext as the password and every carrier call from it would fail.
 */
class CarrierSecret implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '' || ! config('services.carrier_secrets.encrypt')) {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /** True when the stored (raw) value is already encrypted with the current APP_KEY. */
    public static function isEncrypted(?string $raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }
        try {
            Crypt::decryptString($raw);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}

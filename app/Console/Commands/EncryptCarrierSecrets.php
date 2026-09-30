<?php

namespace App\Console\Commands;

use App\Casts\CarrierSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-off: encrypts agent_accounts secrets still stored as plaintext (see CarrierSecret).
 * Refuses to run unless CARRIER_SECRETS_ENCRYPT is on, so new writes and existing rows end up
 * in the same format. Already-encrypted values are left alone, so re-running is safe.
 */
class EncryptCarrierSecrets extends Command
{
    protected $signature = 'agent-accounts:encrypt-secrets {--dry-run : Only report how many values would be encrypted}';

    protected $description = 'Encrypt plaintext UPS client_secret / DHL basic_auth_password values in agent_accounts';

    private const COLUMNS = ['client_secret', 'basic_auth_password'];

    public function handle(): int
    {
        if (! config('services.carrier_secrets.encrypt')) {
            $this->error('CARRIER_SECRETS_ENCRYPT is off. Set CARRIER_SECRETS_ENCRYPT=true on EVERY server that uses this database (all with the same APP_KEY) first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $count = 0;
        // All-or-nothing: a failure part-way (e.g. a column too short) must never leave some
        // accounts encrypted and others not.
        DB::transaction(function () use ($dryRun, &$count) {
            foreach (DB::table('agent_accounts')->get(['id', ...self::COLUMNS]) as $row) {
                $updates = [];
                foreach (self::COLUMNS as $column) {
                    $raw = $row->{$column};
                    if ($raw !== null && $raw !== '' && ! CarrierSecret::isEncrypted($raw)) {
                        $updates[$column] = Crypt::encryptString($raw);
                    }
                }
                if ($updates) {
                    $count += count($updates);
                    if (! $dryRun) {
                        DB::table('agent_accounts')->where('id', $row->id)->update($updates);
                    }
                }
            }
        });

        $this->info(($dryRun ? 'Would encrypt' : 'Encrypted')." {$count} value(s).");

        return self::SUCCESS;
    }
}

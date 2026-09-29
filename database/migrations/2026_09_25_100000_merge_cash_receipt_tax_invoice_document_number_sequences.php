<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Cash Receipt + Tax Invoice are always issued together as a pair — merge their previously
    // independent vol_no/no counters into a single shared one (kept under document_type =
    // CASH_RECEIPT) so both documents in a pair always print the exact same number going
    // forward. Keeps whichever side's counter is further ahead, so no already-printed number is
    // ever risked being reissued.
    public function up(): void
    {
        $rows = DB::table('document_number_sequences')->get()->groupBy('branch_id');

        foreach ($rows as $branchRows) {
            foreach (['vol_no', 'no'] as $field) {
                $fieldRows = $branchRows->where('field', $field);
                $cash = $fieldRows->firstWhere('document_type', 'CASH_RECEIPT');
                $tax = $fieldRows->firstWhere('document_type', 'TAX_INVOICE');

                if ($cash && $tax) {
                    $keep = $tax->next_number > $cash->next_number ? $tax : $cash;
                    DB::table('document_number_sequences')->where('id', $cash->id)
                        ->update(['pattern' => $keep->pattern, 'next_number' => $keep->next_number]);
                    DB::table('document_number_sequences')->where('id', $tax->id)->delete();
                } elseif ($tax && ! $cash) {
                    DB::table('document_number_sequences')->where('id', $tax->id)
                        ->update(['document_type' => 'CASH_RECEIPT']);
                }
            }
        }
    }

    public function down(): void
    {
        // Splitting back into independent counters isn't a meaningful reverse — left as no-op.
    }
};

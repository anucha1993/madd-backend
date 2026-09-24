<?php
require __DIR__."/vendor/autoload.php";
$app = require __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$receipt = App\Models\Receipt::where("vol_no","026")->where("no","00003")->first();
if (!$receipt) { echo "receipt not found\n"; exit; }
echo "receipt id={$receipt->id} status={$receipt->status}\n";
foreach ($receipt->shipments as $s) {
    echo "shipment id={$s->id} tracking={$s->tracking_number} branch_id={$s->branch_id} agent_account_id={$s->agent_account_id} status={$s->status} created_at={$s->created_at} freight={$s->freight_amount} order_total={$s->order_total}\n";
    echo "  packages=" . json_encode($s->packages) . "\n";
}

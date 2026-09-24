<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ctrl = $app->make(App\Http\Controllers\ManifestReportController::class);
$req = new Illuminate\Http\Request(['range' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-09-24']);
$resp = $ctrl->index($req);
$data = json_decode($resp->getContent(), true);
foreach ($data['groups'] as $g) {
    echo '=== '.$g['header']['carrier'].' '.$g['header']['account_number'].' ('.$g['header']['branch_name'].') ==='.PHP_EOL;
    foreach ($g['rows'] as $row) {
        echo '  tracking='.$row['tracking'].' ref='.$row['ref'].' pkg='.$row['pkg'].' freight='.$row['freight'].' total='.$row['total_charge'].PHP_EOL;
    }
}

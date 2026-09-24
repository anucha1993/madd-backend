<?php

use App\Models\AgentAccount;
use App\Services\UpsShipmentService;

$account = AgentAccount::where('username_acc', '884v4f')->first();

if (! $account) {
    echo "NO TEST ACCOUNT FOUND (884v4f)\n";
    return;
}

$service = app(UpsShipmentService::class);

$token = $service->getAccessToken($account->client_id, $account->client_secret, $account->mode);

$shipment = [
    'description' => 'Test Waybill Fetch',
    'from' => [
        'contactName' => 'Test Shipper',
        'company' => 'MADD Test',
        'phone' => '0812345678',
        'address' => '123 Test Rd',
        'city' => 'Bangkok',
        'stateCode' => null,
        'postcode' => '10110',
        'country' => 'TH',
    ],
    'to' => [
        'contactName' => 'Test Receiver',
        'company' => 'Receiver Co',
        'phone' => '0898765432',
        'address' => '456 Receiver St',
        'city' => 'New York',
        'stateCode' => 'NY',
        'postcode' => '10001',
        'country' => 'US',
    ],
    'serviceCode' => '65',
    'packages' => [
        [
            'isDocument' => false,
            'quantity' => 1,
            'weight' => 2,
            'dimensionUnit' => 'CM',
            'length' => 20,
            'width' => 15,
            'height' => 10,
            'declaredValue' => 50000,
            'useCarrierInsurance' => true,
        ],
    ],
    'declaredValueCurrency' => 'THB',
];

try {
    $result = $service->createShipment($token, ['username_acc' => $account->username_acc], $shipment, $account->mode);
    echo "SUCCESS\n";
    echo "trackingNumber: {$result['trackingNumber']}\n";
    echo 'waybillFormat: '.($result['waybillFormat'] ?? 'NULL')."\n";
    echo 'has waybillBase64: '.(! empty($result['waybillBase64']) ? 'YES len='.strlen($result['waybillBase64']) : 'NO')."\n";
    if (! empty($result['waybillBase64'])) {
        $ext = strtolower($result['waybillFormat'] ?? 'bin');
        file_put_contents(__DIR__.'/_tmp_waybill.'.$ext, base64_decode($result['waybillBase64']));
        echo 'Saved waybill to _tmp_waybill.'.$ext."\n";
    }
    file_put_contents(__DIR__.'/_tmp_waybill_raw.json', json_encode($result['raw'], JSON_PRETTY_PRINT));
} catch (\Throwable $e) {
    echo 'FAILED: '.$e->getMessage()."\n";
}

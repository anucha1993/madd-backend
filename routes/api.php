<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AddonCategoryController;
use App\Http\Controllers\AddonItemController;
use App\Http\Controllers\AgentAccountController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\BillingCustomerController;
use App\Http\Controllers\BranchCarrierAccountController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ChargeCodeController;
use App\Http\Controllers\ChargeFormulaController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DhlTrackingController;
use App\Http\Controllers\R2Controller;
use App\Http\Controllers\InsuranceCountryCapController;
use App\Http\Controllers\ManifestOptionController;
use App\Http\Controllers\ManifestReportController;
use App\Http\Controllers\ChargeFixedOverrideController;
use App\Http\Controllers\MarkupRuleController;
use App\Http\Controllers\PickupController;
use App\Http\Controllers\ProductWeightBandController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentDraftController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\ThaiSubdistrictController;
use App\Http\Controllers\TrackingSyncController;
use App\Http\Controllers\UpsTrackingController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('agents', AgentController::class);
    Route::apiResource('agent-accounts', AgentAccountController::class);
    Route::post('agent-accounts/{agentAccount}/test', [AgentAccountController::class, 'test']);
    Route::get('agent-accounts/{agentAccount}/dhl-products', [AgentAccountController::class, 'dhlProducts']);

    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/carrier-accounts', [BranchCarrierAccountController::class, 'index']);
    Route::put('branches/{branch}/carrier-accounts', [BranchCarrierAccountController::class, 'sync']);
    Route::get('branches/{branch}/document-number-settings', [BranchController::class, 'documentNumberSettings']);
    Route::put('branches/{branch}/document-number-settings', [BranchController::class, 'updateDocumentNumberSettings']);
    Route::apiResource('users', UserController::class);

    Route::apiResource('customers', CustomerController::class);
    Route::get('customer-addresses', [CustomerAddressController::class, 'search']);
    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index']);
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store']);
    Route::put('customer-addresses/{address}', [CustomerAddressController::class, 'update']);
    Route::delete('customer-addresses/{address}', [CustomerAddressController::class, 'destroy']);

    Route::get('thai-subdistricts/by-zipcode/{zipCode}', [ThaiSubdistrictController::class, 'byZipCode']);
    Route::get('thai-subdistricts/regions', [ThaiSubdistrictController::class, 'regions']);
    Route::apiResource('thai-subdistricts', ThaiSubdistrictController::class);

    Route::post('shipping/check-rate', [ShippingController::class, 'checkRate']);

    Route::post('shipments', [ShipmentController::class, 'store']);
    Route::get('shipments', [ShipmentController::class, 'index']);
    Route::get('shipments/stats', [ShipmentController::class, 'stats']);
    Route::get('shipments/{shipment}/label', [ShipmentController::class, 'label']);
    Route::get('shipments/{shipment}/labels/all', [ShipmentController::class, 'allLabels']);
    Route::get('shipments/{shipment}/waybill', [ShipmentController::class, 'waybill']);
    Route::get('shipments/{shipment}/commercial-invoice', [ShipmentController::class, 'commercialInvoice']);
    Route::post('shipments/{shipment}/void', [ShipmentController::class, 'void']);
    Route::delete('shipments/{shipment}', [ShipmentController::class, 'destroy']);
    Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);

    Route::apiResource('shipment-drafts', ShipmentDraftController::class);

    Route::apiResource('billing-customers', BillingCustomerController::class);

    Route::post('receipts/preview-lines', [ReceiptController::class, 'previewLines']);
    Route::post('receipts/{receipt}/void', [ReceiptController::class, 'void']);
    Route::get('receipts/{receipt}/pdf', [ReceiptController::class, 'pdf']);
    Route::apiResource('receipts', ReceiptController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::post('pickups', [PickupController::class, 'store']);
    Route::get('pickups', [PickupController::class, 'index']);
    Route::post('pickups/{pickup}/cancel', [PickupController::class, 'cancel']);

    Route::get('tracking-sync/settings', [TrackingSyncController::class, 'showSettings']);
    Route::put('tracking-sync/settings', [TrackingSyncController::class, 'updateSettings']);
    Route::get('tracking-sync/logs', [TrackingSyncController::class, 'logs']);
    Route::post('tracking-sync/run-now', [TrackingSyncController::class, 'runNow']);

    Route::get('r2/settings', [R2Controller::class, 'settings']);
    Route::put('r2/settings', [R2Controller::class, 'updateSettings']);

    Route::post('ups-tracking/track', [UpsTrackingController::class, 'track']);
    Route::post('dhl-tracking/track', [DhlTrackingController::class, 'track']);

    Route::get('ai/settings', [AiController::class, 'settings']);
    Route::put('ai/settings', [AiController::class, 'updateSettings']);
    Route::put('ai/toggle', [AiController::class, 'toggleEnabled']);
    Route::post('ai/parse-address', [AiController::class, 'parseAddress']);
    Route::post('ai/rate-chat', [AiController::class, 'rateChat']);

    Route::get('product-weight-bands', [ProductWeightBandController::class, 'index']);
    Route::apiResource('product-weight-bands', ProductWeightBandController::class)->only(['store', 'update', 'destroy']);

    Route::post('countries/sync', [CountryController::class, 'sync']);
    Route::get('countries/settings', [CountryController::class, 'settings']);
    Route::put('countries/settings', [CountryController::class, 'updateSettings']);
    Route::get('countries', [CountryController::class, 'index']);
    Route::put('countries/{country}', [CountryController::class, 'update']);

    Route::apiResource('supplies', SupplyController::class);

    Route::post('insurance-country-caps/import', [InsuranceCountryCapController::class, 'import']);
    Route::get('insurance-country-caps/lookup', [InsuranceCountryCapController::class, 'lookup']);
    Route::apiResource('insurance-country-caps', InsuranceCountryCapController::class);

    Route::apiResource('charge-codes', ChargeCodeController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::post('charge-formula/preview', [ChargeFormulaController::class, 'preview']);

    Route::apiResource('markup-rules', MarkupRuleController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('charge-fixed-overrides', ChargeFixedOverrideController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('addon-categories', AddonCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('addon-items', AddonItemController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('manifest-options', ManifestOptionController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('manifest-report', [ManifestReportController::class, 'index']);
    Route::get('manifest-report/export', [ManifestReportController::class, 'export']);
});

<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AddonCategoryController;
use App\Http\Controllers\AddonItemController;
use App\Http\Controllers\AgentAccountController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\WordPressPluginController;
use App\Http\Controllers\PublicApi\RateController as PublicRateController;
use App\Http\Controllers\PublicApi\TrackingController as PublicTrackingController;
use App\Http\Controllers\PublicApi\PageViewController;
use App\Http\Controllers\PublicApi\PublicStatsController;
use App\Http\Controllers\PublicApi\AiAssistController as PublicAiAssistController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\SystemAlertController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\BillingCustomerController;
use App\Http\Controllers\BranchCarrierAccountController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ChargeCodeController;
use App\Http\Controllers\ChargeFormulaController;
use App\Http\Controllers\ColumnProfileController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\AddressValidationController;
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
use App\Http\Controllers\ReceiptLineTemplateController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentDraftController;
use App\Http\Controllers\SmtpSettingController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\SupplyStockController;
use App\Http\Controllers\ThaiSubdistrictController;
use App\Http\Controllers\TrackingSyncController;
use App\Http\Controllers\UpsTrackingController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// ---- Public Rate API for external sites (API key, not a user session) ----
Route::prefix('public/v1')->middleware('api.client')->group(function () {
    Route::post('rates', [PublicRateController::class, 'rates']);
    Route::get('countries', [PublicRateController::class, 'countries']);
    Route::get('tracking/{trackingNumber}', [PublicTrackingController::class, 'show']);
});
// Same tracking answer, called from the visitor's browser on a registered website (Origin, no key).
Route::get('public/v1/web/tracking/{trackingNumber}', [PublicTrackingController::class, 'show'])->middleware('api.web:tracking');
// Sell-price quotes from the browser. Sent as a form post (no custom headers) so browsers don't
// need a CORS preflight.
Route::post('public/v1/web/rates', [PublicRateController::class, 'rates'])->middleware('api.web:rates');
Route::get('public/v1/web/countries', [PublicRateController::class, 'countries'])->middleware('api.web:rates');
Route::post('public/v1/web/hit', [PageViewController::class, 'store'])->middleware('api.web:view');
Route::get('public/v1/web/stats', [PublicStatsController::class, 'show'])->middleware('api.web:view');
// AI: a visitor's sentence → rate-quote form values (no prices). Own per-IP / per-day caps.
Route::post('public/v1/web/ai-parse', [PublicAiAssistController::class, 'parse'])->middleware('api.web:rates');

// Every authenticated route also passes through `record.scope` (a Shipment/Receipt/Pickup bound
// from the URL outside the user's data scope 404s). `perm:` keys come from
// config/permissions.php. GET endpoints for reference data (countries, add-ons, branches...)
// stay open to every signed-in user because the booking/receipt forms need them; changing that
// data requires the matching config.* permission.
Route::middleware(['auth:sanctum', 'record.scope'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // ---- Roles & permissions ----
    Route::get('access/registry', [RoleController::class, 'registry'])->middleware('perm:user.roles,user.view');
    Route::get('roles', [RoleController::class, 'index'])->middleware('perm:user.roles,user.view');
    Route::middleware('perm:user.roles')->group(function () {
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });
    Route::apiResource('users', UserController::class)->only(['index', 'show'])->middleware('perm:user.view');
    Route::post('users', [UserController::class, 'store'])->middleware('perm:user.create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:user.edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('perm:user.delete');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('perm:user.audit');
    Route::get('shipments/{shipment}/timeline', [TimelineController::class, 'shipment'])->middleware('perm:shipment.timeline');
    Route::get('receipts/{receipt}/timeline', [TimelineController::class, 'receipt'])->middleware('perm:receipt.timeline');
    Route::get('pickups/{pickup}/timeline', [TimelineController::class, 'pickup'])->middleware('perm:pickup.timeline');
    Route::middleware('perm:config.api_clients')->group(function () {
        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::post('api-clients', [ApiClientController::class, 'store']);
        Route::get('api-clients/logs', [ApiClientController::class, 'logs']);
        Route::get('api-clients/stats', [ApiClientController::class, 'stats']);
        Route::put('api-clients/{apiClient}', [ApiClientController::class, 'update']);
        Route::delete('api-clients/{apiClient}', [ApiClientController::class, 'destroy']);
        Route::post('api-clients/{apiClient}/regenerate', [ApiClientController::class, 'regenerate']);
        Route::post('api-clients/{apiClient}/test', [ApiClientController::class, 'test']);
        Route::get('wordpress-plugins', [WordPressPluginController::class, 'index']);
        Route::get('wordpress-plugins/{slug}/download', [WordPressPluginController::class, 'download']);
    });
    Route::middleware('perm:config.system_alerts')->group(function () {
        Route::get('system-alerts', [SystemAlertController::class, 'index']);
        Route::get('system-alerts/summary', [SystemAlertController::class, 'summary']);
        Route::post('system-alerts/resolve-all', [SystemAlertController::class, 'resolveAll']);
        Route::post('system-alerts/{systemAlert}/resolve', [SystemAlertController::class, 'resolve']);
    });

    // ---- Column Profiles (index returns only the caller's available profiles) ----
    Route::get('column-profiles', [ColumnProfileController::class, 'index']);
    Route::apiResource('column-profiles', ColumnProfileController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.column_profiles');

    // ---- Agent accounts (secrets are #[Hidden] on the model) ----
    Route::get('agents', [AgentController::class, 'index']);
    Route::get('agents/{agent}', [AgentController::class, 'show']);
    Route::get('agent-accounts', [AgentAccountController::class, 'index']);
    Route::get('agent-accounts/{agent_account}', [AgentAccountController::class, 'show']);
    Route::middleware('perm:config.agent_accounts')->group(function () {
        Route::apiResource('agents', AgentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('agent-accounts', AgentAccountController::class)->only(['store', 'update', 'destroy']);
        Route::post('agent-accounts/{agentAccount}/test', [AgentAccountController::class, 'test']);
        Route::get('agent-accounts/{agentAccount}/dhl-products', [AgentAccountController::class, 'dhlProducts']);
    });

    // ---- Branches ----
    Route::get('branches', [BranchController::class, 'index']);
    Route::get('branches/{branch}', [BranchController::class, 'show']);
    Route::post('branches', [BranchController::class, 'store'])->middleware('perm:branch.create');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('perm:branch.edit');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('perm:branch.delete');
    // The branch form (create/edit) also loads + saves which carrier accounts the branch uses.
    Route::get('branches/{branch}/carrier-accounts', [BranchCarrierAccountController::class, 'index'])->middleware('perm:branch.view,branch.create,branch.edit');
    Route::put('branches/{branch}/carrier-accounts', [BranchCarrierAccountController::class, 'sync'])->middleware('perm:branch.create,branch.edit');
    Route::middleware('perm:branch.doc_numbers')->group(function () {
        Route::get('branches/{branch}/document-number-settings', [BranchController::class, 'documentNumberSettings']);
        Route::put('branches/{branch}/document-number-settings', [BranchController::class, 'updateDocumentNumberSettings']);
    });

    // ---- Customers / address book (the booking form saves addresses too) ----
    Route::get('customers', [CustomerController::class, 'index']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::get('customer-addresses', [CustomerAddressController::class, 'search']);
    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index']);
    // Booking (shipment.create) saves new senders/receivers to the address book from the form.
    Route::post('customers', [CustomerController::class, 'store'])->middleware('perm:customer.create,shipment.create');
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->middleware('perm:customer.create,customer.edit,shipment.create');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('perm:customer.edit');
    Route::put('customer-addresses/{address}', [CustomerAddressController::class, 'update'])->middleware('perm:customer.edit');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('perm:customer.delete');
    Route::delete('customer-addresses/{address}', [CustomerAddressController::class, 'destroy'])->middleware('perm:customer.delete');

    // ---- Thai address DB ----
    Route::get('thai-subdistricts/by-zipcode/{zipCode}', [ThaiSubdistrictController::class, 'byZipCode']);
    Route::get('thai-subdistricts/regions', [ThaiSubdistrictController::class, 'regions']);
    Route::get('thai-subdistricts', [ThaiSubdistrictController::class, 'index']);
    Route::get('thai-subdistricts/{thai_subdistrict}', [ThaiSubdistrictController::class, 'show']);
    Route::apiResource('thai-subdistricts', ThaiSubdistrictController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.thai_address');

    // ---- Shipments ----
    Route::middleware('perm:shipment.create')->group(function () {
        Route::post('shipping/check-rate', [ShippingController::class, 'checkRate']);
        Route::post('address-validation/validate', [AddressValidationController::class, 'validate']);
        Route::post('shipments', [ShipmentController::class, 'store']);
        Route::post('shipments/upload-commercial-invoice', [ShipmentController::class, 'uploadCommercialInvoiceFile']);
        Route::apiResource('shipment-drafts', ShipmentDraftController::class);
    });
    Route::middleware('perm:shipment.view')->group(function () {
        Route::get('shipments', [ShipmentController::class, 'index']);
        Route::get('shipments/stats', [ShipmentController::class, 'stats']);
        Route::get('shipments/{shipment}/label', [ShipmentController::class, 'label']);
        Route::get('shipments/{shipment}/labels/all', [ShipmentController::class, 'allLabels']);
        Route::get('shipments/{shipment}/waybill', [ShipmentController::class, 'waybill']);
        Route::get('shipments/{shipment}/commercial-invoice', [ShipmentController::class, 'commercialInvoice']);
        Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
    });
    Route::middleware('perm:shipment.void')->group(function () {
        Route::post('shipments/{shipment}/void', [ShipmentController::class, 'void']);
        Route::post('shipments/{shipment}/unvoid', [ShipmentController::class, 'unvoid']);
        Route::post('shipments/{shipment}/confirm-carrier-cancel', [ShipmentController::class, 'confirmCarrierCancel']);
        Route::post('shipments/{shipment}/carrier-cancel-notified', [ShipmentController::class, 'markCarrierCancelNotified']);
    });
    Route::delete('shipments/{shipment}', [ShipmentController::class, 'destroy'])->middleware('perm:shipment.delete');

    // ---- Billing ----
    Route::get('billing-customers', [BillingCustomerController::class, 'index']);
    Route::get('billing-customers/{billing_customer}', [BillingCustomerController::class, 'show']);
    Route::post('billing-customers', [BillingCustomerController::class, 'store'])->middleware('perm:billing_customer.create');
    Route::put('billing-customers/{billing_customer}', [BillingCustomerController::class, 'update'])->middleware('perm:billing_customer.edit');
    Route::delete('billing-customers/{billing_customer}', [BillingCustomerController::class, 'destroy'])->middleware('perm:billing_customer.delete');

    Route::middleware('perm:receipt.view')->group(function () {
        Route::post('receipts/print-batch', [ReceiptController::class, 'printBatch']);
        Route::get('receipts/{receipt}/pdf', [ReceiptController::class, 'pdf']);
        Route::get('receipts', [ReceiptController::class, 'index']);
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show']);
    });
    Route::post('receipts/preview-lines', [ReceiptController::class, 'previewLines'])->middleware('perm:receipt.create,receipt.edit');
    Route::post('receipts', [ReceiptController::class, 'store'])->middleware('perm:receipt.create');
    Route::put('receipts/{receipt}', [ReceiptController::class, 'update'])->middleware('perm:receipt.edit');
    Route::post('receipts/{receipt}/void', [ReceiptController::class, 'void'])->middleware('perm:receipt.void');
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy'])->middleware('perm:receipt.delete');
    Route::get('receipt-line-templates', [ReceiptLineTemplateController::class, 'index']);
    Route::apiResource('receipt-line-templates', ReceiptLineTemplateController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.receipt_templates');

    // ---- Pickups ----
    Route::get('pickups', [PickupController::class, 'index'])->middleware('perm:pickup.view');
    Route::post('pickups', [PickupController::class, 'store'])->middleware('perm:pickup.create');
    Route::post('pickups/{pickup}/cancel', [PickupController::class, 'cancel'])->middleware('perm:pickup.cancel');
    Route::middleware('perm:pickup.confirm')->group(function () {
        Route::post('pickups/{pickup}/confirm-collected', [PickupController::class, 'confirmCollected']);
        Route::post('shipments/{shipment}/mark-picked-up', [ShipmentController::class, 'markPickedUp']);
    });

    // ---- Tracking ----
    Route::middleware('perm:tracking.view,shipment.view')->group(function () {
        Route::post('ups-tracking/track', [UpsTrackingController::class, 'track']);
        Route::post('dhl-tracking/track', [DhlTrackingController::class, 'track']);
    });
    Route::middleware('perm:config.tracking_sync')->group(function () {
        Route::get('tracking-sync/settings', [TrackingSyncController::class, 'showSettings']);
        Route::put('tracking-sync/settings', [TrackingSyncController::class, 'updateSettings']);
        Route::get('tracking-sync/logs', [TrackingSyncController::class, 'logs']);
        Route::post('tracking-sync/run-now', [TrackingSyncController::class, 'runNow']);
    });

    // ---- Integrations ----
    // ai/settings GET stays open: every page reads `is_enabled` to show/hide AI features, and the
    // key itself is only ever returned masked.
    Route::get('ai/settings', [AiController::class, 'settings']);
    Route::post('ai/parse-address', [AiController::class, 'parseAddress'])->middleware('perm:ai.parse_address');
    Route::post('ai/rate-chat', [AiController::class, 'rateChat'])->middleware('perm:ai.rate_chat');
    Route::middleware('perm:config.integrations')->group(function () {
        Route::put('ai/settings', [AiController::class, 'updateSettings']);
        Route::put('ai/toggle', [AiController::class, 'toggleEnabled']);
        Route::get('r2/settings', [R2Controller::class, 'settings']);
        Route::put('r2/settings', [R2Controller::class, 'updateSettings']);
    });

    // ---- Reference data used by the booking / receipt forms ----
    Route::get('product-weight-bands', [ProductWeightBandController::class, 'index']);
    Route::apiResource('product-weight-bands', ProductWeightBandController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.weight_bands');

    Route::get('countries', [CountryController::class, 'index']);
    Route::middleware('perm:config.countries')->group(function () {
        Route::post('countries/sync', [CountryController::class, 'sync']);
        Route::get('countries/settings', [CountryController::class, 'settings']);
        Route::put('countries/settings', [CountryController::class, 'updateSettings']);
        Route::put('countries/{country}', [CountryController::class, 'update']);
    });

    Route::get('supplies', [SupplyController::class, 'index']);
    Route::get('supply-stock', [SupplyStockController::class, 'index'])->middleware('perm:supply_stock.view');
    Route::get('supply-stock/movements', [SupplyStockController::class, 'movements'])->middleware('perm:supply_stock.view');
    Route::post('supply-stock/receive', [SupplyStockController::class, 'receive'])->middleware('perm:supply_stock.receive');
    Route::post('supply-stock/adjust', [SupplyStockController::class, 'adjust'])->middleware('perm:supply_stock.receive');
    Route::put('supply-stock/limits', [SupplyStockController::class, 'limits'])->middleware('perm:supply_stock.settings');
    Route::get('supply-stock/report', [SupplyStockController::class, 'report'])->middleware('perm:supply_stock.report');
    Route::get('supply-stock/report/export', [SupplyStockController::class, 'reportExport'])->middleware('perm:supply_stock.report');
    Route::get('supplies/{supply}', [SupplyController::class, 'show']);
    Route::apiResource('supplies', SupplyController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.supplies');

    Route::get('insurance-country-caps/lookup', [InsuranceCountryCapController::class, 'lookup']);
    Route::get('insurance-country-caps', [InsuranceCountryCapController::class, 'index']);
    Route::get('insurance-country-caps/{insurance_country_cap}', [InsuranceCountryCapController::class, 'show']);
    Route::middleware('perm:config.insurance')->group(function () {
        Route::post('insurance-country-caps/import', [InsuranceCountryCapController::class, 'import']);
        Route::apiResource('insurance-country-caps', InsuranceCountryCapController::class)->only(['store', 'update', 'destroy']);
    });

    Route::get('charge-codes', [ChargeCodeController::class, 'index']);
    Route::get('markup-rules', [MarkupRuleController::class, 'index']);
    Route::get('charge-fixed-overrides', [ChargeFixedOverrideController::class, 'index']);
    Route::middleware('perm:config.markup')->group(function () {
        Route::apiResource('charge-codes', ChargeCodeController::class)->only(['store', 'update', 'destroy']);
        Route::post('charge-formula/preview', [ChargeFormulaController::class, 'preview']);
        Route::apiResource('markup-rules', MarkupRuleController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('charge-fixed-overrides', ChargeFixedOverrideController::class)->only(['store', 'update', 'destroy']);
    });

    Route::get('addon-categories', [AddonCategoryController::class, 'index']);
    Route::get('addon-items', [AddonItemController::class, 'index']);
    Route::middleware('perm:config.addon')->group(function () {
        Route::apiResource('addon-categories', AddonCategoryController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('addon-items', AddonItemController::class)->only(['store', 'update', 'destroy']);
    });

    Route::get('manifest-options', [ManifestOptionController::class, 'index']);
    Route::apiResource('manifest-options', ManifestOptionController::class)->only(['store', 'update', 'destroy'])->middleware('perm:config.manifest_options');

    // ---- Reports ----
    Route::middleware('perm:report.manifest')->group(function () {
        Route::get('manifest-report', [ManifestReportController::class, 'index']);
        Route::get('manifest-report/export', [ManifestReportController::class, 'export']);
    });
    Route::middleware('perm:config.report_schedules')->group(function () {
        Route::apiResource('report-schedules', ReportScheduleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('report-schedules/{reportSchedule}/send-now', [ReportScheduleController::class, 'sendNow']);
    });

    Route::middleware('perm:config.smtp')->group(function () {
        Route::get('smtp-settings', [SmtpSettingController::class, 'show']);
        Route::put('smtp-settings', [SmtpSettingController::class, 'update']);
        Route::post('smtp-settings/test', [SmtpSettingController::class, 'test']);
    });
});

<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AddonCategoryController;
use App\Http\Controllers\AddonItemController;
use App\Http\Controllers\AgentAccountController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ChargeCodeController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DhlTrackingController;
use App\Http\Controllers\InsuranceCountryCapController;
use App\Http\Controllers\MarkupRuleController;
use App\Http\Controllers\ProductWeightBandController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\ThaiSubdistrictController;
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

    Route::apiResource('branches', BranchController::class);
    Route::apiResource('users', UserController::class);

    Route::get('thai-subdistricts/by-zipcode/{zipCode}', [ThaiSubdistrictController::class, 'byZipCode']);
    Route::get('thai-subdistricts/regions', [ThaiSubdistrictController::class, 'regions']);
    Route::apiResource('thai-subdistricts', ThaiSubdistrictController::class);

    Route::post('shipping/check-rate', [ShippingController::class, 'checkRate']);

    Route::post('ups-tracking/track', [UpsTrackingController::class, 'track']);
    Route::post('dhl-tracking/track', [DhlTrackingController::class, 'track']);

    Route::get('product-weight-bands', [ProductWeightBandController::class, 'index']);
    Route::apiResource('product-weight-bands', ProductWeightBandController::class)->only(['store', 'update', 'destroy']);

    Route::post('countries/sync', [CountryController::class, 'sync']);
    Route::get('countries/settings', [CountryController::class, 'settings']);
    Route::put('countries/settings', [CountryController::class, 'updateSettings']);
    Route::get('countries', [CountryController::class, 'index']);
    Route::put('countries/{country}', [CountryController::class, 'update']);

    Route::apiResource('supplies', SupplyController::class);

    Route::post('insurance-country-caps/import', [InsuranceCountryCapController::class, 'import']);
    Route::apiResource('insurance-country-caps', InsuranceCountryCapController::class);

    Route::apiResource('charge-codes', ChargeCodeController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('markup-rules', MarkupRuleController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('addon-categories', AddonCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('addon-items', AddonItemController::class)->only(['index', 'store', 'update', 'destroy']);
});

<?php

namespace App\Services;

use App\Exceptions\UpsTokenExpiredException;
use App\Models\Pickup;

/**
 * Cancels a courier pickup with the carrier (both UPS and DHL support it via API, unlike
 * cancelling a DHL shipment) and marks it cancelled locally. Shared by the Pickups page and by
 * voiding a shipment whose pickup would otherwise still bring a courier for nothing.
 */
class PickupCanceller
{
    public function __construct(
        private UpsPickupService $upsPickupService,
        private DhlPickupService $dhlPickupService,
        private UpsShipmentService $upsShipmentService,
    ) {
    }

    /** @throws \Throwable when the carrier rejects the cancellation (pickup left unchanged) */
    public function cancel(Pickup $pickup, string $requestorName, string $reason): Pickup
    {
        $pickup->loadMissing('agentAccount.agent');
        $account = $pickup->agentAccount;

        if ($pickup->carrier === 'UPS') {
            $this->withUpsToken($account, fn ($token) => $this->upsPickupService->cancelPickup($token, $pickup->carrier_reference, $account->mode));
        } else {
            $this->dhlPickupService->cancelPickup([
                'basic_auth_username' => $account->basic_auth_username,
                'basic_auth_password' => $account->basic_auth_password,
                'mode' => $account->mode,
                'username_acc' => $account->username_acc,
            ], $pickup->carrier_reference, $requestorName, $reason);
        }

        $pickup->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $pickup;
    }

    /**
     * Fetches a (possibly cached) UPS token and calls $fn($token) — if UPS responds 401 because
     * a cached token died before its TTL, forces a fresh token and retries $fn ONCE more.
     */
    private function withUpsToken($account, callable $fn)
    {
        $token = $this->upsShipmentService->getAccessToken($account->client_id, $account->client_secret, $account->mode);
        try {
            return $fn($token);
        } catch (UpsTokenExpiredException $e) {
            $token = $this->upsShipmentService->getAccessToken($account->client_id, $account->client_secret, $account->mode, true);

            return $fn($token);
        }
    }
}

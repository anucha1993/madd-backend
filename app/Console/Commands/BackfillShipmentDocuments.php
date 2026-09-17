<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\DhlShipmentService;
use App\Services\R2Service;
use App\Services\UpsShipmentService;
use Illuminate\Console\Command;

/**
 * Shipments booked before waybill/commercial-invoice capture was added (see
 * UpsShipmentService::parseShipmentResponse / DhlShipmentService::parseShipmentResponse) already
 * have their full carrier response saved in `raw_response` — this re-parses that saved JSON and
 * uploads whatever documents are found, WITHOUT re-booking anything with the carrier.
 */
class BackfillShipmentDocuments extends Command
{
    protected $signature = 'shipments:backfill-documents {--dry-run : List what would be uploaded without actually uploading}';

    protected $description = 'Backfill waybill/commercial-invoice files for shipments booked before that capture existed, from their already-saved raw_response';

    private const MIME_TYPES = [
        'PDF' => 'application/pdf',
        'GIF' => 'image/gif',
        'ZPL' => 'application/octet-stream',
    ];

    public function handle(UpsShipmentService $upsShipmentService, DhlShipmentService $dhlShipmentService, R2Service $r2Service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $shipments = Shipment::where('status', 'booked')
            ->whereNotNull('raw_response')
            ->where(function ($q) {
                $q->whereNull('waybill_storage_key')->orWhereNull('commercial_invoice_storage_key');
            })
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('Nothing to backfill — every booked shipment already has these documents (or never had a raw_response saved).');

            return self::SUCCESS;
        }

        $uploaded = 0;
        foreach ($shipments as $shipment) {
            $parsed = $shipment->carrier === 'DHL'
                ? $dhlShipmentService->parseShipmentResponse($shipment->raw_response)
                : $upsShipmentService->parseShipmentResponse($shipment->raw_response);

            $needsWaybill = ! $shipment->waybill_storage_key && ! empty($parsed['waybillBase64']);
            $needsInvoice = ! $shipment->commercial_invoice_storage_key && ! empty($parsed['commercialInvoiceBase64']);
            if (! $needsWaybill && ! $needsInvoice) {
                continue;
            }

            $this->line("Shipment #{$shipment->id} ({$shipment->carrier}, {$shipment->tracking_number}): ".
                implode(', ', array_filter([$needsWaybill ? 'waybill' : null, $needsInvoice ? 'commercial invoice' : null])));

            if ($dryRun) {
                continue;
            }

            $updates = [];
            if ($needsWaybill) {
                $updates['waybill_storage_key'] = $this->upload($r2Service, 'waybill', $shipment->tracking_number, $parsed['waybillBase64'], $parsed['waybillFormat'] ?? 'GIF');
            }
            if ($needsInvoice) {
                $updates['commercial_invoice_storage_key'] = $this->upload($r2Service, 'invoice', $shipment->tracking_number, $parsed['commercialInvoiceBase64'], $parsed['commercialInvoiceFormat'] ?? 'PDF');
            }
            $shipment->update($updates);
            $uploaded++;
        }

        $this->info($dryRun ? 'Dry run complete.' : "Backfilled documents for {$uploaded} shipment(s).");

        return self::SUCCESS;
    }

    private function upload(R2Service $r2Service, string $prefix, ?string $trackingNumber, string $base64, string $format): ?string
    {
        try {
            $mime = self::MIME_TYPES[$format] ?? 'application/octet-stream';
            $extension = strtolower($format);
            $upload = $r2Service->upload("{$prefix}-{$trackingNumber}.{$extension}", base64_decode($base64), $mime);

            return $upload['key'];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

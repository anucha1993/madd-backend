<?php

namespace App\Jobs;

use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCarrierInvoiceOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(private int $carrierInvoiceId)
    {
    }

    public function handle(CarrierInvoiceOcrService $ocrService): void
    {
        $invoice = CarrierInvoice::find($this->carrierInvoiceId);
        if (! $invoice) {
            return;
        }

        $ocrService->process($invoice);
    }
}

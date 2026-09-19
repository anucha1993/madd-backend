<?php

namespace App\Console\Commands;

use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Shipment;
use App\Services\DocumentNumberService;
use App\Services\NumberToWordsService;
use App\Services\ReceiptPdfService;
use Illuminate\Console\Command;

class TestReceiptLayoutSmoke2 extends Command
{
    protected $signature = 'test:receipt-layout-smoke2 {lines=6}';
    protected $description = 'Temporary: render a standalone CASH_RECEIPT with N itemized lines to check half-A4 fit';

    public function handle(DocumentNumberService $docNo, NumberToWordsService $words, ReceiptPdfService $pdf)
    {
        $lineCount = (int) $this->argument('lines');
        $shipment = Shipment::first();
        $grandTotal = (float) $shipment->order_total;

        $receipt = Receipt::create([
            'type' => 'CASH_RECEIPT',
            'branch_id' => $shipment->branch_id,
            'vol_no' => $docNo->next($shipment->branch_id, 'CASH_RECEIPT', 'vol_no'),
            'no' => $docNo->next($shipment->branch_id, 'CASH_RECEIPT', 'no'),
            'issued_date' => now(),
            'buyer_name' => 'TEST RECEIVER',
            'buyer_tax_id' => '0105547042471',
            'buyer_address' => 'TEST ADDRESS',
            'grand_total' => $grandTotal,
            'grand_total_words' => $words->bahtText($grandTotal),
            'payment_method' => 'KBANK',
            'payment_reference' => '1234567890',
            'status' => 'ISSUED',
        ]);

        $per = round($grandTotal / $lineCount, 2);
        $running = 0.0;
        for ($i = 1; $i <= $lineCount; $i++) {
            $amount = $i === $lineCount ? round($grandTotal - $running, 2) : $per;
            $running += $amount;
            ReceiptLine::create([
                'receipt_id' => $receipt->id,
                'description' => 'CHARGE ITEM '.$i,
                'is_non_vat' => false,
                'amount' => $amount,
            ]);
        }
        $receipt->shipments()->attach($shipment->id);

        $binary = $pdf->render($receipt->fresh());
        file_put_contents(storage_path('app/_tmp_layout_test2.pdf'), $binary);
        $this->info('PDF written with '.$lineCount.' lines to '.storage_path('app/_tmp_layout_test2.pdf'));
        $this->info('receipt_id='.$receipt->id);
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCarrierInvoiceOcr;
use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use App\Services\CarrierInvoiceMatchingService;
use App\Services\R2Service;
use Illuminate\Http\Request;

class CarrierInvoiceController extends Controller
{
    public function __construct(
        private R2Service $r2Service,
        private CarrierInvoiceMatchingService $matchingService,
    ) {
    }

    public function index(Request $request)
    {
        $query = CarrierInvoice::query()->withCount('lines')->latest('invoice_date');

        if ($carrier = $request->query('carrier')) {
            $query->where('carrier', $carrier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'carrier' => ['required', 'string', 'in:UPS,DHL'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $upload = $this->r2Service->upload('carrier-invoice-'.$file->getClientOriginalName(), $file->get(), 'application/pdf');

        $invoice = CarrierInvoice::create([
            'carrier' => $data['carrier'],
            'invoice_no' => $data['invoice_no'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'storage_key' => $upload['key'],
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'uploaded',
            'created_by' => $request->user()?->id,
        ]);

        ProcessCarrierInvoiceOcr::dispatch($invoice->id);

        return response()->json($invoice, 201);
    }

    public function show(CarrierInvoice $carrierInvoice)
    {
        $carrierInvoice->load(['lines.shipment:id,tracking_number,carrier,cost_amount,cost_currency,rate_quote,branch_id', 'agentAccount:id,username_acc']);

        return response()->json($carrierInvoice);
    }

    public function reparse(CarrierInvoice $carrierInvoice)
    {
        ProcessCarrierInvoiceOcr::dispatch($carrierInvoice->id);

        return response()->json(['message' => 'เริ่มอ่านไฟล์ใหม่แล้ว กรุณารอสักครู่', 'status' => 'parsing']);
    }

    public function update(Request $request, CarrierInvoice $carrierInvoice)
    {
        $data = $request->validate([
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
        ]);

        $carrierInvoice->update($data);

        return response()->json($carrierInvoice->fresh());
    }

    public function updateLine(Request $request, CarrierInvoiceLine $line)
    {
        $data = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'reference_text' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'override_amount' => ['nullable', 'numeric'],
            'override_note' => ['nullable', 'string'],
        ]);

        $trackingChanged = array_key_exists('tracking_number', $data) && $data['tracking_number'] !== $line->tracking_number;

        $line->update($data);

        if ($trackingChanged) {
            $this->matchingService->match($line, $line->invoice->carrier);
        }

        return response()->json($line->fresh(['shipment:id,tracking_number,carrier,cost_amount,cost_currency,rate_quote,branch_id']));
    }

    public function destroyLine(CarrierInvoiceLine $line)
    {
        $line->delete();

        return response()->json(['message' => 'ลบรายการแล้ว']);
    }

    public function destroy(CarrierInvoice $carrierInvoice)
    {
        $carrierInvoice->delete();

        return response()->json(['message' => 'ลบ Invoice แล้ว']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ReceiptLineTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reusable "Line Items" formula templates for /billing/receipts/new (Product-Configurator-style:
 * a template is an ordered list of lines, each either a plain manual-entry amount or a formula
 * referencing an EARLIER line in the same template, e.g. "{FREIGHT CHARGE} * 12%"). Formulas are
 * evaluated entirely client-side (see madd-frontend/src/lib/formulaEval.ts) — this controller just
 * stores/serves the template definitions, it never evaluates or persists a computed amount itself.
 */
class ReceiptLineTemplateController extends Controller
{
    public function index()
    {
        return ReceiptLineTemplate::with('items')->orderBy('sort_order')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);

        $template = DB::transaction(function () use ($data) {
            $template = ReceiptLineTemplate::create([
                'name' => $data['name'],
                'status' => $data['status'] ?? true,
                'sort_order' => (int) ReceiptLineTemplate::max('sort_order') + 1,
            ]);
            $this->replaceItems($template, $data['items']);

            return $template;
        });

        return response()->json($template->load('items'), 201);
    }

    public function update(Request $request, ReceiptLineTemplate $receiptLineTemplate)
    {
        $data = $this->validateTemplate($request);

        DB::transaction(function () use ($data, $receiptLineTemplate) {
            $receiptLineTemplate->update([
                'name' => $data['name'],
                'status' => $data['status'] ?? $receiptLineTemplate->status,
            ]);
            $this->replaceItems($receiptLineTemplate, $data['items']);
        });

        return $receiptLineTemplate->fresh('items');
    }

    public function destroy(ReceiptLineTemplate $receiptLineTemplate)
    {
        $receiptLineTemplate->delete();

        return response()->json(['message' => 'ลบ Template เรียบร้อย']);
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.formula' => ['nullable', 'string', 'max:500'],
            'items.*.is_non_vat' => ['boolean'],
        ]);
    }

    private function replaceItems(ReceiptLineTemplate $template, array $items): void
    {
        $template->items()->delete();
        foreach ($items as $i => $item) {
            $template->items()->create([
                'description' => $item['description'],
                'formula' => $item['formula'] ?? null,
                'is_non_vat' => $item['is_non_vat'] ?? false,
                'sort_order' => $i,
            ]);
        }
    }
}

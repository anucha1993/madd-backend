<?php

namespace App\Http\Controllers;

use App\Models\ShipmentDraft;
use Illuminate\Http\Request;

class ShipmentDraftController extends Controller
{
    /**
     * Only the staff member who created a draft may view/edit/delete it — drafts are personal
     * scratch state, not shared across the branch like booked Shipments are.
     */
    private function authorizeOwner(Request $request, ShipmentDraft $draft): void
    {
        if ($draft->created_by !== $request->user()?->id) {
            abort(403, 'ไม่มีสิทธิ์เข้าถึงฉบับร่างนี้');
        }
    }

    public function index(Request $request)
    {
        return ShipmentDraft::where('created_by', $request->user()?->id)->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'form_state' => ['required', 'array'],
        ]);

        $draft = ShipmentDraft::create($data + ['created_by' => $request->user()?->id]);

        return response()->json($draft, 201);
    }

    public function show(Request $request, ShipmentDraft $shipmentDraft)
    {
        $this->authorizeOwner($request, $shipmentDraft);

        return $shipmentDraft;
    }

    public function update(Request $request, ShipmentDraft $shipmentDraft)
    {
        $this->authorizeOwner($request, $shipmentDraft);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'form_state' => ['required', 'array'],
        ]);

        $shipmentDraft->update($data);

        return $shipmentDraft;
    }

    public function destroy(Request $request, ShipmentDraft $shipmentDraft)
    {
        $this->authorizeOwner($request, $shipmentDraft);

        $shipmentDraft->delete();

        return response()->json(['message' => 'ลบฉบับร่างเรียบร้อย']);
    }
}

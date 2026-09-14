<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    /**
     * Search saved addresses across ALL customers by name/company/phone/tax_id — powers the
     * Ship From/Ship To "pick a saved address" combobox on the Create Shipment page, so staff
     * don't have to drill into a specific customer first.
     */
    public function search(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $query = CustomerAddress::query()->with('customer:id,name')->orderByDesc('is_default');

        if ($type) {
            $query->whereIn('type', [$type, 'both']);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('contact_name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('tax_id', 'like', "%{$search}%");
                    });
            });
        }

        return $query->limit(20)->get();
    }

    public function index(Customer $customer, Request $request)
    {
        $query = $customer->addresses()->orderByDesc('is_default')->orderBy('label');

        if ($type = $request->query('type')) {
            $query->whereIn('type', [$type, 'both']);
        }

        return $query->get();
    }

    public function store(Customer $customer, Request $request)
    {
        $data = $this->validated($request);

        if ($data['is_default'] ?? false) {
            $customer->addresses()->update(['is_default' => false]);
        }

        return response()->json($customer->addresses()->create($data), 201);
    }

    public function update(Request $request, CustomerAddress $address)
    {
        $data = $this->validated($request, sometimes: true);

        if ($data['is_default'] ?? false) {
            CustomerAddress::where('customer_id', $address->customer_id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $address->update($data);

        return $address;
    }

    public function destroy(CustomerAddress $address)
    {
        $address->delete();

        return response()->json(['message' => 'ลบที่อยู่เรียบร้อย']);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'type' => ['sometimes', 'in:ship_from,ship_to,both'],
            'label' => ['nullable', 'string', 'max:255'],
            'contact_name' => [$required, 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_code' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'address1' => ['nullable', 'string', 'max:1000'],
            'address2' => ['nullable', 'string', 'max:1000'],
            'address3' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['boolean'],
        ]);
    }
}

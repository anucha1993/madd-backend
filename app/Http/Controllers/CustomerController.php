<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * List customers, optionally filtered by a single search term matched against
     * name / company / phone / tax_id — used by the Ship From/Ship To customer picker.
     */
    public function index(Request $request)
    {
        $query = Customer::query()->withCount('addresses')->with('primaryAddress')->orderBy('name');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        // Company/tax/phone/email columns are just a preview of the first saved address,
        // never edited on the Customer record itself — see CustomerAddressManager (frontend).
        return $query->limit(20)->get()->map(function (Customer $customer) {
            if ($address = $customer->primaryAddress) {
                $customer->company_name = $address->company_name;
                $customer->tax_id = $address->tax_id;
                $customer->phone = $address->phone;
                $customer->email = $address->email;
            }

            return $customer->makeHidden('primaryAddress');
        });
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(Customer::create($data), 201);
    }

    public function show(Customer $customer)
    {
        return $customer->load('addresses');
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer->update($data);

        return $customer;
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json(['message' => 'ลบข้อมูลลูกค้าเรียบร้อย']);
    }
}

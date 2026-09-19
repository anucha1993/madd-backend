<?php

namespace App\Http\Controllers;

use App\Models\BillingCustomer;
use Illuminate\Http\Request;

class BillingCustomerController extends Controller
{
    private const VALIDATION_RULES = [
        'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        'name' => ['required', 'string', 'max:255'],
        'tax_id' => ['nullable', 'string', 'max:20'],
        'is_head_office' => ['boolean'],
        'branch_no' => ['nullable', 'string', 'max:50'],
        'address1' => ['nullable', 'string', 'max:255'],
        'address2' => ['nullable', 'string', 'max:255'],
        'address3' => ['nullable', 'string', 'max:255'],
        'city' => ['nullable', 'string', 'max:255'],
        'state_code' => ['nullable', 'string', 'max:50'],
        'postcode' => ['nullable', 'string', 'max:20'],
        'country' => ['nullable', 'string', 'max:100'],
        'phone' => ['nullable', 'string', 'max:50'],
        'email' => ['nullable', 'email', 'max:255'],
        'notes' => ['nullable', 'string', 'max:1000'],
    ];

    public function index(Request $request)
    {
        $query = BillingCustomer::query()->latest();

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::VALIDATION_RULES);

        return response()->json(BillingCustomer::create($data), 201);
    }

    public function show(BillingCustomer $billingCustomer)
    {
        return $billingCustomer;
    }

    public function update(Request $request, BillingCustomer $billingCustomer)
    {
        $rules = self::VALIDATION_RULES;
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];

        $data = $request->validate($rules);
        $billingCustomer->update($data);

        return $billingCustomer;
    }

    public function destroy(BillingCustomer $billingCustomer)
    {
        $billingCustomer->delete();

        return response()->json(['message' => 'ลบข้อมูลลูกค้าเรียบร้อย']);
    }
}

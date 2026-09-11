<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return User::with('branches:id,name,code')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,staff'],
            'can_access_all_branches' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        $user = User::create($data);

        if (! $user->can_access_all_branches) {
            $user->branches()->sync($branchIds);
        }

        return response()->json($user->load('branches:id,name,code'), 201);
    }

    public function show(User $user)
    {
        return $user->load('branches:id,name,code');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'required', 'in:admin,staff'],
            'can_access_all_branches' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchIds = $data['branch_ids'] ?? null;
        unset($data['branch_ids']);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if ($branchIds !== null) {
            $user->branches()->sync($user->can_access_all_branches ? [] : $branchIds);
        } elseif ($user->can_access_all_branches) {
            $user->branches()->sync([]);
        }

        return $user->load('branches:id,name,code');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'ไม่สามารถลบบัญชีของตัวเองได้'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้งานเรียบร้อย']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\RoleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();

        // `manageable` = the caller ranks strictly above this user (or is Super Admin).
        return User::with('branches:id,name,code,nickname', 'homeBranch:id,name,code,nickname', 'roles:id,key,name,is_super_admin,level')->orderBy('name')->get()
            ->each(fn (User $u) => $u->setAttribute('manageable', RoleHierarchy::canManageUser($actor, $u)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'can_access_all_branches' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            // The user's own branch (Shipments are booked under it); branch_ids = what they may see.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchIds = $data['branch_ids'] ?? [];
        $roleIds = $data['role_ids'];
        unset($data['branch_ids'], $data['role_ids']);
        $this->assertCanAssignRoles($request, $roleIds, null);

        $user = User::create($data);
        $this->syncAudited($user, 'roles', $roleIds);

        if (! $user->can_access_all_branches) {
            $this->syncAudited($user, 'branches', self::withHomeBranch($branchIds, $user->branch_id));
        }

        return response()->json($user->load('branches:id,name,code,nickname', 'homeBranch:id,name,code,nickname', 'roles:id,key,name,is_super_admin'), 201);
    }

    public function show(User $user)
    {
        return $user->load('branches:id,name,code,nickname', 'homeBranch:id,name,code,nickname', 'roles:id,key,name,is_super_admin');
    }

    public function update(Request $request, User $user)
    {
        abort_unless(RoleHierarchy::canManageUser($request->user(), $user), 403, 'แก้ไขผู้ใช้ที่ระดับ Role เท่ากับหรือสูงกว่าตัวเองไม่ได้');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:6'],
            'role_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'can_access_all_branches' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            // The user's own branch (Shipments are booked under it); branch_ids = what they may see.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchIds = $data['branch_ids'] ?? null;
        $roleIds = $data['role_ids'] ?? null;
        unset($data['branch_ids'], $data['role_ids']);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($roleIds !== null) {
            $this->assertCanAssignRoles($request, $roleIds, $user);
        }

        $user->update($data);

        if ($roleIds !== null) {
            $this->syncAudited($user, 'roles', $roleIds);
        }

        if ($branchIds !== null) {
            $this->syncAudited($user, 'branches', $user->can_access_all_branches ? [] : self::withHomeBranch($branchIds, $user->branch_id));
        } elseif ($user->can_access_all_branches) {
            $this->syncAudited($user, 'branches', []);
        } elseif ($user->branch_id && ! $user->branches()->whereKey($user->branch_id)->exists()) {
            $this->syncAudited($user, 'branches', self::withHomeBranch($user->branches()->pluck('branches.id')->all(), $user->branch_id));
        }

        return $user->load('branches:id,name,code,nickname', 'homeBranch:id,name,code,nickname', 'roles:id,key,name,is_super_admin');
    }

    /** A user always sees their own branch's data. */
    private static function withHomeBranch(array $branchIds, ?int $homeBranchId): array
    {
        return array_values(array_unique(array_map('intval', $homeBranchId ? [...$branchIds, $homeBranchId] : $branchIds)));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'ไม่สามารถลบบัญชีของตัวเองได้'], 422);
        }
        if ($this->isSuperAdmin($user) && ! $this->isSuperAdmin($request->user())) {
            return response()->json(['message' => 'เฉพาะ Super Admin เท่านั้นที่ลบผู้ใช้ Super Admin ได้'], 403);
        }
        abort_unless(RoleHierarchy::canManageUser($request->user(), $user), 403, 'ลบผู้ใช้ที่ระดับ Role เท่ากับหรือสูงกว่าตัวเองไม่ได้');

        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้งานเรียบร้อย']);
    }

    /**
     * Super-admin Roles can only be granted/removed by a super admin (otherwise user.manage would
     * be a path to unrestricted access), and the last super admin can't lose that Role.
     */
    private function assertCanAssignRoles(Request $request, array $roleIds, ?User $target): void
    {
        // Only Roles ranked below the caller can be handed out.
        $actor = $request->user();
        foreach (Role::whereIn('id', $roleIds)->where('is_super_admin', false)->get() as $role) {
            if (! RoleHierarchy::canManageRole($actor, $role)) {
                throw ValidationException::withMessages(['role_ids' => "กำหนด Role \"{$role->name}\" ไม่ได้ — ระดับเท่ากับหรือสูงกว่าตัวเอง"]);
            }
        }
        $superRoleIds = Role::where('is_super_admin', true)->pluck('id')->all();
        $grantsSuper = (bool) array_intersect($roleIds, $superRoleIds);
        $hadSuper = $target && $this->isSuperAdmin($target);

        if (($grantsSuper || $hadSuper) && $grantsSuper !== $hadSuper && ! $this->isSuperAdmin($request->user())) {
            throw ValidationException::withMessages(['role_ids' => 'เฉพาะ Super Admin เท่านั้นที่กำหนด Role Super Admin ได้']);
        }
        if ($grantsSuper && ! $hadSuper) {
            return;
        }
        if ($hadSuper && ! $grantsSuper
            && User::whereKeyNot($target->id)->whereHas('roles', fn ($q) => $q->where('is_super_admin', true))->doesntExist()) {
            throw ValidationException::withMessages(['role_ids' => 'ต้องมีผู้ใช้ Super Admin เหลืออย่างน้อย 1 คน']);
        }
    }

    /**
     * Pivot syncs fire no model events, so role/branch assignment changes (the ones that
     * actually change what a user can do) are written to the audit log here, by name.
     */
    private function syncAudited(User $user, string $relation, array $ids): void
    {
        $nameColumn = $relation === 'roles' ? 'roles.name' : 'branches.code';
        $before = $user->{$relation}()->pluck($nameColumn)->sort()->values()->all();
        $user->{$relation}()->sync($ids);
        $after = $user->{$relation}()->pluck($nameColumn)->sort()->values()->all();

        if ($before !== $after) {
            app(AuditLogger::class)->record("{$relation}_changed", $user, [$relation => ['old' => $before, 'new' => $after]], $user->username);
        }
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('is_super_admin', true)->exists();
    }
}

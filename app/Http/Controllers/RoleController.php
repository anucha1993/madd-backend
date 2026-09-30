<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Roles UI backend — what can be granted comes from config/permissions.php (served as-is by
 * registry()), a Role only stores its selections. Anything submitted that isn't in the registry
 * is dropped rather than stored, so a stale/forged key can never grant something.
 */
class RoleController extends Controller
{
    public function registry()
    {
        return response()->json(config('permissions'));
    }

    public function index()
    {
        return Role::withCount('users')->orderByDesc('is_super_admin')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $role = Role::create($this->validated($request));

        return response()->json($role->loadCount('users'), 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);
        // The super-admin flag is only ever changed by a super admin — otherwise anyone holding
        // user.roles could promote their own role to unrestricted access.
        if (($data['is_super_admin'] ?? false) !== $role->is_super_admin && ! $this->actorIsSuperAdmin($request)) {
            unset($data['is_super_admin']);
        }
        if ($role->is_super_admin && ($data['is_super_admin'] ?? true) === false
            && Role::where('is_super_admin', true)->whereKeyNot($role->id)->doesntExist()) {
            throw ValidationException::withMessages(['is_super_admin' => 'ต้องมี Role ที่เป็น Super Admin อย่างน้อย 1 Role']);
        }

        $role->update($data);

        return $role->loadCount('users');
    }

    public function destroy(Role $role)
    {
        if ($role->is_super_admin && Role::where('is_super_admin', true)->whereKeyNot($role->id)->doesntExist()) {
            return response()->json(['message' => 'ลบไม่ได้ — ต้องมี Role ที่เป็น Super Admin อย่างน้อย 1 Role'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Role นี้ยังมีผู้ใช้งานอยู่ กรุณาย้ายผู้ใช้ไป Role อื่นก่อน'], 422);
        }

        $role->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $modules = config('permissions.modules');

        $data = $request->validate([
            'key' => [$role ? 'sometimes' : 'required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'key')->ignore($role?->id)],
            'name' => [$role ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_super_admin' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
            'field_access' => ['array'],
            'data_scopes' => ['array'],
        ]);

        if ($role?->is_system) {
            unset($data['key']);
        }
        if (! $role && ($data['is_super_admin'] ?? false) && ! $this->actorIsSuperAdmin($request)) {
            $data['is_super_admin'] = false;
        }

        if (array_key_exists('permissions', $data) || ! $role) {
            $valid = [];
            foreach ($modules as $module => $def) {
                foreach (array_keys($def['actions'] ?? []) as $action) {
                    $valid[] = "{$module}.{$action}";
                }
            }
            $data['permissions'] = array_values(array_intersect($valid, $data['permissions'] ?? []));
        }

        if (array_key_exists('field_access', $data) || ! $role) {
            $fieldAccess = [];
            foreach ($modules as $module => $def) {
                foreach ($def['fields'] ?? [] as $group => $groupDef) {
                    $level = $data['field_access'][$module][$group] ?? $groupDef['default'] ?? 'hidden';
                    if ($level === 'edit' && empty($groupDef['inputs'])) {
                        $level = 'view'; // read-only group — nothing to edit
                    }
                    $fieldAccess[$module][$group] = in_array($level, ['hidden', 'view', 'edit'], true) ? $level : 'hidden';
                }
            }
            $data['field_access'] = $fieldAccess;
        }

        if (array_key_exists('data_scopes', $data) || ! $role) {
            $scopes = [];
            foreach ($modules as $module => $def) {
                if (! empty($def['scope'])) {
                    $scope = $data['data_scopes'][$module] ?? 'own';
                    $scopes[$module] = array_key_exists($scope, config('permissions.scopes')) ? $scope : 'own';
                }
            }
            $data['data_scopes'] = $scopes;
        }

        return $data;
    }

    private function actorIsSuperAdmin(Request $request): bool
    {
        return $request->user()->roles()->where('is_super_admin', true)->exists();
    }
}

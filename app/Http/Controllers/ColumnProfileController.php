<?php

namespace App\Http\Controllers;

use App\Models\ColumnProfile;
use App\Models\Role;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Column Profiles for list pages with "Manage Columns". Only `config.column_profiles` holders
 * decide which columns a profile has and which Roles get it; everyone else only receives the
 * profiles available to their Roles and may re-order those columns locally. Column visibility
 * still sits under field access — a profile can list a cost column, but the API strips its
 * data for a Role that can't see cost (and the page drops the column).
 */
class ColumnProfileController extends Controller
{
    public function __construct(private AccessService $access)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => ['required', 'string', Rule::in($this->pageKeys())]]);
        $user = $request->user();
        $canManage = $this->access->can($user, 'config.column_profiles');
        $roleIds = $user->roles()->pluck('roles.id')->all();

        $profiles = ColumnProfile::where('page_key', $data['page'])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (ColumnProfile $p) => $canManage || $p->isAvailableTo($roleIds))
            ->values();

        return response()->json([
            'can_manage' => $canManage,
            'profiles' => $canManage ? $profiles : $profiles->map->only(['id', 'name', 'columns', 'group_of']),
            // Only needed by the profile editor's "ใช้ได้กับ Role" picker.
            'roles' => $canManage ? Role::orderBy('id')->get(['id', 'name']) : [],
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(ColumnProfile::create($this->validated($request)), 201);
    }

    public function update(Request $request, ColumnProfile $columnProfile)
    {
        $columnProfile->update($this->validated($request, $columnProfile));

        return $columnProfile;
    }

    public function destroy(ColumnProfile $columnProfile)
    {
        $columnProfile->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?ColumnProfile $profile = null): array
    {
        $data = $request->validate([
            'page_key' => [$profile ? 'sometimes' : 'required', 'string', Rule::in($this->pageKeys())],
            'name' => [$profile ? 'sometimes' : 'required', 'string', 'max:100'],
            'columns' => [$profile ? 'sometimes' : 'required', 'array', 'min:1'],
            'columns.*' => ['string', 'max:100'],
            'group_of' => ['nullable', 'array'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('columns', $data)) {
            $data['columns'] = array_values(array_unique($data['columns']));
        }
        if (array_key_exists('group_of', $data) || ! $profile) {
            $data['group_of'] = $data['group_of'] ?? [];
        }
        if (array_key_exists('role_ids', $data) || ! $profile) {
            $data['role_ids'] = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
        }

        return $data;
    }

    private function pageKeys(): array
    {
        return array_keys(config('permissions.column_pages', []));
    }
}

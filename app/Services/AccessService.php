<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves what a user may do from ALL of their Roles combined (config/permissions.php is the
 * registry of what exists). Roles only ever ADD access — the most generous Role wins:
 * permissions are a union, each field group takes the highest level, each data scope the widest.
 * A user with no Role at all gets nothing (no actions, every field at its registry default
 * capped to 'view', scope 'own').
 *
 * Registered as a singleton (see AppServiceProvider) so one request resolves each user once.
 */
class AccessService
{
    private const LEVEL_RANK = ['hidden' => 0, 'view' => 1, 'edit' => 2];

    private const SCOPE_RANK = ['own' => 0, 'branch' => 1, 'all' => 2];

    /** @var array<int, array> */
    private array $resolved = [];

    /**
     * @return array{is_super_admin:bool, roles:array, permissions:array<int,string>, fields:array<string,array<string,string>>, scopes:array<string,string>}
     */
    public function resolve(User $user): array
    {
        return $this->resolved[$user->id] ??= $this->build($user);
    }

    public function forget(User $user): void
    {
        unset($this->resolved[$user->id]);
    }

    public function can(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        $access = $this->resolve($user);

        return $access['is_super_admin'] || in_array($permission, $access['permissions'], true);
    }

    public function fieldLevel(?User $user, string $module, string $group): string
    {
        // No authenticated user = console/queue context (scheduled reports, backfills) — those
        // aren't a viewer, so nothing is restricted.
        if (! $user) {
            return 'edit';
        }

        return $this->resolve($user)['fields'][$module][$group] ?? 'hidden';
    }

    /** Columns/appended attributes to strip from $module's JSON output for this user. */
    public function hiddenColumns(?User $user, string $module): array
    {
        if (! $user) {
            return [];
        }
        $columns = [];
        foreach (config("permissions.modules.{$module}.fields", []) as $group => $def) {
            if ($this->fieldLevel($user, $module, $group) === 'hidden') {
                array_push($columns, ...$def['columns']);
            }
        }

        return $columns;
    }

    /**
     * Strips the `rate` field groups this user may not see from one rate quote (check-rate
     * result, AI rate-chat quote, or a Shipment's saved rate_quote). Keys are quote-level
     * ('raw') or per chargeBreakdown line ('chargeBreakdown.*.markupBase'). The sell price and
     * the breakdown amounts themselves are never touched.
     */
    public function sanitizeRateQuote(?User $user, array $quote): array
    {
        if (! $user) {
            return $quote;
        }
        foreach (config('permissions.modules.rate.fields', []) as $group => $def) {
            if ($this->fieldLevel($user, 'rate', $group) !== 'hidden') {
                continue;
            }
            foreach ($def['columns'] as $column) {
                if (str_starts_with($column, 'chargeBreakdown.*.')) {
                    $key = substr($column, strlen('chargeBreakdown.*.'));
                    if (is_array($quote['chargeBreakdown'] ?? null)) {
                        $quote['chargeBreakdown'] = array_map(function ($line) use ($key) {
                            unset($line[$key]);

                            return $line;
                        }, $quote['chargeBreakdown']);
                    }
                } elseif ($column === 'chargeBreakdown' && is_array($quote['chargeBreakdown'] ?? null)) {
                    // Keep only the carrier-insurance lines the booking form's pricing math needs.
                    $quote['chargeBreakdown'] = array_values(array_filter(
                        $quote['chargeBreakdown'],
                        fn ($line) => in_array((string) ($line['code'] ?? ''), ChargeMarkupService::COST_ONLY_CODES, true),
                    ));
                } else {
                    unset($quote[$column]);
                }
            }
        }

        return $quote;
    }

    public function scope(User $user, string $module): string
    {
        return $this->resolve($user)['scopes'][$module] ?? 'own';
    }

    /**
     * Limits $query to the records of $module this user may see. 'branch' also always includes
     * the user's OWN records (e.g. something booked while temporarily assigned elsewhere).
     */
    public function applyScope(Builder $query, User $user, string $module): Builder
    {
        $scope = $this->scope($user, $module);
        if ($scope === 'all' || ($scope === 'branch' && $user->can_access_all_branches)) {
            return $query;
        }

        $branchIds = $scope === 'branch' ? $user->branches()->pluck('branches.id')->all() : [];

        return $query->where(function ($q) use ($module, $user, $branchIds) {
            $q->where($q->qualifyColumn('created_by'), $user->id);
            if (! $branchIds) {
                return;
            }
            if ($module === 'pickup') {
                // Pickups have no branch of their own — they belong to the branches of the
                // shipments they collect.
                $q->orWhereHas('shipments', fn ($s) => $s->whereIn('shipments.branch_id', $branchIds));
            } else {
                $q->orWhereIn($q->qualifyColumn('branch_id'), $branchIds);
            }
        });
    }

    public function canSeeRecord(User $user, Model $record, string $module): bool
    {
        return $this->applyScope($record->newQuery()->whereKey($record->getKey()), $user, $module)->exists();
    }

    /**
     * Rejects the request (403) if it submits any `inputs` of a field group the user can't
     * edit. A key that's absent, empty, or equal to the registry default is not a change.
     */
    public function assertWritableFields(Request $request, string $module): void
    {
        $user = $request->user();
        foreach (config("permissions.modules.{$module}.fields", []) as $group => $def) {
            if (empty($def['inputs']) || $this->fieldLevel($user, $module, $group) === 'edit') {
                continue;
            }
            foreach ($def['inputs'] as $key => $default) {
                $value = $request->input($key);
                if ($value === null || $value === '' || (string) $value === (string) $default) {
                    continue;
                }
                throw new HttpException(403, "ไม่มีสิทธิ์แก้ไขข้อมูล \"{$def['label']}\"");
            }
        }
    }

    private function build(User $user): array
    {
        $roles = $user->roles()->get();
        $isSuperAdmin = $roles->contains('is_super_admin', true);
        $modules = config('permissions.modules', []);

        $permissions = [];
        $fields = [];
        $scopes = [];

        foreach ($modules as $module => $def) {
            foreach (array_keys($def['actions'] ?? []) as $action) {
                $key = "{$module}.{$action}";
                if ($isSuperAdmin || $roles->contains(fn (Role $r) => in_array($key, $r->permissions ?? [], true))) {
                    $permissions[] = $key;
                }
            }

            foreach ($def['fields'] ?? [] as $group => $groupDef) {
                $maxLevel = empty($groupDef['inputs']) ? 'view' : 'edit';
                if ($isSuperAdmin) {
                    $fields[$module][$group] = $maxLevel;

                    continue;
                }
                $level = $roles->isEmpty() ? $this->capLevel($groupDef['default'] ?? 'hidden', 'view') : 'hidden';
                foreach ($roles as $role) {
                    $roleLevel = $role->field_access[$module][$group] ?? $groupDef['default'] ?? 'hidden';
                    if ((self::LEVEL_RANK[$roleLevel] ?? 0) > self::LEVEL_RANK[$level]) {
                        $level = $roleLevel;
                    }
                }
                $fields[$module][$group] = $this->capLevel($level, $maxLevel);
            }

            if (! empty($def['scope'])) {
                $scope = 'own';
                foreach ($roles as $role) {
                    $roleScope = $isSuperAdmin ? 'all' : ($role->data_scopes[$module] ?? 'own');
                    if ((self::SCOPE_RANK[$roleScope] ?? 0) > self::SCOPE_RANK[$scope]) {
                        $scope = $roleScope;
                    }
                }
                $scopes[$module] = $isSuperAdmin ? 'all' : $scope;
            }
        }

        return [
            'is_super_admin' => $isSuperAdmin,
            'roles' => $roles->map(fn (Role $r) => ['id' => $r->id, 'key' => $r->key, 'name' => $r->name])->values()->all(),
            'permissions' => $permissions,
            'fields' => $fields,
            'scopes' => $scopes,
        ];
    }

    private function capLevel(string $level, string $max): string
    {
        return (self::LEVEL_RANK[$level] ?? 0) > self::LEVEL_RANK[$max] ? $max : $level;
    }
}

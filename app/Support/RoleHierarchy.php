<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessService;

/**
 * Who may manage whom. Super Admin is above everything (and needs no permissions set). Everyone
 * else may only touch Roles — and users — ranked strictly BELOW their own highest Role level:
 * equal can't edit equal, nobody edits upward. And a Role can only be given what its editor has.
 */
final class RoleHierarchy
{
    public static function isSuper(User $user): bool
    {
        return $user->roles()->where('is_super_admin', true)->exists();
    }

    /** The user's rank: their highest Role level (Super Admin = above any level). */
    public static function levelOf(User $user): int
    {
        return self::isSuper($user) ? PHP_INT_MAX : (int) ($user->roles()->max('level') ?? 0);
    }

    public static function canManageRole(User $actor, Role $role): bool
    {
        if (self::isSuper($actor)) {
            return true;
        }

        return ! $role->is_super_admin && (int) $role->level < self::levelOf($actor);
    }

    public static function canManageUser(User $actor, User $target): bool
    {
        if (self::isSuper($actor)) {
            return true;
        }

        return $actor->id !== $target->id && self::levelOf($target) < self::levelOf($actor);
    }

    /**
     * What a non-super editor may set on a Role: permissions / field levels / data scopes only up to
     * their own. Anything the Role already has beyond the editor's reach is kept as it was (they can
     * neither grant nor take away what they don't hold themselves).
     */
    public static function clampGrants(User $actor, array $data, ?Role $existing): array
    {
        if (self::isSuper($actor)) {
            return $data;
        }
        $mine = app(AccessService::class)->resolve($actor);

        if (array_key_exists('permissions', $data)) {
            $had = $existing?->permissions ?? [];
            $data['permissions'] = array_values(array_unique([
                ...array_intersect($data['permissions'], $mine['permissions']),
                ...array_diff($had, $mine['permissions']),
            ]));
        }

        $levelRank = ['hidden' => 0, 'view' => 1, 'edit' => 2];
        if (array_key_exists('field_access', $data)) {
            foreach ($data['field_access'] as $module => $groups) {
                foreach ($groups as $group => $level) {
                    $myLevel = $mine['fields'][$module][$group] ?? 'hidden';
                    $had = $existing?->field_access[$module][$group] ?? null;
                    if ($had !== null && $levelRank[$had] > $levelRank[$myLevel]) {
                        $data['field_access'][$module][$group] = $had;          // beyond my reach — untouched
                    } elseif ($levelRank[$level] > $levelRank[$myLevel]) {
                        $data['field_access'][$module][$group] = $myLevel;      // no more than I have
                    }
                }
            }
        }

        $scopeRank = ['own' => 0, 'branch' => 1, 'all' => 2];
        if (array_key_exists('data_scopes', $data)) {
            foreach ($data['data_scopes'] as $module => $scope) {
                $myScope = $mine['scopes'][$module] ?? 'own';
                $had = $existing?->data_scopes[$module] ?? null;
                if ($had !== null && ($scopeRank[$had] ?? 0) > ($scopeRank[$myScope] ?? 0)) {
                    $data['data_scopes'][$module] = $had;
                } elseif (($scopeRank[$scope] ?? 0) > ($scopeRank[$myScope] ?? 0)) {
                    $data['data_scopes'][$module] = $myScope;
                }
            }
        }

        return $data;
    }
}

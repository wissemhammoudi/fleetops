<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Support\Auth;

class CollectionConsoleAccess
{
    public const ACTIONS = [
        'read' => 'view pickup-round',
        'plan' => 'plan pickup-round',
        'assign' => 'assign pickup-round',
        'release' => 'release pickup-round',
        'cancel' => 'cancel pickup-round',
        'labels' => 'labels pickup-round',
        'scan' => 'scan depot-custody',
        'reconcile' => 'reconcile depot-custody',
        'resolve' => 'resolve depot-custody',
        'assign-delivery' => 'assign delivery-handover',
        'load-delivery' => 'load delivery-handover',
        'release-delivery' => 'release delivery-handover',
        'recall-delivery' => 'recall delivery-handover',
    ];

    public static function allowed(string $action): bool
    {
        if (!isset(self::ACTIONS[$action]) || !session('company') || !session('user')
            || session('is_customer') || session('is_driver')) {
            return false;
        }

        return session('is_admin') || Auth::can('fleet-ops ' . self::ACTIONS[$action]);
    }

    public static function authorize(string $action): string
    {
        abort_unless(self::allowed('read') && self::allowed($action), 403, 'The requested collection operation is not permitted.');

        return (string) session('company');
    }

    public static function permissions(): array
    {
        $permissions = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            $permissions[$action] = self::allowed($action);
        }

        return $permissions;
    }
}

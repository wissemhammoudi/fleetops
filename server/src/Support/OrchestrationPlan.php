<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Short-lived, company-bound optimistic snapshots. Resource rows are locked again
 * on commit; the company lock serializes competing workbench commits.
 */
class OrchestrationPlan
{
    public const LIMIT = 500;

    public static function capture(string $company, array $ids, bool $lock = false): array
    {
        $snapshot = [];
        $rows = [];
        foreach (['orders', 'vehicles', 'drivers'] as $table) {
            $query = DB::table($table)->where('company_uuid', $company)->whereNull('deleted_at')->orderBy('uuid');
            if (isset($ids[$table])) {
                $query->whereIn('public_id', $ids[$table]);
            }
            $rows[$table] = ($lock ? $query->lockForUpdate() : $query)->limit(self::LIMIT + 1)->get();
            abort_if($rows[$table]->count() > self::LIMIT, 422, 'Select at most 500 orders, vehicles and drivers per plan.');
            if (isset($ids[$table])) {
                abort_if($rows[$table]->count() !== count(array_unique($ids[$table])), 422, 'Unknown or unavailable company resource.');
            }
            foreach ($rows[$table] as $row) {
                $snapshot[$table][$row->public_id] = hash('sha256', serialize((array) $row));
            }
            $snapshot[$table] ??= [];
        }

        $payloadIds = $rows['orders']->pluck('payload_uuid')->filter()->unique()->values()->all();
        $payloads = self::related('payloads', 'uuid', $payloadIds, $lock);
        foreach ($payloads as $payload) {
            abort_if($payload->company_uuid !== $company || $payload->deleted_at !== null, 422, 'Unavailable company payload.');
        }
        abort_if($payloads->count() !== count($payloadIds), 422, 'Unavailable order payload.');
        $waypoints = self::related('waypoints', 'payload_uuid', $payloadIds, $lock);
        $entities = self::related('entities', 'payload_uuid', $payloadIds, $lock);
        foreach ($waypoints->merge($entities) as $record) {
            abort_if($record->company_uuid !== $company, 422, 'Unavailable company payload resource.');
        }
        $placeIds = $payloads->flatMap(fn ($p) => [$p->pickup_uuid, $p->dropoff_uuid, $p->return_uuid])
            ->merge($waypoints->pluck('place_uuid'))->filter()->unique()->values()->all();
        $places = self::related('places', 'uuid', $placeIds, $lock);
        foreach ($places as $place) {
            abort_if($place->company_uuid !== $company || $place->deleted_at !== null, 422, 'Unavailable company place.');
        }
        abort_if($places->count() !== count($placeIds), 422, 'Unavailable order place.');
        foreach (['payloads' => $payloads, 'waypoints' => $waypoints, 'entities' => $entities, 'places' => $places] as $table => $records) {
            $snapshot['related'][$table] = hash('sha256', serialize($records->map(fn ($r) => (array) $r)->all()));
        }

        return $snapshot;
    }

    private static function related(string $table, string $key, array $ids, bool $lock)
    {
        $query = DB::table($table)->whereIn($key, $ids)->orderBy('uuid');

        $records = ($lock ? $query->lockForUpdate() : $query)->limit(10001)->get();
        abort_if($records->count() > 10000, 422, 'Plan exceeds the payload resource limit.');

        return $records;
    }

    public static function propose(string $company, array $snapshot): string
    {
        $version = (string) Str::uuid();
        DB::table('orchestration_plans')->insert([
            'uuid' => $version, 'company_uuid' => $company,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $version;
    }

    public static function load(string $company, string $version, bool $lock = false): object
    {
        $query = DB::table('orchestration_plans')->where('company_uuid', $company)->where('uuid', $version);
        $plan = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($plan, 409, 'Unknown plan version. Run and review a new plan.');

        return $plan;
    }

    public static function assertFresh(object $plan, string $company, bool $lock = false): array
    {
        abort_if($plan->request_id || now()->greaterThan($plan->expires_at), 409, 'Plan is committed or expired. Run and review a new plan.');
        $snapshot = json_decode($plan->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        foreach (['orders', 'vehicles', 'drivers'] as $table) {
            $ids[$table] = array_keys($snapshot[$table]);
        }
        try {
            $current = self::capture($company, $ids, $lock);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            abort(409, 'Plan resources are no longer available. Run and review a new plan.');
        }
        abort_unless($snapshot === $current, 409, 'Plan data changed. Run and review a new plan.');

        return $snapshot;
    }
}

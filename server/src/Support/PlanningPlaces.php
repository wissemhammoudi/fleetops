<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanningPlaces
{
    public const SETTING_KEY = 'fleet-ops.planning';

    public static function configuration(string $company): array
    {
        return Setting::lookup('company.' . $company . '.' . self::SETTING_KEY, [
            'default_depot_uuid' => null, 'revision' => 0,
        ]);
    }

    public static function places(string $company): array
    {
        return DB::connection(config('fleetbase.connection.db', 'mysql'))->table('places')
            ->where('company_uuid', $company)->whereNull('deleted_at')->orderBy('name')->orderBy('uuid')
            ->select(['uuid', 'name', 'street1', 'phone'])->selectRaw('ST_AsText(location) as location_wkt')
            ->get()->map(fn ($place) => self::summary($place))->all();
    }

    public static function summaries(string $company, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return DB::connection(config('fleetbase.connection.db', 'mysql'))->table('places')
            ->where('company_uuid', $company)->whereNull('deleted_at')->whereIn('uuid', array_values(array_unique($ids)))
            ->select(['uuid', 'name', 'street1', 'phone'])->selectRaw('ST_AsText(location) as location_wkt')
            ->get()->mapWithKeys(fn ($place) => [$place->uuid => self::summary($place)])->all();
    }

    public static function depotIds(string $company): array
    {
        $configuration = self::configuration($company);

        return $configuration['depot_uuids'] ?? array_values(array_filter([$configuration['default_depot_uuid'] ?? null]));
    }

    public static function depots(string $company): array
    {
        $ids = self::depotIds($company);

        return array_values(array_filter(self::places($company), fn ($place) => in_array($place['uuid'], $ids, true)));
    }

    public static function depot(string $company, string $uuid): array
    {
        abort_unless(in_array($uuid, self::depotIds($company), true), 422, 'Choose a depot registered in Organization management.');

        return self::snapshot($company, $uuid);
    }

    public static function snapshot(string $company, string $uuid): array
    {
        $place = DB::connection(config('fleetbase.connection.db', 'mysql'))->table('places')
            ->where('company_uuid', $company)->where('uuid', $uuid)->whereNull('deleted_at')
            ->select(['uuid', 'name', 'street1', 'phone'])->selectRaw('ST_AsText(location) as location_wkt')->lockForUpdate()->first();
        if (!$place || !($snapshot = self::summary($place))['routable']) {
            throw ValidationException::withMessages(['place_uuid' => 'Choose a saved place from this company with a street address and valid map coordinates.']);
        }

        return $snapshot;
    }

    public static function assertCurrent(string $company, array $snapshot): void
    {
        $current = self::snapshot($company, $snapshot['uuid']);
        foreach (['uuid', 'name', 'street1', 'phone'] as $field) {
            abort_unless($current[$field] === $snapshot[$field], 409, 'A saved route address changed. Review the pickup assignment before releasing it.');
        }
        foreach (['lat', 'lng'] as $coordinate) {
            abort_unless($current['location'][$coordinate] === (float) $snapshot['location'][$coordinate],
                409, 'A saved route map position changed. Review the pickup assignment before releasing it.');
        }
    }

    public static function save(string $company, string $actor, array $input): array
    {
        $connection = (new Setting())->getConnection();

        return $connection->transaction(function () use ($company, $actor, $input, $connection) {
            abort_unless($connection->table('companies')->where('uuid', $company)->lockForUpdate()->first(['uuid']), 404, 'Company not found.');
            self::snapshot($company, $input['default_depot_uuid']);
            $key = 'company.' . $company . '.' . self::SETTING_KEY;
            Setting::firstOrCreate(['key' => $key], ['value' => ['default_depot_uuid' => null, 'revision' => 0]]);
            $setting = Setting::where('key', $key)->lockForUpdate()->firstOrFail();
            $previous = $setting->value;
            $revision = (int) $input['revision'];
            abort_unless(($previous['revision'] ?? 0) === $revision, 409, 'Planning configuration changed. Refresh before saving.');
            $depots = $input['depot_uuids'] ?? array_values(array_unique([...self::depotIds($company), $input['default_depot_uuid']]));
            if (!$depots || !in_array($input['default_depot_uuid'], $depots, true)) {
                throw ValidationException::withMessages(['default_depot_uuid' => 'Keep at least one depot and select its default.']);
            }
            foreach ($depots as $uuid) self::snapshot($company, $uuid);
            $configuration = ['default_depot_uuid' => $input['default_depot_uuid'], 'depot_uuids' => array_values($depots), 'revision' => $revision + 1];
            $history = $previous['history'] ?? [];
            $history[] = ['actor_uuid' => $actor, 'at' => now()->toISOString()] + $configuration;
            $setting->value = $configuration + ['history' => $history];
            $setting->save();

            return $configuration;
        }, 3);
    }

    private static function summary(object $place): array
    {
        $location = null;
        if (preg_match('/^POINT\s*\(\s*([-+\d.eE]+)\s+([-+\d.eE]+)\s*\)$/i', (string) $place->location_wkt, $matches)
            && is_numeric($matches[1]) && is_numeric($matches[2])) {
            $lng = (float) $matches[1];
            $lat = (float) $matches[2];
            if (is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180) {
                $location = ['lat' => $lat, 'lng' => $lng];
            }
        }

        return ['uuid' => $place->uuid, 'name' => $place->name, 'street1' => $place->street1,
            'phone' => $place->phone, 'location' => $location,
            'routable' => trim((string) $place->street1) !== '' && $location !== null];
    }
}

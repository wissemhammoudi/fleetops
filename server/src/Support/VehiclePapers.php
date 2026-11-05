<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Carbon;

/**
 * Tunisian vehicle papers stored on `vehicles.meta.papers`: carte grise number,
 * insurance / visite technique / vignette expiry dates and private scan ids.
 * Insurance and visite technique drive the expiry alerts.
 */
class VehiclePapers
{
    public const DATES = ['insurance_expires_at', 'visite_technique_expires_at', 'vignette_expires_at'];

    public const ALERT_DATES = ['insurance_expires_at', 'visite_technique_expires_at'];

    public const DOCUMENTS = ['carte_grise', 'insurance'];

    public const SOON_DAYS = 30;

    public static function rules(string $prefix = 'meta.papers.'): array
    {
        $rules = [$prefix . 'carte_grise_number' => ['nullable', 'string', 'max:50']];
        foreach (self::DATES as $date) {
            $rules[$prefix . $date] = ['nullable', 'date_format:Y-m-d'];
        }
        foreach (self::DOCUMENTS as $document) {
            $rules[$prefix . 'documents.' . $document] = ['nullable', 'uuid'];
        }

        return $rules;
    }

    public static function of($vehicle): array
    {
        $papers = data_get($vehicle, 'meta.papers');

        return is_array($papers) ? $papers : [];
    }

    /** @return array<int, array{kind: string, status: string, date: string}> */
    public static function alerts($vehicle, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $papers = self::of($vehicle);
        $alerts = [];
        foreach (self::ALERT_DATES as $kind) {
            $value = $papers[$kind] ?? null;
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                continue;
            }
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            if ($date->lt($today)) {
                $alerts[] = ['kind' => $kind, 'status' => 'expired', 'date' => $value];
            } elseif ($date->lte($today->copy()->addDays(self::SOON_DAYS))) {
                $alerts[] = ['kind' => $kind, 'status' => 'expiring', 'date' => $value];
            }
        }

        return $alerts;
    }

    /** One of `expired`, `expiring`, `missing` (no alert dates recorded) or `valid`. */
    public static function status($vehicle, ?Carbon $today = null): string
    {
        $statuses = array_column(self::alerts($vehicle, $today), 'status');
        if (in_array('expired', $statuses, true)) {
            return 'expired';
        }
        if ($statuses) {
            return 'expiring';
        }
        $papers = self::of($vehicle);
        foreach (self::ALERT_DATES as $kind) {
            if (empty($papers[$kind])) {
                return 'missing';
            }
        }

        return 'valid';
    }
}

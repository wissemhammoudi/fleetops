<?php

namespace Fleetbase\FleetOps\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Arr;

class CollectionSchedule
{
    public const SETTING_KEY = 'fleet-ops.collection-schedule';

    public static function defaults(): array
    {
        return [
            'timezone' => 'Africa/Tunis',
            'operating_days' => [1, 2, 3, 4, 5, 6],
            'cutoff_time' => '12:00',
            'departure_time' => '14:00',
            'closed_dates' => [],
            'pickup_windows' => [],
            'capacity_planning' => false,
            'fallback_parcel_capacity' => null,
            'max_pickup_stops' => null,
        ];
    }

    public static function validate(array $input): array
    {
        $validated = Validator::make(['schedule' => $input], [
            'schedule' => ['required', 'array:timezone,operating_days,cutoff_time,departure_time,closed_dates,pickup_windows,capacity_planning,fallback_parcel_capacity,max_pickup_stops'],
            'schedule.timezone' => ['required', 'timezone'],
            'schedule.operating_days' => ['required', 'array', 'min:1', 'max:7'],
            'schedule.operating_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'schedule.cutoff_time' => ['required', 'date_format:H:i'],
            'schedule.departure_time' => ['required', 'date_format:H:i', 'after:schedule.cutoff_time'],
            'schedule.closed_dates' => ['present', 'array', 'max:366'],
            'schedule.closed_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'schedule.pickup_windows' => ['sometimes', 'array', 'max:12'],
            'schedule.pickup_windows.*' => ['required', 'array:start,end'],
            'schedule.pickup_windows.*.start' => ['required', 'date_format:H:i', 'distinct', 'after:schedule.cutoff_time'],
            'schedule.pickup_windows.*.end' => ['required', 'date_format:H:i'],
            'schedule.capacity_planning' => ['sometimes', 'boolean'],
            'schedule.fallback_parcel_capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'schedule.max_pickup_stops' => ['nullable', 'required_if:schedule.capacity_planning,true', 'integer', 'min:1', 'max:1000'],
        ])->validate()['schedule'];
        $validated = array_merge(self::defaults(), $validated);
        $validated['pickup_windows'] = $validated['pickup_windows'] ?? [];
        usort($validated['pickup_windows'], fn ($a, $b) => strcmp($a['start'], $b['start']));
        $previousEnd = null;
        foreach ($validated['pickup_windows'] as $window) {
            if ($window['end'] <= $window['start'] || ($previousEnd && $window['start'] < $previousEnd)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pickup_windows' => 'Pickup windows must end after they start and must not overlap.']);
            }
            $previousEnd = $window['end'];
        }
        $validated['operating_days'] = array_map('intval', $validated['operating_days']);
        sort($validated['operating_days']);
        sort($validated['closed_dates']);

        return $validated;
    }

    public static function forCompany(string $companyUuid): array
    {
        abort_if($companyUuid === '', 422, 'A company is required for collection scheduling.');
        $stored = Setting::lookup('company.' . $companyUuid . '.' . self::SETTING_KEY, self::defaults());

        return self::validate(Arr::only($stored, array_keys(self::defaults())));
    }

    public static function saveForCompany(string $companyUuid, string $actorUuid, array $input): array
    {
        abort_if($companyUuid === '' || $actorUuid === '', 403, 'A company and actor are required to edit collection scheduling.');
        $schedule = self::validate($input);
        $connection = (new Setting())->getConnection();

        return $connection->transaction(function () use ($companyUuid, $actorUuid, $schedule, $connection) {
            abort_unless($connection->table('companies')->where('uuid', $companyUuid)->lockForUpdate()->first(['uuid']), 404, 'Company not found.');
            $key = 'company.' . $companyUuid . '.' . self::SETTING_KEY;
            Setting::firstOrCreate(['key' => $key], ['value' => self::defaults()]);
            $setting = Setting::where('key', $key)->lockForUpdate()->firstOrFail();
            $history = data_get($setting->value, 'history', []);
            $history[] = ['at' => now()->toISOString(), 'actor_uuid' => $actorUuid, 'schedule' => $schedule];
            $setting->value = array_merge($schedule, ['history' => $history]);
            $setting->save();

            return $schedule;
        }, 3);
    }

    public static function pickupAt(CarbonInterface $readyAt, array $schedule): CarbonImmutable
    {
        $schedule = self::validate($schedule);
        $local = CarbonImmutable::instance($readyAt)->setTimezone($schedule['timezone']);
        $date = $local->startOfDay();
        if ($local->format('H:i') >= $schedule['cutoff_time']) {
            $date = $date->addDay();
        }

        return self::operatingDate($date, $schedule)->setTimeFromTimeString($schedule['departure_time'])->utc();
    }

    public static function deliveryDate(CarbonInterface $receivedAt, array $schedule): string
    {
        $schedule = self::validate($schedule);
        $date = CarbonImmutable::instance($receivedAt)->setTimezone($schedule['timezone'])->startOfDay()->addDay();

        return self::operatingDate($date, $schedule)->toDateString();
    }

    public static function pickupSlots(CarbonInterface $earliest, array $schedule, ?string $date = null): array
    {
        $first = self::pickupAt($earliest, $schedule)->setTimezone($schedule['timezone'])->startOfDay();
        $day = $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $schedule['timezone']) : $first;
        abort_unless($day && $day->toDateString() === ($date ?? $first->toDateString()) && $day->greaterThanOrEqualTo($first),
            422, 'Choose a pickup date on or after the earliest available collection day.');
        abort_unless(self::operatingDate($day, $schedule)->equalTo($day), 422, 'There are no pickups on this closed date.');

        return array_map(fn ($window) => [
            'start' => $day->setTimeFromTimeString($window['start'])->utc()->toISOString(),
            'end' => $day->setTimeFromTimeString($window['end'])->utc()->toISOString(),
            'label' => $window['start'] . ' - ' . $window['end'],
        ], $schedule['pickup_windows'] ?? []);
    }

    public static function containsPickup(CarbonInterface $planned, array $schedule, ?string $end = null): bool
    {
        $local = CarbonImmutable::instance($planned)->setTimezone($schedule['timezone']);
        if (!self::operatingDate($local->startOfDay(), $schedule)->equalTo($local->startOfDay())) {
            return false;
        }

        if ($end === null) {
            return $local->format('H:i:s') === $schedule['departure_time'] . ':00';
        }
        foreach ($schedule['pickup_windows'] ?? [] as $window) {
            if ($local->format('H:i:s') === $window['start'] . ':00'
                && $local->setTimeFromTimeString($window['end'])->utc()->toISOString() === $end) {
                return true;
            }
        }

        return false;
    }

    public static function pickupDates(CarbonInterface $earliest, array $schedule, ?string $selected = null): array
    {
        $day = self::pickupAt($earliest, $schedule)->setTimezone($schedule['timezone'])->startOfDay();
        $dates = [];
        for ($index = 0; $index < 7; $index++) {
            $day = self::operatingDate($day, $schedule);
            $dates[] = $day->toDateString();
            $day = $day->addDay();
        }
        if ($selected && !in_array($selected, $dates, true)) {
            self::pickupSlots($earliest, $schedule, $selected);
            $dates[] = $selected;
            sort($dates);
        }
        return $dates;
    }

    private static function operatingDate(CarbonImmutable $date, array $schedule): CarbonImmutable
    {
        // Every rejected operating date consumes one entry from the finite closure list.
        for ($attempt = 0; $attempt <= count($schedule['closed_dates']); $attempt++) {
            while (!in_array($date->dayOfWeekIso, $schedule['operating_days'], true)) {
                $date = $date->addDay();
            }
            if (!in_array($date->toDateString(), $schedule['closed_dates'], true)) {
                return $date;
            }
            $date = $date->addDay();
        }

        throw new \LogicException('Unable to find an operating date in the collection calendar.');
    }
}

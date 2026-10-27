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
        ];
    }

    public static function validate(array $input): array
    {
        $validated = Validator::make(['schedule' => $input], [
            'schedule' => ['required', 'array:timezone,operating_days,cutoff_time,departure_time,closed_dates'],
            'schedule.timezone' => ['required', 'timezone'],
            'schedule.operating_days' => ['required', 'array', 'min:1', 'max:7'],
            'schedule.operating_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'schedule.cutoff_time' => ['required', 'date_format:H:i'],
            'schedule.departure_time' => ['required', 'date_format:H:i', 'after:schedule.cutoff_time'],
            'schedule.closed_dates' => ['present', 'array', 'max:366'],
            'schedule.closed_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ])->validate()['schedule'];
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

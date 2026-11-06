<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderManager;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ActiveVisitTracking
{
    private static function validPoint(?Point $point): bool
    {
        return $point && is_finite($point->getLat()) && is_finite($point->getLng())
            && abs($point->getLat()) <= 90 && abs($point->getLng()) <= 180 && ($point->getLat() != 0 || $point->getLng() != 0);
    }

    public static function read(Driver $driver): ?array
    {
        $visit = DriverNavigation::active($driver);
        if (!$visit) return null;
        $order = Order::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['order_uuid'])->firstOrFail();
        $positionAt = data_get($driver->meta, 'navigator_position_at');
        $time = is_string($positionAt) && strtotime($positionAt) !== false ? Carbon::parse($positionAt) : null;
        $point = $driver->location;
        $fresh = $driver->online && $time && $time->between(now()->subSeconds(120), now()->addSeconds(30)) && self::validPoint($point);
        $options = TrackingOptions::fromArray(['route_cache_ttl_seconds' => 20], $driver->company_uuid);
        if ($visit['kind'] === 'pickup') {
            $round = CollectionRound::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['round_uuid'])->firstOrFail();
            $stop = collect($round->stops)->firstWhere('uuid', $visit['stop_uuid']);
            $location = $stop['location'] ?? data_get($stop, 'contact.location');
            $place = new Place();
            $contact = $stop['contact'] ?? [];
            unset($contact['location']);
            $place->forceFill($contact);
            if (isset($location['latitude'], $location['longitude']) && is_numeric($location['latitude']) && is_numeric($location['longitude'])
                && is_finite((float) $location['latitude']) && is_finite((float) $location['longitude'])
                && abs((float) $location['latitude']) <= 90 && abs((float) $location['longitude']) <= 180
                && ((float) $location['latitude'] != 0 || (float) $location['longitude'] != 0)) {
                $place->location = new Point((float) $location['latitude'], (float) $location['longitude']);
            }
            $destination = new TrackingStop($stop['uuid'], null, 'pickup', 'pending', $place, sequence: 1);
        } else {
            $context = app(TrackingContextBuilder::class)->build($order, $options);
            $destination = $context->activeStop;
        }
        $destinationPoint = $destination?->point();
        $validDestination = self::validPoint($destinationPoint);
        $result = [
            'visit' => $visit, 'order_id' => $order->public_id ?? $order->uuid,
            'destination' => $destination ? [
                'name' => $destination->place?->name, 'street1' => $destination->place?->street1,
                'city' => $destination->place?->city, 'phone' => $destination->place?->phone,
                'location' => $validDestination ? ['latitude' => $destinationPoint->getLat(), 'longitude' => $destinationPoint->getLng()] : null,
            ] : null,
            'driver' => ['name' => $driver->name, 'phone' => $driver->phone, 'online' => (bool) $driver->online,
                'position_at' => $positionAt, 'location' => $fresh ? ['latitude' => $point->getLat(), 'longitude' => $point->getLng()] : null],
            'eta_seconds' => null, 'arrival_at' => null, 'distance_m' => null,
            'geometry' => null, 'traffic' => false, 'provider' => null,
            'unavailable_reason' => !$driver->online ? 'driver_offline' : ($fresh ? 'missing_destination' : 'stale_location'),
            'observed_at' => now()->toISOString(),
        ];
        if (!$fresh || !$validDestination) return $result;
        $context = new TrackingContext($order, null, $driver, $point, $point, collect([$destination]), collect(), collect([$destination]), $destination, null, 0);
        // Bound provider calls even when the source GPS updates more frequently than the UI.
        $key = 'navigator_visit_eta:' . hash('sha256', json_encode([$driver->company_uuid, $driver->uuid, $visit, $destination->toArray(), (int) floor(now()->timestamp / 20)]));
        $route = Cache::remember($key, 20, fn () => app(TrackingProviderManager::class)->track($context, $options));
        $road = !in_array($route->provider, ['none', 'calculated']) && ($route->polyline || count($route->coordinates) > 1);
        $seconds = $route->durationInTrafficSeconds ?? $route->durationSeconds;
        if (!$road || $seconds === null || !is_finite($seconds) || $seconds < 0) {
            $result['unavailable_reason'] = 'routing_unavailable';
            return $result;
        }
        $result['eta_seconds'] = (int) ceil($seconds);
        $result['arrival_at'] = now()->addSeconds($result['eta_seconds'])->toISOString();
        $result['distance_m'] = $route->distanceMeters;
        $result['geometry'] = $route->polyline;
        $result['coordinates'] = $route->polyline ? [] : $route->coordinates;
        $result['traffic'] = $route->durationInTrafficSeconds !== null;
        $result['provider'] = $route->provider;
        $result['unavailable_reason'] = null;
        return $result;
    }

    public static function forOrder(Order $order, string $kind): ?array
    {
        $driver = $kind === 'delivery'
            ? Driver::where('company_uuid', $order->company_uuid)->where('uuid', $order->driver_assigned_uuid)->first()
            : null;
        if (!$driver) return null;
        $visit = DriverNavigation::active($driver);
        return $visit && $visit['kind'] === $kind && $visit['order_uuid'] === $order->uuid ? self::read($driver) : null;
    }
}

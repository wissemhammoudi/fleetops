<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Models\Model;
use Fleetbase\Support\Http;
use Illuminate\Support\Collection;

class Payload extends FleetbaseResource
{
    use ResolvesOrderServiceStops;

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $withRouteETA = $request->has('with_route_eta');

        return [
            'id'                           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                    => $this->when(Http::isInternalRequest(), $this->public_id),
            'current_waypoint_uuid'        => $this->when(Http::isInternalRequest(), $this->current_waypoint_uuid),
            'pickup_uuid'                  => $this->when(Http::isInternalRequest(), $this->pickup_uuid),
            'pickup_tracking_number_uuid'  => $this->when(Http::isInternalRequest(), $this->pickup_tracking_number_uuid),
            'dropoff_uuid'                 => $this->when(Http::isInternalRequest(), $this->dropoff_uuid),
            'dropoff_tracking_number_uuid' => $this->when(Http::isInternalRequest(), $this->dropoff_tracking_number_uuid),
            'return_uuid'                  => $this->when(Http::isInternalRequest(), $this->return_uuid),
            'return_tracking_number_uuid'  => $this->when(Http::isInternalRequest(), $this->return_tracking_number_uuid),
            'current_waypoint'             => $this->when(Http::isPublicRequest() && $this->currentWaypoint, data_get($this, 'currentWaypoint.public_id')),
            'pickup'                       => $this->getPlace($this->pickup, false, 'pickup'),
            'dropoff'                      => $this->getPlace($this->dropoff, false, 'dropoff'),
            'return'                       => new Place($this->return),
            'waypoints'                    => Waypoint::collection($this->getWaypoints($withRouteETA)),
            'service_stops'                => $this->when(Http::isInternalRequest(), fn () => $this->getServiceStops()),
            'entities'                     => Entity::collection($this->entities),
            'cod_amount'                   => $this->cod_amount ?? null,
            'cod_currency'                 => $this->cod_currency ?? null,
            'cod_payment_method'           => $this->cod_payment_method ?? null,
            'payment_method'               => $this->payment_method ?? null,
            'meta'                         => data_get($this, 'meta', Utils::createObject()),
            'updated_at'                   => $this->updated_at,
            'created_at'                   => $this->created_at,
        ];
    }

    private function getPlace(?Model $place, bool $withRouteETA = false, ?string $stopKey = null): Place
    {
        if ($place) {
            $place = clone $place;
            $place->service_stop_key = $stopKey;
        }
        if ($place && $withRouteETA) {
            $place->eta = $this->tracker()->getWaypointETA($place);
        }

        return new Place($place);
    }

    private function getServiceStops(): array
    {
        return $this->payloadServiceStops($this->resource)->map(fn (array $stop) => [
            'key' => $stop['key'],
            'role' => $stop['type'],
            'place_uuid' => $stop['place']->uuid,
            'tracking_number_uuid' => $stop['tracking_number_uuid'],
            'tracking' => data_get($stop, 'waypoint.tracking'),
            'status' => data_get($stop, 'waypoint.status'),
            'status_code' => data_get($stop, 'waypoint.status_code'),
            'complete' => data_get($stop, 'waypoint.complete', false),
        ])->all();
    }

    private function getWaypoints(bool $withRouteETA = false): Collection
    {
        if ($this->waypointMarkers->isNotEmpty()) {
            return $this->waypointMarkers->sortBy('order')->values()
                ->filter(fn ($marker) => $marker->place !== null)
                ->map(function ($marker) use ($withRouteETA) {
                    $place = clone $marker->place;
                    $place->payload_uuid = $this->uuid;
                    $place->setRelation('serviceWaypoint', $marker);
                    if ($withRouteETA) {
                        $place->eta = $this->tracker()->getWaypointETA($place);
                    }

                    return $place;
                })->values();
        }
        if ($this->waypoints instanceof Collection) {
            return $this->waypoints->map(function ($waypoint) use ($withRouteETA) {
                $waypoint->payload_uuid = $this->uuid;
                if ($withRouteETA) {
                    $waypoint->eta = $this->tracker()->getWaypointETA($waypoint);
                }

                return $waypoint;
            });
        }

        return collect();
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'                 => $this->public_id,
            'pickup'             => new Place($this->pickup),
            'dropoff'            => new Place($this->dropoff),
            'return'             => new Place($this->return),
            'waypoints'          => static::waypoints($this->waypoints),
            'entities'           => Entity::collection($this->entities ?? []),
            'cod_amount'         => $this->cod_amount ?? null,
            'cod_currency'       => $this->cod_currency ?? null,
            'cod_payment_method' => $this->cod_payment_method ?? null,
            'meta'               => data_get($this, 'meta', Utils::createObject()),
            'updated_at'         => $this->updated_at,
            'created_at'         => $this->created_at,
        ];
    }
}

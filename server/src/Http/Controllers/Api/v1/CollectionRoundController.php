<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Support\CollectionDelivery;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Fleetbase\FleetOps\Support\DeliveryOptions;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CollectionRoundController extends Controller
{
    private function driver(): Driver
    {
        abort_unless(session('company') && session('user'), 403, 'Driver authentication is required.');

        return Driver::where('company_uuid', session('company'))->where('user_uuid', session('user'))->firstOrFail();
    }

    public function index()
    {
        $driver = $this->driver();

        return response()->json(['rounds' => CollectionRound::where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)
            ->whereIn('status', ['released', 'in_progress', 'awaiting_depot'])->orderBy('scheduled_at')->limit(100)->get(),
            'catalogue' => DeliveryOptions::catalogue()]);
    }

    public function update(Request $request, string $id)
    {
        $driver = $this->driver();
        $input = $request->validate([
            'action' => ['required', 'in:start,result'],
            'revision' => ['required', 'integer', 'min:1'],
            'stop_uuid' => ['required_if:action,result', 'string', 'max:150'],
            'request_id' => ['required_if:action,result', 'string', 'min:8', 'max:100'],
            'outcome' => ['required_if:action,result', 'in:collected,not_ready,absent,packaging_refused'],
            'reason' => ['required_if:outcome,not_ready,absent,packaging_refused', 'nullable', 'string', 'max:1000'],
            'checks' => ['sometimes', 'array'],
            'checks.*' => ['boolean'],
        ]);

        return response()->json(['round' => CollectionRounds::drive((string) $driver->company_uuid, $driver->uuid, $id, $input)]);
    }

    public function deliveries()
    {
        $driver = $this->driver();

        return response()->json(['deliveries' => CollectionDeliveryLeg::where('company_uuid', $driver->company_uuid)
            ->where('driver_uuid', $driver->uuid)->whereIn('status', ['staging', 'accepted', 'held'])
            ->orderBy('created_at')->limit(100)->get()->map(function ($leg) {
                $data = $leg->toArray();
                unset($data['manifest']['requests']);

                return $data;
            }), 'catalogue' => DeliveryOptions::catalogue()]);
    }

    public function acceptDelivery(Request $request, string $id)
    {
        $driver = $this->driver();

        return response()->json(['delivery' => CollectionDelivery::change((string) $driver->company_uuid, $id, $request->all(), $driver->uuid)]);
    }
}

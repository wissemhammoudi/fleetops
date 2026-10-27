<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Support\CollectionDelivery;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Fleetbase\FleetOps\Support\CollectionDepot;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

class CollectionRoundController extends Controller
{
    private function authorizeConsole(bool $write): string
    {
        abort_unless(session('company') && session('user') && !session('is_customer') && !session('is_driver'), 403, 'Console access is required.');
        abort_unless(session('is_admin') || Auth::can('fleet-ops ' . ($write ? 'update' : 'view') . ' scheduling-settings'), 403, 'Scheduling permission is required.');

        return (string) session('company');
    }

    public function index()
    {
        $company = $this->authorizeConsole(false);

        return response()->json(['rounds' => CollectionRound::where('company_uuid', $company)->where('status', '!=', 'closed')->whereJsonLength('stops', '>', 0)
            ->orderBy('scheduled_at')->limit(100)->get()->map(function ($round) {
                $data = $round->toArray();
                unset($data['custody']['requests']);
                $data['depot_orders'] = [];
                foreach (data_get($round->custody, 'receipts', []) as $uuid => $receipt) {
                    if (!collect($round->stops)->where('order_uuid', $uuid)->contains('status', 'collected')) {
                        continue;
                    }
                    $order = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $round->company_uuid)->where('uuid', $uuid)->first();
                    $leg = CollectionDeliveryLeg::where('company_uuid', $round->company_uuid)->where('order_uuid', $uuid)->first();
                    $delivery = $leg?->toArray();
                    unset($delivery['manifest']['requests']);
                    $data['depot_orders'][] = ['order_uuid' => $uuid, 'eligible' => $order && CollectionDepot::eligible($order), 'delivery' => $delivery] + $receipt;
                }

                return $data;
            }),
            'drivers' => Driver::where('company_uuid', $company)->get()->map(fn ($driver) => ['uuid' => $driver->uuid, 'name' => $driver->name]),
            'depots' => DB::connection(config('fleetbase.connection.db', 'mysql'))->table('places')->where('company_uuid', $company)
                ->whereNull('deleted_at')->orderBy('name')->get(['uuid', 'name', 'street1'])]);
    }

    public function plan()
    {
        return response()->json(CollectionRounds::plan($this->authorizeConsole(true)));
    }

    public function depot(Request $request, string $id)
    {
        return response()->json(['round' => CollectionDepot::change($this->authorizeConsole(true), $id, $request->all())]);
    }

    public function delivery(Request $request, string $id)
    {
        return response()->json(['delivery' => CollectionDelivery::change($this->authorizeConsole(true), $id, $request->all())]);
    }

    public function labels(string $id)
    {
        $round = CollectionRound::where('company_uuid', $this->authorizeConsole(false))->where('uuid', $id)->firstOrFail();
        abort_unless($round->custody && $round->status !== 'draft', 409, 'Release the round to create physical parcel labels.');

        return response()->json(['labels' => array_map(fn ($unit) => \Illuminate\Support\Arr::only($unit, ['barcode', 'order_id', 'name', 'number', 'quantity'])
            + ['image' => DNS2D::getBarcodePNG($unit['barcode'], 'QRCODE')], $round->custody['units'])]);
    }

    public function update(Request $request, string $id)
    {
        $company = $this->authorizeConsole(true);
        $input = $request->validate([
            'action' => ['required', 'in:assign,release,cancel'],
            'revision' => ['required', 'integer', 'min:1'],
            'driver_uuid' => ['required_if:action,assign', 'uuid'],
            'depot_uuid' => ['required_if:action,assign', 'uuid'],
        ]);

        return response()->json(['round' => CollectionRounds::operate($company, $id, $input)]);
    }
}

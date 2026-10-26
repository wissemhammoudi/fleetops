<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Support\DeliveryOptions;
use Fleetbase\Http\Controllers\Controller;

class DeliveryOptionsController extends Controller
{
    public function index()
    {
        return response()->json(DeliveryOptions::catalogue());
    }
}

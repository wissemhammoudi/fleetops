<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Illuminate\Console\Command;

class PlanCollectionRounds extends Command
{
    protected $signature = 'fleetops:plan-collection-rounds';
    protected $description = 'Build unreleased daily collection rounds for customer-confirmed standard Transport orders.';

    public function handle(): int
    {
        $companies = Order::where('meta->collection_workflow->state', 'ready_for_pickup')->distinct()->pluck('company_uuid');
        foreach ($companies as $company) {
            $result = CollectionRounds::plan($company);
            $this->info($company . ': ' . count($result['rounds']) . ' rounds updated.');
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning['order_uuid'] . ': ' . $warning['message']);
            }
        }

        return self::SUCCESS;
    }
}

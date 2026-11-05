<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Illuminate\Console\Command;

class ReleaseAutomaticPickupDrafts extends Command
{
    protected $signature = 'fleetops:release-auto-pickup-drafts {--company= : Restrict to one company}';
    protected $description = 'Return orders held in unassigned, unoptimized draft pickup rounds to "ready for pickup" so the dispatcher can plan them.';

    public function handle(): int
    {
        $companies = $this->option('company')
            ? [$this->option('company')]
            : CollectionRound::where('status', 'draft')->whereNull('driver_uuid')->whereNull('routing')->distinct()->pluck('company_uuid')->all();
        foreach ($companies as $company) {
            $released = CollectionRounds::releaseAutomaticDrafts($company);
            $this->info($company . ': ' . count($released) . ' orders returned to ready for pickup.');
        }

        return self::SUCCESS;
    }
}

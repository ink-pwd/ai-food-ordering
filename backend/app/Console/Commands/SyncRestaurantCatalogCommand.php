<?php

namespace App\Console\Commands;

use App\Jobs\SyncDotsTopology;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:sync')]
#[Description('Queue Dots topology and catalog synchronization.')]
class SyncRestaurantCatalogCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        SyncDotsTopology::dispatch();

        $this->line('Catalog topology synchronization queued.');

        return Command::SUCCESS;
    }
}

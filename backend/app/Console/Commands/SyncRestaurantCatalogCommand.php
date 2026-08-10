<?php

namespace App\Console\Commands;

use App\Jobs\SyncRestaurantCatalog;
use App\Models\Restaurant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:sync
    {restaurant : Stable local restaurant slug}')]
#[Description('Queue catalog synchronization for a local restaurant.')]
class SyncRestaurantCatalogCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $slug = (string) $this->argument('restaurant');

        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->first();

        if ($restaurant === null) {
            $this->error("Restaurant not found: slug={$slug}");

            return Command::FAILURE;
        }

        if (! $restaurant->is_active) {
            $this->error("Restaurant is inactive: id={$restaurant->id} slug={$restaurant->slug}");

            return Command::FAILURE;
        }

        SyncRestaurantCatalog::dispatch($restaurant->id);

        $this->line("Catalog synchronization queued: id={$restaurant->id} slug={$restaurant->slug}");

        return Command::SUCCESS;
    }
}

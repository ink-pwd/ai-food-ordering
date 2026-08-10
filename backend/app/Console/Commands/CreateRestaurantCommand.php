<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('restaurant:create
    {external_company_id : Dots company UUID}
    {name : Restaurant name}
    {slug : Stable restaurant slug}
    {--currency=UAH : ISO 4217 currency code}
    {--locale=uk-UA : Restaurant locale}
    {--timezone=Europe/Kyiv : Restaurant timezone}
    {--inactive : Create the restaurant as inactive}')]
#[Description('Create a local restaurant.')]
class CreateRestaurantCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $data = [
            'external_company_id' => $this->argument('external_company_id'),
            'name' => $this->argument('name'),
            'slug' => $this->argument('slug'),
            'currency' => $this->option('currency'),
            'locale' => $this->option('locale'),
            'timezone' => $this->option('timezone'),
        ];

        $validator = Validator::make($data, [
            'external_company_id' => ['required', 'uuid', Rule::unique('restaurants', 'external_company_id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('restaurants', 'slug')],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'locale' => ['required', 'string', 'max:35'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return Command::FAILURE;
        }

        $restaurant = Restaurant::query()->create([
            'external_company_id' => $data['external_company_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'currency' => strtoupper($data['currency']),
            'locale' => $data['locale'],
            'timezone' => $data['timezone'],
            'is_active' => ! $this->option('inactive'),
        ]);

        $this->line("Restaurant created: id={$restaurant->id} slug={$restaurant->slug} external_company_id={$restaurant->external_company_id}");

        return Command::SUCCESS;
    }
}

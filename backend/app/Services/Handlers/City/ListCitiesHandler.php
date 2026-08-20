<?php

namespace App\Services\Handlers\City;

use App\Models\City;
use App\Services\Repositories\CityRepository;
use Illuminate\Database\Eloquent\Collection;

readonly class ListCitiesHandler
{
    public function __construct(
        private CityRepository $cities,
    ) {
    }

    /**
     * @return Collection<int, City>
     */
    public function handle(): Collection
    {
        return $this->cities->active();
    }
}

<?php

namespace App\DTO;

final readonly class CustomerContactData
{
    public function __construct(
        public string $name,
        public string $phone,
    ) {
    }
}

<?php

namespace App\DTO\OrderingBackend;

final readonly class DeliveryAddressData
{
    public function __construct(
        public int $type,
        public string $street,
        public string $house,
        public ?string $flat = null,
        public ?string $stage = null,
        public ?string $note = null,
        public ?string $title = null,
    ) {
    }

    /**
     * @return array{type: int, street: string, house: string, flat?: string, stage?: string, note?: string, title?: string}
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'street' => $this->street,
            'house' => $this->house,
        ];

        foreach ([
            'flat' => $this->flat,
            'stage' => $this->stage,
            'note' => $this->note,
            'title' => $this->title,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}

<?php

namespace App\DTO;

final readonly class ProductSynchronizationResultData
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $relationsAttached,
        public int $relationsDetached,
    ) {
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     relations_attached: int,
     *     relations_detached: int
     * }
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'relations_attached' => $this->relationsAttached,
            'relations_detached' => $this->relationsDetached,
        ];
    }
}

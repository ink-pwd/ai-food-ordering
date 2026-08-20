<?php

use App\DTO\ProductSynchronizationResultData;

test('product synchronization result preserves the catalog log shape', function (): void {
    $result = new ProductSynchronizationResultData(
        created: 2,
        updated: 3,
        unchanged: 4,
        relationsAttached: 5,
        relationsDetached: 6,
    );

    expect($result->toArray())->toBe([
        'created' => 2,
        'updated' => 3,
        'unchanged' => 4,
        'relations_attached' => 5,
        'relations_detached' => 6,
    ]);
});

<?php

use App\Enums\PaymentType;

test('only online payment requires online payment resolution', function (): void {
    expect(PaymentType::Online->requiresOnlinePayment())->toBeTrue()
        ->and(PaymentType::Cash->requiresOnlinePayment())->toBeFalse()
        ->and(PaymentType::Terminal->requiresOnlinePayment())->toBeFalse();
});

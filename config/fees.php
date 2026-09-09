<?php

return [
    'buyer_fee_percent' => (float) env('TRADA_BUYER_FEE_PERCENT', 1),
    'seller_fee_percent' => (float) env('TRADA_SELLER_FEE_PERCENT', 2),
    'payout_fee_percent' => (float) env('PAYOUT_FEE_PERCENT', 5),
    'payout_minimum_amount' => (float) env('PAYOUT_MINIMUM_AMOUNT', 10000),
    'payout_maximum_amount' => (float) env('PAYOUT_MAXIMUM_AMOUNT', 5000000),
];

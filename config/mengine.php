<?php

return [
    'mengine' => [
        // Transaction types, not subject to change.
        'transaction' => [
            'buy',
            'sell',
        ],

        // Default precision, can be changed.
        'accuracy' => 8,
        // If the precision for the trading pair is set, use it; otherwise, take the default accuracy.
        'abc2usdt_accuracy' => 6, // Example of a trading pair
        'test2usdt_accuracy' => 7, // Example of a trading pair

        // If strict mode is set to true, it will validate that the decimal places of the transaction volume or price must be less than the configured length, otherwise the order will fail.
        // If strict mode is set to false, the data will be truncated to the configured decimal places length.
        'strict_mode' => false,
    ],
];

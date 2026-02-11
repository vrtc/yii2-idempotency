<?php

return [
    'controllerMap' => [
        'idempotency' => [
            'class' => 'Idempotency\console\IdempotencyController',
        ],
    ],
    'components' => [
        'idempotency' => [
            'class' => 'Idempotency\IdempotencyComponent',
            // Конфигурация по умолчанию для консоли
            'defaultStorage' => [
                'class' => 'Idempotency\storage\DatabaseStorage',
                'db' => 'db',
            ],
            'defaultLock' => [
                'class' => 'Idempotency\lock\FileLock',
                'lockDir' => '@runtime/locks',
            ],
            'enableAutoCleanup' => true,
            'cleanupInterval' => 86400, // 24 часа
            'cleanupBatchSize' => 1000,
        ],
    ],
    'bootstrap' => ['idempotency'],
];
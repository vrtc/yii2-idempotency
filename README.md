# Yii2 Idempotency Behavior

 

Универсальный, высокопроизводительный behavior для реализации идемпотентности в Yii2 REST API. Позволяет безопасно обрабатывать повторяющиеся запросы, предотвращает двойные списания, защищает от race condition и оптимизирован для работы под высокой нагрузкой.

## ✨ Особенности

- **🚀 Высокая производительность**: Redis с Lua-скриптами, двухуровневый кеш, batch операции
- **🔒 Безопасность**: Защита от race condition, deadlock detection, атомарные операции
- **🛡️ Защита от оверсела**: Встроенная система контроля инвентаря
- **🔧 Гибкость**: Поддержка Redis, Database, Cache хранилищ
- **📊 Мониторинг**: Консольные команды, метрики, логирование
- **📦 Готовность к production**: Retry логика, обработка ошибок, автоочистка

## 📦 Установка

```bash
composer require vrtc/yii2-idempotency

```

## 🚀 Быстрый старт

### 1. Настройка компонента

```php

// config/web.php
return [
    'components' => [
        'idempotency' => [
            'class' => 'Idempotency\IdempotencyComponent',
            'defaultStorage' => [
                'class' => 'Idempotency\storage\RedisStorage',
                'redis' => 'redis',
                'prefix' => 'idemp:',
                'compress' => true,
            ],
            'defaultLock' => [
                'class' => 'Idempotency\lock\RedisLock',
                'redis' => 'redis',
            ],
            'enableAutoCleanup' => true,
            'cleanupInterval' => 3600,
        ],
    ],
];
```

### 2. Использование в контроллере

```php
use Idempotency\IdempotencyBehavior;

class PaymentController extends \yii\web\Controller
{
    public function behaviors()
    {
        return [
            'idempotency' => [
                'class' => IdempotencyBehavior::class,
                'mode' => IdempotencyBehavior::MODE_STRICT,
                'headerName' => 'X-Idempotency-Key',
                'ttl' => 3600,
                'overSellProtection' => true,
                'only' => ['create', 'update'],
                'useFastCache' => true,
                'fastCacheTtl' => 5,
            ],
        ];
    }
    
    public function actionCreate()
    {
        // Ваш код обработки платежа...
        // При повторном запросе с тем же X-Idempotency-Key
        // вернется сохраненный результат
    }
}
```

### 3. Клиентская сторона

```php
// Генерация ключа идемпотентности
$idempotencyKey = Yii::$app->idempotency->generateKey();
// или
$idempotencyKey = \Ramsey\Uuid\Uuid::uuid4()->toString();

// Отправка запроса
$client = new \yii\httpclient\Client();
$response = $client->createRequest()
    ->setMethod('POST')
    ->setUrl('https://api.example.com/payment/create')
    ->setHeaders([
        'X-Idempotency-Key' => $idempotencyKey,
        'Content-Type' => 'application/json',
    ])
    ->setData([
        'amount' => 100.00,
        'currency' => 'USD',
    ])
    ->send();

```

## 📁 Структура проекта

```
yii2-idempotency/
├── src/
│   ├── IdempotencyBehavior.php          # Основной behavior
│   ├── IdempotencyComponent.php         # Компонент для конфигурации
│   ├── Bootstrap.php                    # Автозагрузка
│   ├── exceptions/                      # Исключения
│   │   ├── IdempotencyException.php
│   │   ├── ConcurrentRequestException.php
│   │   ├── InvalidKeyException.php
│   │   └── OverSellException.php
│   ├── storage/                         # Хранилища
│   │   ├── StorageInterface.php
│   │   ├── RedisStorage.php
│   │   ├── CacheStorage.php
│   │   └── DatabaseStorage.php
│   ├── lock/                            # Блокировки
│   │   ├── LockInterface.php
│   │   ├── RedisLock.php
│   │   └── FileLock.php
│   ├── validator/                       # Валидация
│   │   └── KeyValidator.php
│   └── console/                         # Консольные команды
│       └── IdempotencyController.php
├── migrations/                          # Миграции БД
│   └── m240101_000000_create_idempotency_table.php
├── config/                              # Конфигурации
│   └── console.php
├── tests/                               # Тесты
├── composer.json
├── README.md
├── LICENSE
└── CHANGELOG.md
```

## 🔧 Конфигурация
### Режимы работы

```php
// STRICT (по умолчанию) - ключ обязателен
'mode' => IdempotencyBehavior::MODE_STRICT,

// OPTIONAL - ключ опционален
'mode' => IdempotencyBehavior::MODE_OPTIONAL,

// LAX - только проверка, без сохранения результатов
'mode' => IdempotencyBehavior::MODE_LAX,
```

## Хранилища

### Redis (рекомендуется для production)

```php

'storageConfig' => [
    'class' => 'Idempotency\storage\RedisStorage',
    'redis' => 'redis', // компонент Redis
    'prefix' => 'idemp:prod:', // префикс ключей
    'compress' => true, // сжатие данных
    'compressionLevel' => 6,
],

```

### Database (для распределенных систем)

```php
'storageConfig' => [
    'class' => 'Idempotency\storage\DatabaseStorage',
    'db' => 'db', // компонент БД
    'tableName' => '{{%idempotency_keys}}',
    'maxDeadlockRetries' => 3,
],
```

### Cache (простая настройка)

```php

'storageConfig' => [
    'class' => 'Idempotency\storage\CacheStorage',
    'cache' => 'cache', // компонент кеша
    'prefix' => 'idemp:',
    'compress' => true,
],

```

### Блокировки

### Redis Lock (рекомендуется)
```php

'lockConfig' => [
    'class' => 'Idempotency\lock\RedisLock',
    'redis' => 'redis',
    'prefix' => 'lock:idemp:',
],
```
### File Lock (для тестов)
```php

'lockConfig' => [
    'class' => 'Idempotency\lock\FileLock',
    'lockDir' => '@runtime/locks',
    'useFlock' => true,
],
```

## 🎯 Примеры использования
### 1. Финансовые операции (платежи)
```php

class PaymentController extends Controller
{
    public function behaviors()
    {
        return [
            'idempotency' => [
                'class' => IdempotencyBehavior::class,
                'mode' => IdempotencyBehavior::MODE_STRICT,
                'headerName' => 'X-Idempotency-Key',
                'ttl' => 86400, // 24 часа для платежей
                'overSellProtection' => true,
                'only' => ['create', 'refund'],
                'useFastCache' => true,
                'maxLockAttempts' => 3,
                'lockRetryDelay' => 100, // 100ms
            ],
        ];
    }
    
    public function actionCreate()
    {
        $transaction = Yii::$app->db->beginTransaction();
        
        try {
            $payment = new Payment(Yii::$app->request->post());
            
            if (!$payment->save()) {
                throw new \Exception('Payment validation failed');
            }
            
            // Обработка платежа
            $this->processPayment($payment);
            
            $transaction->commit();
            
            return $this->asJson([
                'success' => true,
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);
            
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
```
## 2. Заказы с защитой от оверсела
```php

class OrderController extends Controller
{
    public function behaviors()
    {
        return [
            'idempotency' => [
                'class' => IdempotencyBehavior::class,
                'mode' => IdempotencyBehavior::MODE_STRICT,
                'overSellProtection' => true,
                'only' => ['create'],
            ],
        ];
    }
    
    public function actionCreate()
    {
        $request = Yii::$app->request;
        $productId = $request->post('product_id');
        $quantity = $request->post('quantity', 1);
        
        // Защита от оверсела работает автоматически
        // через Redis атомарные операции
        
        $order = new Order([
            'product_id' => $productId,
            'quantity' => $quantity,
            'status' => 'pending',
        ]);
        
        if ($order->save()) {
            return $this->asJson([
                'success' => true,
                'order_id' => $order->id,
            ]);
        }
        
        return $this->asJson([
            'success' => false,
            'errors' => $order->errors,
        ]);
    }
}
```
### 3. Массовые операции
```php

class BatchController extends Controller
{
    public function behaviors()
    {
        return [
            'idempotency' => [
                'class' => IdempotencyBehavior::class,
                'mode' => IdempotencyBehavior::MODE_STRICT,
                'only' => ['process'],
                // Используем batch операции для производительности
            ],
        ];
    }
    
    public function actionProcess()
    {
        $items = Yii::$app->request->post('items', []);
        $results = [];
        
        foreach ($items as $item) {
            // Каждый элемент обрабатывается с идемпотентностью
            $result = $this->processItem($item);
            $results[] = $result;
        }
        
        return $this->asJson([
            'success' => true,
            'results' => $results,
        ]);
    }
}
```

## ⚡ Производительность под нагрузкой
### Горячий кеш
```php

'idempotency' => [
    'class' => IdempotencyBehavior::class,
    'useFastCache' => true, // Включить быстрый кеш
    'fastCacheTtl' => 5, // 5 секунд для частых проверок
],
```
### Настройка Redis для высокой нагрузки
```php

'components' => [
    'redis' => [
        'class' => 'yii\redis\Connection',
        'hostname' => 'localhost',
        'port' => 6379,
        'database' => 0,
        'connectionTimeout' => 1, // 1 секунда
        'readTimeout' => 1,
        'retries' => 2, // Повторные попытки
    ],
],
```
### Оптимальные настройки
```php

'idempotency' => [
    'class' => IdempotencyBehavior::class,
    'ttl' => 3600, // 1 час для большинства операций
    'lockTtl' => 10, // 10 секунд для блокировок
    'maxLockAttempts' => 3, // 3 попытки получить блокировку
    'lockRetryDelay' => 50, // 50ms между попытками
    'useFastCache' => true,
    'fastCacheTtl' => 2, // 2 секунды для очень горячих ключей
],
```

## 🛠️ Консольные команды
```bash

# Очистка просроченных ключей
php yii idempotency/cleanup [batchSize=1000]

# Генерация тестового ключа
php yii idempotency/generate-key

# Тестирование хранилища
php yii idempotency/test-storage

# Показать статистику
php yii idempotency/stats

# Массовая очистка (для больших объемов)
php yii idempotency/cleanup 5000
```
## 📊 Миграции базы данных
```bash

# Применить миграцию для DatabaseStorage
php yii migrate --migrationPath=@vendor/vrtc/yii2-idempotency/migrations
```


## 🔍 Отладка и мониторинг
### Включение логов
```php

// config/web.php
'components' => [
    'log' => [
        'targets' => [
            [
                'class' => 'yii\log\FileTarget',
                'levels' => ['error', 'warning', 'info'],
                'categories' => ['idempotency'],
                'logFile' => '@runtime/logs/idempotency.log',
            ],
        ],
    ],
],
```
### Метрики производительности
```php

// В любом месте приложения
$metrics = Yii::$app->idempotency->getMetrics();
/*
Возвращает:
[
    'last_cleanup' => '2024-01-01 12:00:00',
    'storage_class' => 'Idempotency\storage\RedisStorage',
    'locker_class' => 'Idempotency\lock\RedisLock',
]
*/
```
## 🚨 Обработка ошибок
## Кастомные исключения
```php

use Idempotency\exceptions\{
    ConcurrentRequestException,
    InvalidKeyException,
    OverSellException
};

try {
    // Ваш код...
} catch (ConcurrentRequestException $e) {
    // 429 - Too Many Requests
    return $this->asJson([
        'error' => 'Concurrent request detected',
        'retry_after' => 5,
    ]);
} catch (OverSellException $e) {
    // 409 - Conflict
    return $this->asJson([
        'error' => 'Insufficient stock',
        'code' => 'OVERSELL',
    ]);
} catch (InvalidKeyException $e) {
    // 400 - Bad Request
    return $this->asJson([
        'error' => 'Invalid idempotency key',
        'code' => 'INVALID_KEY',
    ]);
}
```
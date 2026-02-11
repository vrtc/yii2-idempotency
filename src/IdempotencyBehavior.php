<?php

namespace Idempotency;

use Yii;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\web\Controller;
use yii\web\Request;
use yii\web\Response;
use Idempotency\storage\StorageInterface;
use Idempotency\lock\LockInterface;
use Idempotency\exceptions\ConcurrentRequestException;
use Idempotency\exceptions\OverSellException;
use Idempotency\exceptions\InvalidKeyException;
use Idempotency\validator\InputFilter;

/**
 * High-performance Idempotency Behavior для Yii2
 * Поддержка высокой нагрузки, защита от оверсела, горячий кеш
 */
class IdempotencyBehavior extends Behavior
{
    // Константы для режимов работы
    const MODE_STRICT = 'strict';     // Обязательный ключ
    const MODE_OPTIONAL = 'optional'; // Ключ опционален
    const MODE_LAX = 'lax';           // Только проверка, без сохранения
    
    /**
     * @var string Режим работы
     */
    public $mode = self::MODE_STRICT;
    
    /**
     * @var string Название заголовка с ключом идемпотентности
     */
    public $headerName = 'X-Idempotency-Key';
    
    /**
     * @var int Время жизни ключа в секундах
     */
    public $ttl = 3600; // 1 час
    
    /**
     * @var int Время блокировки в секундах
     */
    public $lockTtl = 10;
    
    /**
     * @var int Количество попыток получить блокировку
     */
    public $maxLockAttempts = 3;
    
    /**
     * @var int Задержка между попытками блокировки (миллисекунды)
     */
    public $lockRetryDelay = 100;
    
    /**
     * @var bool Использовать быстрый кеш для проверки существования ключа
     */
    public $useFastCache = true;
    
    /**
     * @var int TTL для быстрого кеша (короткий, чтобы уменьшить нагрузку)
     */
    public $fastCacheTtl = 5;
    
    /**
     * @var bool Включить защиту от оверсела
     */
    public $overSellProtection = false;
    
    /**
     * @var string|null Класс для валидации ключа
     */
    public $validatorClass;
    
    /**
     * @var array Конфигурация хранилища
     */
    public $storageConfig = [
        'class' => 'Idempotency\storage\RedisStorage',
    ];
    
    /**
     * @var array Конфигурация блокировок
     */
    public $lockConfig = [
        'class' => 'Idempotency\lock\RedisLock',
    ];
    
    /**
     * @var StorageInterface
     */
    private $_storage;
    
    /**
     * @var LockInterface
     */
    private $_locker;
    
    /**
     * @var string|null Текущий ключ идемпотентности
     */
    private $_currentKey;
    
    /**
     * @var array Кеш быстрых проверок
     */
    private $_fastCheckCache = [];
    
    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            Controller::EVENT_BEFORE_ACTION => 'beforeAction',
            Controller::EVENT_AFTER_ACTION => 'afterAction',
        ];
    }
    
    /**
     * Обработка перед выполнением действия
     */
    public function beforeAction($event)
    {
        $request = Yii::$app->request;
        
        // Извлекаем ключ идемпотентности
        $idempotencyKey = $this->extractKey($request);
        
        if (!$idempotencyKey) {
            if ($this->mode === self::MODE_STRICT) {
                throw new InvalidKeyException('Idempotency key is required');
            }
            return;
        }
        
        $this->_currentKey = $idempotencyKey;
        
        // 1. Быстрая проверка в памяти (для снижения нагрузки на хранилище)
        if ($this->useFastCache && $this->fastCheck($idempotencyKey)) {
            $this->returnCachedResponse($idempotencyKey);
            $event->isValid = false; // Прерываем выполнение action
            return;
        }
        
        // 2. Получаем блокировку с повторными попытками
        $lockKey = $this->getLockKey($idempotencyKey);
        $lockAcquired = false;
        
        for ($attempt = 1; $attempt <= $this->maxLockAttempts; $attempt++) {
            if ($this->getLocker()->acquire($lockKey, $this->lockTtl)) {
                $lockAcquired = true;
                break;
            }
            
            if ($attempt < $this->maxLockAttempts) {
                usleep($this->lockRetryDelay * 1000);
            }
        }
        
        if (!$lockAcquired) {
            throw new ConcurrentRequestException(
                'Too many concurrent requests with same idempotency key',
                429
            );
        }
        
        try {
            // 3. Проверяем в основном хранилище (под блокировкой)
            $cachedData = $this->getStorage()->get($idempotencyKey);
            
            if ($cachedData) {
                // Добавляем в быстрый кеш
                if ($this->useFastCache) {
                    $this->setFastCheck($idempotencyKey);
                }
                
                $this->returnCachedResponse($idempotencyKey, $cachedData);
                $event->isValid = false;
                return;
            }
            
            // 4. Защита от оверсела (если включена)
            if ($this->overSellProtection) {
                $this->checkOverSellProtection($request);
            }
            
            // Ключ не найден, продолжаем выполнение
            // Результат будет сохранен в afterAction
            
        } finally {
            if ($lockAcquired) {
                $this->getLocker()->release($lockKey);
            }
        }
    }
    
    /**
     * Обработка после выполнения действия
     */
    public function afterAction($event)
    {
        if (!$this->_currentKey || $this->mode === self::MODE_LAX) {
            return;
        }
        
        $response = Yii::$app->response;
        
        // Сохраняем только успешные ответы
        if ($response->isSuccessful && $event->result !== null) {
            $data = $this->prepareResponseData($response, $event->result);
            
            // Сохраняем в основном хранилище
            $saved = $this->getStorage()->set(
                $this->_currentKey,
                $data,
                $this->ttl
            );
            
            // Добавляем в быстрый кеш
            if ($saved && $this->useFastCache) {
                $this->setFastCheck($this->_currentKey);
            }
        }
        
        // Очищаем текущий ключ
        $this->_currentKey = null;
    }
    
    /**
     * Быстрая проверка ключа в памяти
     */
    private function fastCheck(string $key): bool
    {
        // Используем статический кеш для текущего запроса
        if (isset($this->_fastCheckCache[$key])) {
            return true;
        }
        
        // Или проверяем в быстром кеше приложения
        if ($this->fastCacheTtl > 0) {
            $cacheKey = $this->getFastCacheKey($key);
            $exists = Yii::$app->cache->get($cacheKey);
            if ($exists) {
                $this->_fastCheckCache[$key] = true;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Сохраняет ключ в быстром кеше
     */
    private function setFastCheck(string $key): void
    {
        $this->_fastCheckCache[$key] = true;
        
        if ($this->fastCacheTtl > 0) {
            $cacheKey = $this->getFastCacheKey($key);
            Yii::$app->cache->set($cacheKey, 1, $this->fastCacheTtl);
        }
    }
    
    /**
     * Возвращает ключ для быстрого кеша
     */
    private function getFastCacheKey(string $key): string
    {
        return 'idemp_fast:' . md5($key);
    }
    
    /**
     * Защита от оверсела (например, для товаров с ограниченным количеством)
     */
    private function checkOverSellProtection(Request $request): void
    {
        // Пример: проверяем количество товара на складе
        $productId = $request->post('product_id');
        $quantity = $request->post('quantity', 1);
        
        if ($productId && $quantity > 0) {
            // Используем Redis для атомарных операций
            $redis = Yii::$app->redis;
            $stockKey = "product:stock:{$productId}";
            
            // Lua скрипт для атомарной проверки и уменьшения
            $lua = "
                local stock = tonumber(redis.call('GET', KEYS[1]))
                local quantity = tonumber(ARGV[1])
                
                if stock == nil then
                    return -1  -- Товар не найден
                end
                
                if stock < quantity then
                    return 0   -- Недостаточно на складе
                end
                
                redis.call('DECRBY', KEYS[1], quantity)
                return stock - quantity
            ";
            
            $result = $redis->eval($lua, 1, $stockKey, $quantity);
            
            if ($result === 0) {
                throw new OverSellException('Insufficient stock');
            }
            
            if ($result === -1) {
                throw new OverSellException('Product not found');
            }
            
            // Запоминаем, что нужно вернуть stock при отмене
            Yii::$app->on(Controller::EVENT_AFTER_ACTION, function() use ($redis, $stockKey, $quantity) {
                // Если запрос не удался, возвращаем stock
                if (!Yii::$app->response->isSuccessful) {
                    $redis->incrby($stockKey, $quantity);
                }
            });
        }
    }
    
    /**
     * Возвращает сохраненный ответ
     */
    private function returnCachedResponse(string $key, array $cachedData = null): void
    {
        if (!$cachedData) {
            $cachedData = $this->getStorage()->get($key);
        }
        
        if (!$cachedData) {
            return;
        }
        
        $response = Yii::$app->response;
        
        // Восстанавливаем статус
        if (isset($cachedData['status'])) {
            $response->setStatusCode($cachedData['status']);
        }
        
        // Восстанавливаем данные
        if (isset($cachedData['data'])) {
            if ($response->format === Response::FORMAT_JSON) {
                $response->data = $cachedData['data'];
            } else {
                $response->content = $cachedData['data'];
            }
        }
        
        // Добавляем заголовки
        $response->headers->add('X-Idempotent-Response', 'true');
        $response->headers->add('X-Idempotency-Key', $key);
        
        if (isset($cachedData['created_at'])) {
            $response->headers->add('X-Created-At', $cachedData['created_at']);
        }
    }
    
    /**
     * Подготавливает данные ответа для сохранения
     */
    private function prepareResponseData(Response $response, $result): array
    {
        return [
            'status' => $response->statusCode,
            'data' => $result,
            'headers' => $response->headers->toArray(),
            'created_at' => time(),
            'expires_at' => time() + $this->ttl,
        ];
    }
    
    /**
     * Извлекает ключ из запроса
     */
    private function extractKey(Request $request): ?string
    {
        $key = $request->getHeaders()->get($this->headerName);
        
        // Также проверяем в теле запроса для некоторых форматов
        if (!$key && $request->isPost) {
            $key = $request->post($this->headerName);
        }
        
        return $key ? trim($key) : null;
    }
    
    /**
     * Возвращает ключ для блокировки
     */
    private function getLockKey(string $idempotencyKey): string
    {
        return 'lock:idempotency:' . md5($idempotencyKey);
    }
    
    /**
     * Возвращает экземпляр хранилища
     */
    private function getStorage(): StorageInterface
    {
        if ($this->_storage === null) {
            $this->_storage = Yii::createObject($this->storageConfig);
        }
        
        return $this->_storage;
    }
    
    /**
     * Возвращает экземпляр блокировки
     */
    private function getLocker(): LockInterface
    {
        if ($this->_locker === null) {
            $this->_locker = Yii::createObject($this->lockConfig);
        }
        
        return $this->_locker;
    }
}

<?php

namespace Idempotency\lock;

use Yii;
use yii\redis\Connection;
use Idempotency\lock\LockInterface;

class RedisLock implements LockInterface
{
    /**
     * @var Connection|array|string Redis компонент
     */
    public $redis = 'redis';
    
    /**
     * @var string Префикс для ключей блокировок
     */
    public $prefix = 'lock:';
    
    /**
     * @var float Время для проверки блокировки в секундах (дробное)
     */
    public $watchTimeout = 0.1;
    
    /**
     * @var string Lua скрипт для атомарного получения блокировки
     */
    private $lockScript = "
        local key = KEYS[1]
        local value = ARGV[1]
        local ttl = tonumber(ARGV[2])
        
        if redis.call('SETNX', key, value) == 1 then
            redis.call('EXPIRE', key, ttl)
            return 1
        else
            -- Проверяем, не просрочена ли блокировка
            local current = redis.call('GET', key)
            if current == false then
                redis.call('SET', key, value)
                redis.call('EXPIRE', key, ttl)
                return 1
            end
            return 0
        end
    ";
    
    /**
     * @var string Lua скрипт для атомарного освобождения
     */
    private $unlockScript = "
        local key = KEYS[1]
        local value = ARGV[1]
        
        local current = redis.call('GET', key)
        if current == value then
            return redis.call('DEL', key)
        end
        return 0
    ";
    
    public function acquire(string $key, int $ttl): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);
        $value = $this->generateLockValue();
        
        $result = $redis->eval(
            $this->lockScript,
            1,
            $fullKey,
            $value,
            $ttl
        );
        
        if ($result) {
            // Сохраняем значение для проверки при освобождении
            Yii::$app->params['redis_locks'][$fullKey] = $value;
            return true;
        }
        
        return false;
    }
    
    public function release(string $key): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);
        $value = Yii::$app->params['redis_locks'][$fullKey] ?? null;
        
        if (!$value) {
            return false;
        }
        
        $result = $redis->eval(
            $this->unlockScript,
            1,
            $fullKey,
            $value
        );
        
        if ($result) {
            unset(Yii::$app->params['redis_locks'][$fullKey]);
        }
        
        return (bool)$result;
    }
    
    public function isLocked(string $key): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);
        
        return (bool)$redis->exists($fullKey);
    }
    
    /**
     * Множественная блокировка (для распределенных транзакций)
     */
    public function acquireMultiple(array $keys, int $ttl): bool
    {
        $redis = $this->getRedis();
        $pipeline = $redis->pipeline();
        $values = [];
        
        foreach ($keys as $key) {
            $fullKey = $this->getFullKey($key);
            $value = $this->generateLockValue();
            $values[$fullKey] = $value;
            
            $pipeline->eval(
                $this->lockScript,
                1,
                $fullKey,
                $value,
                $ttl
            );
        }
        
        $results = $pipeline->execute();
        
        // Проверяем, все ли блокировки получены
        $allAcquired = true;
        foreach ($results as $index => $result) {
            $fullKey = $this->getFullKey($keys[$index]);
            if ($result) {
                Yii::$app->params['redis_locks'][$fullKey] = $values[$fullKey];
            } else {
                $allAcquired = false;
            }
        }
        
        // Если не все получены, освобождаем полученные
        if (!$allAcquired) {
            $this->releaseMultiple($keys);
        }
        
        return $allAcquired;
    }
    
    /**
     * Освобождение множественных блокировок
     */
    public function releaseMultiple(array $keys): void
    {
        foreach ($keys as $key) {
            $this->release($key);
        }
    }
    
    private function getFullKey(string $key): string
    {
        return $this->prefix . $key;
    }
    
    private function generateLockValue(): string
    {
        return Yii::$app->security->generateRandomString(32) . ':' . microtime(true);
    }
    
    private function getRedis(): Connection
    {
        if (is_string($this->redis)) {
            $this->redis = Yii::$app->get($this->redis);
        }
        
        return $this->redis;
    }
}

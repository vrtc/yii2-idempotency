<?php

namespace Idempotency\storage;

use Yii;
use yii\caching\CacheInterface;
use Idempotency\storage\StorageInterface;
use Idempotency\exceptions\IdempotencyException;

/**
 * Хранилище на основе Yii2 Cache компонента
 */
class CacheStorage implements StorageInterface
{
    /**
     * @var CacheInterface|string Компонент кеша
     */
    public $cache = 'cache';
    
    /**
     * @var string Префикс для ключей
     */
    public $prefix = 'idemp:';
    
    /**
     * @var bool Сжимать данные
     */
    public $compress = true;
    
    /**
     * @var string Ключ для хранения индекса ключей (для cleanup)
     */
    public $indexKey = 'idemp:keys:index';
    
    /**
     * @var int Максимальное количество ключей в индексе
     */
    public $maxIndexSize = 10000;
    
    public function set(string $key, array $data, int $ttl): bool
    {
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($key);
        
        // Сохраняем данные
        $success = $cache->set($cacheKey, $this->serialize($data), $ttl);
        
        if ($success) {
            // Добавляем ключ в индекс для cleanup
            $this->addToIndex($key, $ttl);
        }
        
        return $success;
    }
    
    public function get(string $key): ?array
    {
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($key);
        
        $data = $cache->get($cacheKey);
        
        if ($data === false) {
            return null;
        }
        
        return $this->unserialize($data);
    }
    
    public function exists(string $key): bool
    {
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($key);
        
        return $cache->exists($cacheKey);
    }
    
    public function delete(string $key): bool
    {
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($key);
        
        $success = $cache->delete($cacheKey);
        
        if ($success) {
            $this->removeFromIndex($key);
        }
        
        return $success;
    }
    
    public function multiGet(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        
        $cache = $this->getCache();
        $cacheKeys = array_map([$this, 'getCacheKey'], $keys);
        
        $results = $cache->multiGet($cacheKeys);
        
        $data = [];
        foreach ($results as $cacheKey => $result) {
            if ($result !== false) {
                $originalKey = $this->getOriginalKey($cacheKey);
                $data[$originalKey] = $this->unserialize($result);
            }
        }
        
        return $data;
    }
    
    public function cleanup(int $batchSize = 1000): int
    {
        $cache = $this->getCache();
        $index = $cache->get($this->indexKey) ?: [];
        
        if (empty($index)) {
            return 0;
        }
        
        $deleted = 0;
        $now = time();
        
        foreach ($index as $key => $expires) {
            if ($expires < $now) {
                $cacheKey = $this->getCacheKey($key);
                if ($cache->delete($cacheKey)) {
                    $deleted++;
                    unset($index[$key]);
                }
            }
            
            if ($deleted >= $batchSize) {
                break;
            }
        }
        
        // Обновляем индекс
        $cache->set($this->indexKey, $index, 86400);
        
        return $deleted;
    }
    
    /**
     * Добавляет ключ в индекс для отслеживания
     */
    private function addToIndex(string $key, int $ttl): void
    {
        $cache = $this->getCache();
        $index = $cache->get($this->indexKey) ?: [];
        
        // Ограничиваем размер индекса
        if (count($index) >= $this->maxIndexSize) {
            // Удаляем самые старые записи
            asort($index);
            $index = array_slice($index, -$this->maxIndexSize + 1, null, true);
        }
        
        $index[$key] = time() + $ttl;
        $cache->set($this->indexKey, $index, 86400);
    }
    
    /**
     * Удаляет ключ из индекса
     */
    private function removeFromIndex(string $key): void
    {
        $cache = $this->getCache();
        $index = $cache->get($this->indexKey) ?: [];
        
        if (isset($index[$key])) {
            unset($index[$key]);
            $cache->set($this->indexKey, $index, 86400);
        }
    }
    
    private function getCacheKey(string $key): string
    {
        return $this->prefix . $key;
    }
    
    private function getOriginalKey(string $cacheKey): string
    {
        return substr($cacheKey, strlen($this->prefix));
    }
    
    private function serialize(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        if ($this->compress && function_exists('gzcompress')) {
            return gzcompress($json, 6);
        }
        
        return $json;
    }
    
    private function unserialize(string $data): array
    {
        if ($this->compress && function_exists('gzuncompress')) {
            $data = @gzuncompress($data);
            if ($data === false) {
                // Возможно, данные не сжаты
                return [];
            }
        }
        
        return json_decode($data, true) ?: [];
    }
    
    private function getCache(): CacheInterface
    {
        if (is_string($this->cache)) {
            $this->cache = Yii::$app->get($this->cache);
        }
        
        if (!$this->cache instanceof CacheInterface) {
            throw new IdempotencyException('Cache component must implement CacheInterface');
        }
        
        return $this->cache;
    }
}

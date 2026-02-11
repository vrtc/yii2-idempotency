<?php

namespace Idempotency\storage;

use Yii;
use yii\redis\Connection;
use Idempotency\storage\StorageInterface;

class RedisStorage implements StorageInterface
{
    /**
     * @var Connection Redis соединение
     */
    public $redis = 'redis';

    /**
     * @var string Префикс для ключей
     */
    public $prefix = 'idemp:';

    /**
     * @var bool Сжимать данные
     */
    public $compress = true;

    /**
     * @var int Уровень сжатия (1-9)
     */
    public $compressionLevel = 6;

    /**
     * @var int Максимальный размер индекса
     */
    private $maxIndexSize = 10000;
    /**
     * @var string Ключ для хранения индекса (для cleanup)
     */
    private $indexKey = 'idemp:keys:index';

    /**
     * Lua скрипт для атомарного сохранения
     * Исправленная версия
     */
    private $setScript = <<<LUA
local key = KEYS[1]
local data = ARGV[1]
local ttl = tonumber(ARGV[2])
local mode = ARGV[3]

-- Проверяем существование
local exists = redis.call('EXISTS', key)

if exists == 1 then
    if mode == 'strict' then
        -- Возвращаем существующие данные (для идемпотентности)
        return redis.call('GET', key)
    end
    -- Ключ уже существует
    return 'EXISTS'
end

-- Сохраняем
redis.call('SET', key, data)

if ttl > 0 then
    redis.call('EXPIRE', key, ttl)
end

return 'OK'
LUA;

    /**
     * Lua скрипт для массового получения
     */
    private $mgetScript = <<<LUA
local keys = {}
for i = 1, #KEYS do
    table.insert(keys, redis.call('GET', KEYS[i]))
end
return keys
LUA;

    public function set(string $key, array $data, int $ttl): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);

        $serialized = $this->serialize($data);

        try {
            $result = $redis->eval(
                $this->setScript,
                1,
                $fullKey,
                $serialized,
                $ttl,
                'strict'
            );

            // $this->stdout("Lua script result: " . var_export($result, true) . "\n", Console::FG_CYAN);

            if ($result === 'OK') {
                $this->addToIndex($fullKey);
                return true;
            }

            // Если вернулись данные, значит ключ уже существует
            // Это нормально для идемпотентности
            if ($result !== null && $result !== false && $result !== 'EXISTS') {
                $this->addToIndex($fullKey);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            var_dump($e->getMessage());
            exit;
            // $this->stderr("Lua script error: " . $e->getMessage() . "\n", Console::FG_RED);
            return false;
        }
    }

    public function get(string $key): ?array
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);

        $data = $redis->get($fullKey);

        if (!$data) {
            return null;
        }

        return $this->unserialize($data);
    }

    public function exists(string $key): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);

        return (bool) $redis->exists($fullKey);
    }

    public function delete(string $key): bool
    {
        $redis = $this->getRedis();
        $fullKey = $this->getFullKey($key);

        $result = (bool) $redis->del($fullKey);

        if ($result) {
            $this->removeFromIndex($fullKey);
        }

        return $result;
    }

    public function multiGet(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $redis = $this->getRedis();
        $fullKeys = array_map([$this, 'getFullKey'], $keys);

        $results = $redis->eval(
            $this->mgetScript,
            count($fullKeys),
            ...$fullKeys
        );

        $data = [];
        foreach ($results as $index => $result) {
            if ($result) {
                $data[$keys[$index]] = $this->unserialize($result);
            }
        }

        return $data;
    }

    /**
     * Массовое сохранение (пакетная обработка)
     */
    public function multiSet(array $items, int $ttl): bool
    {
        $redis = $this->getRedis();
        $pipeline = $redis->pipeline();

        foreach ($items as $key => $data) {
            $fullKey = $this->getFullKey($key);
            $serialized = $this->serialize($data);

            $pipeline->set($fullKey, $serialized);
            if ($ttl > 0) {
                $pipeline->expire($fullKey, $ttl);
            }
        }

        $pipeline->execute();
        return true;
    }

    private function getFullKey(string $key): string
    {
        return $this->prefix . $key;
    }

    private function serialize(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($this->compress && function_exists('gzcompress')) {
            return gzcompress($json, $this->compressionLevel);
        }

        return $json;
    }

    private function unserialize(string $data): array
    {
        if ($this->compress && function_exists('gzuncompress')) {
            $data = @gzuncompress($data);
            if ($data === false) {
                // Возможно, данные не сжаты
                $data = $data;
            }
        }

        return json_decode($data, true) ?: [];
    }

    private function getRedis(): Connection
    {
        if (is_string($this->redis)) {
            $this->redis = Yii::$app->get($this->redis);
        }

        return $this->redis;
    }

    public function cleanup(int $batchSize = 1000): int
    {
        $redis = $this->getRedis();

        // Для Redis используем SCAN для поиска ключей с истекшим TTL
        $cursor = 0;
        $deleted = 0;
        $now = time();

        do {
            // Ищем ключи по паттерну
            $result = $redis->executeCommand('SCAN', [$cursor, 'MATCH', $this->prefix . '*', 'COUNT', 100]);
            $cursor = $result[0];
            $keys = $result[1];

            if (!empty($keys)) {
                // Проверяем TTL для каждого ключа
                $pipeline = $redis->pipeline();

                foreach ($keys as $key) {
                    $pipeline->ttl($key);
                }

                $ttls = $pipeline->execute();

                // Удаляем ключи с TTL = -1 (нет TTL) или TTL = -2 (уже удален)
                $keysToDelete = [];
                foreach ($keys as $index => $key) {
                    if ($ttls[$index] <= 0) { // TTL <= 0 означает просроченный
                        $keysToDelete[] = $key;
                    }

                    if (count($keysToDelete) >= $batchSize) {
                        break;
                    }
                }

                // Удаляем просроченные ключи
                if (!empty($keysToDelete)) {
                    $redis->del(...$keysToDelete);
                    $deleted += count($keysToDelete);

                    // Удаляем из индекса
                    $this->removeFromIndexBatch($keysToDelete);
                }
            }

        } while ($cursor > 0 && $deleted < $batchSize);

        return $deleted;
    }

    /**
     * Добавляет ключ в индекс для отслеживания
     */
    private function addToIndex(string $key): void
    {
        $redis = $this->getRedis();

        // Удаляем префикс для сохранения оригинального ключа
        $originalKey = str_replace($this->prefix, '', $key);

        // Используем sorted set с timestamp как score
        $redis->zadd($this->indexKey, time(), $originalKey);

        // Ограничиваем размер sorted set
        $count = $redis->zcard($this->indexKey);
        if ($count > $this->maxIndexSize) {
            // Удаляем самые старые записи
            $redis->zremrangebyrank($this->indexKey, 0, $count - $this->maxIndexSize - 1);
        }
    }

    /**
     * Удаляет ключ из индекса
     */
    private function removeFromIndex(string $key): void
    {
        $redis = $this->getRedis();
        $originalKey = str_replace($this->prefix, '', $key);
        $redis->zrem($this->indexKey, $originalKey);
    }

    /**
     * Удаляет несколько ключей из индекса
     */
    private function removeFromIndexBatch(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        $redis = $this->getRedis();
        $originalKeys = array_map(function ($key) {
            return str_replace($this->prefix, '', $key);
        }, $keys);

        $redis->zrem($this->indexKey, ...$originalKeys);
    }
}

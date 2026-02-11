<?php

namespace Idempotency\storage;

use Yii;
use yii\db\Connection;
use yii\db\Transaction;
use Idempotency\storage\StorageInterface;
use Idempotency\exceptions\IdempotencyException;

class DatabaseStorage implements StorageInterface
{
    /**
     * @var Connection|string Компонент БД
     */
    public $db = 'db';
    
    /**
     * @var string Имя таблицы
     */
    public $tableName = '{{%idempotency_keys}}';
    
    /**
     * @var int Максимальное количество попыток при deadlock
     */
    public $maxDeadlockRetries = 3;
    
    /**
     * @var int Задержка между попытками при deadlock (миллисекунды)
     */
    public $deadlockRetryDelay = 100;
    
    public function set(string $key, array $data, int $ttl): bool
    {
        $db = $this->getDb();
        
        for ($attempt = 1; $attempt <= $this->maxDeadlockRetries; $attempt++) {
            try {
                $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
                
                // Используем INSERT ... ON DUPLICATE KEY UPDATE
                $result = $db->createCommand()
                    ->upsert($this->tableName, [
                        'idempotency_key' => $key,
                        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], [
                        'data' => new \yii\db\Expression('VALUES(data)'),
                        'expires_at' => new \yii\db\Expression('VALUES(expires_at)'),
                        'updated_at' => new \yii\db\Expression('NOW()'),
                    ])
                    ->execute();
                
                // Очищаем просроченные записи асинхронно
                if ($attempt === 1 && mt_rand(1, 100) === 1) { // 1% chance
                    $this->cleanupExpired();
                }
                
                $transaction->commit();
                return $result > 0;
                
            } catch (\yii\db\Exception $e) {
                if ($transaction ?? null) {
                    $transaction->rollBack();
                }
                
                // Проверяем deadlock
                if ($this->isDeadlock($e) && $attempt < $this->maxDeadlockRetries) {
                    usleep($this->deadlockRetryDelay * 1000);
                    continue;
                }
                
                throw new IdempotencyException(
                    'Failed to save idempotency key: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
        
        return false;
    }
    
    public function get(string $key): ?array
    {
        $db = $this->getDb();
        
        // Используем FOR UPDATE для предотвращения race condition
        $row = $db->createCommand("
            SELECT data, expires_at 
            FROM {$this->tableName} 
            WHERE idempotency_key = :key 
            AND expires_at > NOW()
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ", [':key' => $key])->queryOne();
        
        if (!$row) {
            return null;
        }
        
        return json_decode($row['data'], true) ?: null;
    }
    
    public function exists(string $key): bool
    {
        $db = $this->getDb();
        
        // Быстрая проверка без блокировки
        $exists = $db->createCommand("
            SELECT 1 
            FROM {$this->tableName} 
            WHERE idempotency_key = :key 
            AND expires_at > NOW()
            LIMIT 1
        ", [':key' => $key])->queryScalar();
        
        return (bool)$exists;
    }
    
    /**
     * Пакетная проверка существования ключей
     */
    public function batchExists(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        
        $db = $this->getDb();
        $placeholders = [];
        $params = [];
        
        foreach ($keys as $i => $key) {
            $placeholders[] = ':key' . $i;
            $params[':key' . $i] = $key;
        }
        
        $sql = "
            SELECT idempotency_key 
            FROM {$this->tableName} 
            WHERE idempotency_key IN (" . implode(',', $placeholders) . ")
            AND expires_at > NOW()
        ";
        
        $results = $db->createCommand($sql, $params)->queryColumn();
        
        return array_fill_keys($results, true);
    }
    
    /**
     * Очистка просроченных записей
     */
    private function cleanupExpired(): void
    {
        $db = $this->getDb();
        
        // Используем batch delete для больших таблиц
        $batchSize = 1000;
        $deleted = 0;
        
        do {
            $result = $db->createCommand("
                DELETE FROM {$this->tableName} 
                WHERE expires_at <= NOW() 
                LIMIT {$batchSize}
            ")->execute();
            
            $deleted += $result;
            
            // Даем БД передышку
            if ($result > 0) {
                usleep(10000); // 10ms
            }
            
        } while ($result > 0 && $deleted < 10000); // Максимум 10к за раз
    }
    
    private function isDeadlock(\Exception $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();
        
        // MySQL deadlock error codes
        if ($code == 1213 || $code == 1205) {
            return true;
        }
        
        // PostgreSQL deadlock
        if (strpos($message, 'deadlock detected') !== false) {
            return true;
        }
        
        // SQL Server deadlock
        if (strpos($message, 'deadlock') !== false && $code == 1205) {
            return true;
        }
        
        return false;
    }
    
    private function getDb(): Connection
    {
        if (is_string($this->db)) {
            $this->db = Yii::$app->get($this->db);
        }
        
        return $this->db;
    }
}

<?php

namespace Idempotency\lock;

/**
 * Интерфейс для блокировок
 */
interface LockInterface
{
    /**
     * Получает блокировку
     * 
     * @param string $key Ключ блокировки
     * @param int $ttl Время жизни блокировки в секундах
     * @return bool Успешность получения блокировки
     */
    public function acquire(string $key, int $ttl): bool;
    
    /**
     * Освобождает блокировку
     * 
     * @param string $key Ключ блокировки
     * @return bool Успешность освобождения
     */
    public function release(string $key): bool;
    
    /**
     * Проверяет, существует ли блокировка
     * 
     * @param string $key Ключ блокировки
     * @return bool
     */
    public function isLocked(string $key): bool;
    
    /**
     * Получает множественные блокировки атомарно
     * 
     * @param array $keys Массив ключей
     * @param int $ttl Время жизни блокировок
     * @return bool Все ли блокировки получены
     */
    public function acquireMultiple(array $keys, int $ttl): bool;
    
    /**
     * Освобождает множественные блокировки
     * 
     * @param array $keys Массив ключей
     */
    public function releaseMultiple(array $keys): void;
}

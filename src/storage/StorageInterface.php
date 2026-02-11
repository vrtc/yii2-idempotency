<?php

namespace Idempotency\storage;

/**
 * Интерфейс хранилища для ключей идемпотентности
 */
interface StorageInterface
{
    /**
     * Сохраняет данные по ключу
     * 
     * @param string $key Ключ идемпотентности
     * @param array $data Данные для сохранения
     * @param int $ttl Время жизни в секундах
     * @return bool Успешность операции
     */
    public function set(string $key, array $data, int $ttl): bool;
    
    /**
     * Получает данные по ключу
     * 
     * @param string $key Ключ идемпотентности
     * @return array|null Данные или null если не найдено
     */
    public function get(string $key): ?array;
    
    /**
     * Проверяет существование ключа
     * 
     * @param string $key Ключ идемпотентности
     * @return bool
     */
    public function exists(string $key): bool;
    
    /**
     * Удаляет ключ
     * 
     * @param string $key Ключ идемпотентности
     * @return bool Успешность операции
     */
    public function delete(string $key): bool;
    
    /**
     * Пакетное получение данных
     * 
     * @param array $keys Массив ключей
     * @return array Ассоциативный массив ключ => данные
     */
    public function multiGet(array $keys): array;
    
    /**
     * Очищает просроченные записи
     * 
     * @param int $batchSize Размер батча для очистки
     * @return int Количество удаленных записей
     */
    public function cleanup(int $batchSize = 1000): int;
}

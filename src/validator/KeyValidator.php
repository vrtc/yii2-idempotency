<?php

namespace Idempotency\validator;

use Ramsey\Uuid\Uuid;
use Idempotency\exceptions\InvalidKeyException;

/**
 * Валидатор ключей идемпотентности
 */
class KeyValidator
{
    /**
     * @var int Минимальная длина ключа
     */
    public $minLength = 1;
    
    /**
     * @var int Максимальная длина ключа
     */
    public $maxLength = 255;
    
    /**
     * @var string Регулярное выражение для валидации
     */
    public $pattern = '/^[a-zA-Z0-9_\-\.]+$/';
    
    /**
     * @var bool Проверять как UUID
     */
    public $checkUuid = true;
    
    /**
     * @var bool Проверять уникальность в пределах TTL
     */
    public $checkUniqueness = false;
    
    /**
     * Валидирует ключ идемпотентности
     * 
     * @param string $key Ключ для валидации
     * @return bool
     * @throws InvalidKeyException
     */
    public function validate(string $key): bool
    {
        if (empty($key)) {
            throw new InvalidKeyException('Idempotency key cannot be empty');
        }
        
        // Проверка длины
        $length = mb_strlen($key, 'UTF-8');
        if ($length < $this->minLength || $length > $this->maxLength) {
            throw new InvalidKeyException(
                sprintf('Idempotency key length must be between %d and %d characters', 
                    $this->minLength, $this->maxLength)
            );
        }
        
        // Проверка паттерна
        if ($this->pattern && !preg_match($this->pattern, $key)) {
            throw new InvalidKeyException(
                'Idempotency key contains invalid characters'
            );
        }
        
        // Проверка UUID формата
        if ($this->checkUuid && $this->isUuidFormat($key)) {
            if (!Uuid::isValid($key)) {
                throw new InvalidKeyException('Invalid UUID format');
            }
        }
        
        return true;
    }
    
    /**
     * Генерирует новый UUID ключ
     * 
     * @return string
     */
    public function generate(): string
    {
        return Uuid::uuid4()->toString();
    }
    
    /**
     * Проверяет, похож ли ключ на UUID
     * 
     * @param string $key
     * @return bool
     */
    private function isUuidFormat(string $key): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $key
        );
    }
    
    /**
     * Нормализует ключ (приводит к единому формату)
     * 
     * @param string $key
     * @return string
     */
    public function normalize(string $key): string
    {
        $key = trim($key);
        
        // Если это UUID, приводим к нижнему регистру
        if ($this->isUuidFormat($key)) {
            return strtolower($key);
        }
        
        return $key;
    }
    
    /**
     * Валидирует и нормализует ключ
     * 
     * @param string $key
     * @return string Нормализованный ключ
     * @throws InvalidKeyException
     */
    public function validateAndNormalize(string $key): string
    {
        $this->validate($key);
        return $this->normalize($key);
    }
}

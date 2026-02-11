<?php

namespace Idempotency\exceptions;

/**
 * Базовое исключение для идемпотентности
 */
class IdempotencyException extends \Exception
{
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Исключение при конкурентных запросах
 */
class ConcurrentRequestException extends IdempotencyException
{
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message ?: 'Too many concurrent requests', $code ?: 429, $previous);
    }
}

/**
 * Исключение при невалидном ключе
 */
class InvalidKeyException extends IdempotencyException
{
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message ?: 'Invalid idempotency key', $code ?: 400, $previous);
    }
}

/**
 * Исключение при оверселе (закончился товар и т.д.)
 */
class OverSellException extends IdempotencyException
{
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message ?: 'Resource limit exceeded', $code ?: 409, $previous);
    }
}

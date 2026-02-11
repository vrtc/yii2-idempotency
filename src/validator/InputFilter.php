<?php

namespace Idempotency\validator;

use Idempotency\exceptions\InvalidKeyException;

/**
 * InputFilter для валидации входных данных с фильтрацией чувствительных полей
 */
class InputFilter
{
    /**
     * @var array Список чувствительных полей для фильтрации
     */
    public static array $sensitiveFields = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'secret_key',
        'secretkey',
        'access_token',
        'refresh_token',
        'credit_card',
        'creditcard',
        'cvv',
        'cvc',
        'pin',
        'ssn',
        'social_security_number',
        'bank_account',
        'routing_number',
        'private_key',
        'privatekey',
        'encryption_key',
        'encryptionkey',
        'salt',
        'auth_token',
        'bearer_token',
    ];

    /**
     * Валидирует и очищает входные данные
     *
     * @param array $data Входные данные
     * @param array $rules Правила валидации (атрибут => правила)
     * @return array Очищенные данные
     * @throws InvalidKeyException При ошибке валидации
     */
    public static function validateAndFilter(array $data, array $rules = []): array
    {
        // Простая валидация на основе правил
        foreach ($rules as $attribute => $rule) {
            if (isset($data[$attribute])) {
                $value = $data[$attribute];
                
                // Проверка required
                if ($rule === 'required' && (empty($value) || $value === '')) {
                    throw new InvalidKeyException(
                        "Field '{$attribute}' is required",
                        422
                    );
                }
                
                // Проверка integer
                if ($rule === 'integer' && !is_int($value) && !ctype_digit((string)$value)) {
                    throw new InvalidKeyException(
                        "Field '{$attribute}' must be an integer",
                        422
                    );
                }
                
                // Проверка number
                if ($rule === 'number' && !is_numeric($value)) {
                    throw new InvalidKeyException(
                        "Field '{$attribute}' must be a number",
                        422
                    );
                }
                
                // Проверка string
                if ($rule === 'string' && !is_string($value)) {
                    throw new InvalidKeyException(
                        "Field '{$attribute}' must be a string",
                        422
                    );
                }
            }
        }

        // Фильтруем чувствительные данные
        return self::filterSensitiveData($data);
    }

    /**
     * Фильтрует чувствительные поля из массива данных
     *
     * @param array $data Входные данные
     * @return array Очищенные данные
     */
    public static function filterSensitiveData(array $data): array
    {
        $result = [];
        $sensitiveLower = array_map('strtolower', self::$sensitiveFields);

        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);

            // Проверяем, является ли поле чувствительным
            if (in_array($keyLower, $sensitiveLower)) {
                // Заменяем чувствительные данные на маску
                $result[$key] = self::maskSensitiveValue($value, $key);
            } elseif (is_array($value)) {
                // Рекурсивная обработка вложенных массивов
                $result[$key] = self::filterSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Маскирует чувствительное значение
     *
     * @param mixed $value Значение
     * @param string $key Ключ поля
     * @return string Маскированное значение
     */
    private static function maskSensitiveValue(mixed $value, string $key): string
    {
        if ($value === null) {
            return '***';
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length <= 4) {
                return str_repeat('*', $length);
            }
            // Показываем первые 2 и последние 2 символа
            $visibleStart = mb_substr($value, 0, 2);
            $visibleEnd = mb_substr($value, -2);
            return $visibleStart . str_repeat('*', $length - 4) . $visibleEnd;
        }

        if (is_numeric($value)) {
            return str_repeat('*', strlen((string)$value));
        }

        return '***';
    }

    /**
     * Подготавливает данные для логирования (убирает чувствительные поля)
     *
     * @param array $data Входные данные
     * @return array Данные для логирования
     */
    public static function prepareForLog(array $data): array
    {
        $result = [];
        $sensitiveLower = array_map('strtolower', self::$sensitiveFields);

        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);

            // Пропускаем чувствительные поля
            if (!in_array($keyLower, $sensitiveLower)) {
                if (is_array($value)) {
                    $result[$key] = self::prepareForLog($value);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Проверяет, является ли поле чувствительным
     *
     * @param string $key Ключ поля
     * @return bool
     */
    public static function isSensitiveField(string $key): bool
    {
        return in_array(strtolower($key), array_map('strtolower', self::$sensitiveFields));
    }

    /**
     * Добавляет дополнительное поле в список чувствительных
     *
     * @param string $field Имя поля
     */
    public static function addSensitiveField(string $field): void
    {
        if (!in_array($field, self::$sensitiveFields)) {
            self::$sensitiveFields[] = $field;
        }
    }

    /**
     * Удаляет поле из списка чувствительных
     *
     * @param string $field Имя поля
     */
    public static function removeSensitiveField(string $field): void
    {
        $key = array_search($field, self::$sensitiveFields);
        if ($key !== false) {
            unset(self::$sensitiveFields[$key]);
            self::$sensitiveFields = array_values(self::$sensitiveFields);
        }
    }
}

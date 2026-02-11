<?php

namespace Idempotency;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use Idempotency\storage\StorageInterface;
use Idempotency\lock\LockInterface;
use Idempotency\validator\KeyValidator;

/**
 * Компонент для настройки идемпотентности во всем приложении
 */
class IdempotencyComponent extends Component
{
    /**
     * @var array Конфигурация хранилища по умолчанию
     */
    public $defaultStorage = [
        'class' => 'Idempotency\storage\RedisStorage',
    ];
    
    /**
     * @var array Конфигурация блокировок по умолчанию
     */
    public $defaultLock = [
        'class' => 'Idempotency\lock\RedisLock',
    ];
    
    /**
     * @var array Конфигурация валидатора по умолчанию
     */
    public $defaultValidator = [
        'class' => 'Idempotency\validator\KeyValidator',
    ];
    
    /**
     * @var bool Включить автоматическую очистку старых ключей
     */
    public $enableAutoCleanup = true;
    
    /**
     * @var int Интервал автоматической очистки в секундах
     */
    public $cleanupInterval = 3600; // 1 час
    
    /**
     * @var int Количество ключей для очистки за раз
     */
    public $cleanupBatchSize = 1000;
    
    /**
     * @var bool Включить мониторинг и метрики
     */
    public $enableMetrics = false;
    
    /**
     * @var StorageInterface
     */
    private $_storage;
    
    /**
     * @var LockInterface
     */
    private $_locker;
    
    /**
     * @var KeyValidator
     */
    private $_validator;
    
    /**
     * @var float Время последней очистки
     */
    private $_lastCleanupTime = 0;
    
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        
        if ($this->enableAutoCleanup) {
            $this->scheduleCleanup();
        }
    }
    
    /**
     * Возвращает экземпляр хранилища
     * 
     * @return StorageInterface
     * @throws InvalidConfigException
     */
    public function getStorage(): StorageInterface
    {
        if ($this->_storage === null) {
            $this->_storage = Yii::createObject($this->defaultStorage);
            
            if (!$this->_storage instanceof StorageInterface) {
                throw new InvalidConfigException('Storage must implement StorageInterface');
            }
        }
        
        return $this->_storage;
    }
    
    /**
     * Возвращает экземпляр блокировки
     * 
     * @return LockInterface
     * @throws InvalidConfigException
     */
    public function getLocker(): LockInterface
    {
        if ($this->_locker === null) {
            $this->_locker = Yii::createObject($this->defaultLock);
            
            if (!$this->_locker instanceof LockInterface) {
                throw new InvalidConfigException('Locker must implement LockInterface');
            }
        }
        
        return $this->_locker;
    }
    
    /**
     * Возвращает экземпляр валидатора
     * 
     * @return KeyValidator
     * @throws InvalidConfigException
     */
    public function getValidator(): KeyValidator
    {
        if ($this->_validator === null) {
            $this->_validator = Yii::createObject($this->defaultValidator);
        }
        
        return $this->_validator;
    }
    
    /**
     * Генерирует новый ключ идемпотентности
     * 
     * @return string
     */
    public function generateKey(): string
    {
        return $this->getValidator()->generate();
    }
    
    /**
     * Пакетная проверка ключей на существование
     * 
     * @param array $keys Массив ключей
     * @return array Массив существующих ключей
     */
    public function batchCheck(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        
        $storage = $this->getStorage();
        $existing = [];
        
        if (method_exists($storage, 'batchExists')) {
            $existing = $storage->batchExists($keys);
        } else {
            foreach ($keys as $key) {
                if ($storage->exists($key)) {
                    $existing[$key] = true;
                }
            }
        }
        
        return array_keys($existing);
    }
    
    /**
     * Очистка просроченных ключей
     * 
     * @return int Количество удаленных ключей
     */
    public function cleanup(): int
    {
        $storage = $this->getStorage();
        
        if (method_exists($storage, 'cleanup')) {
            $deleted = $storage->cleanup($this->cleanupBatchSize);
            
            if ($this->enableMetrics) {
                Yii::info("Cleaned up {$deleted} expired idempotency keys", 'idempotency');
            }
            
            $this->_lastCleanupTime = microtime(true);
            return $deleted;
        }
        
        return 0;
    }
    
    /**
     * Планирует автоматическую очистку
     */
    private function scheduleCleanup(): void
    {
        Yii::$app->on(\yii\web\Application::EVENT_BEFORE_REQUEST, function() {
            $now = microtime(true);
            
            if (($now - $this->_lastCleanupTime) > $this->cleanupInterval) {
                // Выполняем в фоне, чтобы не блокировать запрос
                register_shutdown_function(function() {
                    try {
                        $this->cleanup();
                    } catch (\Exception $e) {
                        Yii::error("Cleanup failed: " . $e->getMessage(), 'idempotency');
                    }
                });
            }
        });
    }
    
    /**
     * Метрики использования
     * 
     * @return array
     */
    public function getMetrics(): array
    {
        if (!$this->enableMetrics) {
            return [];
        }
        
        $storage = $this->getStorage();
        
        return [
            'last_cleanup' => $this->_lastCleanupTime ? date('Y-m-d H:i:s', $this->_lastCleanupTime) : null,
            'storage_class' => get_class($storage),
            'locker_class' => get_class($this->getLocker()),
        ];
    }
}

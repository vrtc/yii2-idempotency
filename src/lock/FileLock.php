<?php

namespace Idempotency\lock;

use Yii;
use yii\base\BaseObject;
use Idempotency\lock\LockInterface;
use Idempotency\exceptions\ConcurrentRequestException;

/**
 * Блокировки на основе файловой системы
 */
class FileLock extends BaseObject implements LockInterface
{
    /**
     * @var string Путь к директории для блокировок
     */
    public $lockDir = '@runtime/locks';
    
    /**
     * @var int Время ожидания блокировки в микросекундах
     */
    public $spinWait = 1000;
    
    /**
     * @var int Максимальное время ожидания в секундах
     */
    public $maxWait = 10;
    
    /**
     * @var bool Использовать flock или файлы
     */
    public $useFlock = true;
    
    private $_locks = [];
    
    public function init()
    {
        parent::init();
        
        $this->lockDir = Yii::getAlias($this->lockDir);
        
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0777, true);
        }
    }
    
    public function acquire(string $key, int $ttl): bool
    {
        $lockFile = $this->getLockFile($key);
        
        if ($this->useFlock) {
            return $this->acquireFlock($lockFile, $ttl);
        }
        
        return $this->acquireFile($lockFile, $ttl);
    }
    
    public function release(string $key): bool
    {
        $lockFile = $this->getLockFile($key);
        
        if ($this->useFlock) {
            return $this->releaseFlock($lockFile);
        }
        
        return $this->releaseFile($lockFile);
    }
    
    public function isLocked(string $key): bool
    {
        $lockFile = $this->getLockFile($key);
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        if ($this->useFlock) {
            // Для flock проверяем, можем ли получить блокировку
            $fp = @fopen($lockFile, 'r+');
            if (!$fp) {
                return true;
            }
            
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            fclose($fp);
            return true;
        }
        
        // Для файлов проверяем время создания
        $content = @file_get_contents($lockFile);
        if ($content === false) {
            return false;
        }
        
        $data = json_decode($content, true);
        if (!$data || !isset($data['expires'])) {
            return false;
        }
        
        return $data['expires'] > time();
    }
    
    public function acquireMultiple(array $keys, int $ttl): bool
    {
        $acquired = [];
        
        try {
            foreach ($keys as $key) {
                if (!$this->acquire($key, $ttl)) {
                    // Если не удалось получить одну блокировку, освобождаем все
                    $this->releaseMultiple($acquired);
                    return false;
                }
                $acquired[] = $key;
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->releaseMultiple($acquired);
            throw $e;
        }
    }
    
    public function releaseMultiple(array $keys): void
    {
        foreach ($keys as $key) {
            $this->release($key);
        }
    }
    
    private function acquireFlock(string $lockFile, int $ttl): bool
    {
        $fp = fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }
        
        $startTime = microtime(true);
        
        while (true) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                // Записываем время истечения
                ftruncate($fp, 0);
                fwrite($fp, (string)(time() + $ttl));
                fflush($fp);
                
                $this->_locks[$lockFile] = $fp;
                return true;
            }
            
            // Проверяем время ожидания
            if ((microtime(true) - $startTime) > $this->maxWait) {
                fclose($fp);
                return false;
            }
            
            usleep($this->spinWait);
        }
    }
    
    private function releaseFlock(string $lockFile): bool
    {
        if (!isset($this->_locks[$lockFile])) {
            return false;
        }
        
        $fp = $this->_locks[$lockFile];
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        // Удаляем файл
        @unlink($lockFile);
        
        unset($this->_locks[$lockFile]);
        
        return true;
    }
    
    private function acquireFile(string $lockFile, int $ttl): bool
    {
        $startTime = microtime(true);
        
        while (true) {
            // Пытаемся создать файл атомарно
            if (!file_exists($lockFile)) {
                $data = [
                    'pid' => getmypid(),
                    'expires' => time() + $ttl,
                    'created' => microtime(true),
                ];
                
                $tempFile = $lockFile . '.' . uniqid('', true);
                file_put_contents($tempFile, json_encode($data));
                
                // Атомарное переименование
                if (@rename($tempFile, $lockFile)) {
                    $this->_locks[$lockFile] = true;
                    return true;
                }
                
                @unlink($tempFile);
            } else {
                // Проверяем, не просрочен ли файл
                $content = @file_get_contents($lockFile);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if ($data && isset($data['expires']) && $data['expires'] < time()) {
                        // Удаляем просроченный файл
                        @unlink($lockFile);
                        continue;
                    }
                }
            }
            
            // Проверяем время ожидания
            if ((microtime(true) - $startTime) > $this->maxWait) {
                return false;
            }
            
            usleep($this->spinWait);
        }
    }
    
    private function releaseFile(string $lockFile): bool
    {
        if (!isset($this->_locks[$lockFile])) {
            return false;
        }
        
        $success = @unlink($lockFile);
        
        if ($success) {
            unset($this->_locks[$lockFile]);
        }
        
        return $success;
    }
    
    private function getLockFile(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->lockDir . '/' . md5($safeKey) . '.lock';
    }
    
    public function __destruct()
    {
        // Освобождаем все блокировки при уничтожении объекта
        foreach (array_keys($this->_locks) as $lockFile) {
            $this->releaseFile($lockFile);
        }
    }
}

<?php

namespace Idempotency\console;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Консольные команды для управления идемпотентностью
 */
class IdempotencyController extends Controller
{

    /**
     * Summary of actionIndex
     */
    public function actionIndex()
    {
        $this->stdout("Idempotency Component Console Commands\n\n", Console::FG_YELLOW);
        $this->stdout("Available commands:\n", Console::FG_CYAN);
        $this->stdout("  cleanup [batchSize]      Clean up expired idempotency keys\n");
        $this->stdout("  generate-key             Generate a test idempotency key\n");
        $this->stdout("  test-storage             Test storage connection\n");
        $this->stdout("  stats                    Show statistics\n");
        $this->stdout("\nUsage:\n", Console::FG_CYAN);
        $this->stdout("  php yii idempotency/cleanup 1000\n");
        $this->stdout("  php yii idempotency/generate-key\n");

        return ExitCode::OK;
    }
    /**
     * Очищает просроченные ключи идемпотентности
     * 
     * @param int $batchSize Размер батча для очистки
     * @return int
     */
    public function actionCleanup($batchSize = 1000)
    {
        $this->stdout("Cleaning up expired idempotency keys...\n", Console::FG_YELLOW);

        $component = Yii::$app->idempotency;
        $deleted = $component->cleanup($batchSize);

        $this->stdout("Deleted {$deleted} expired keys\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Генерирует тестовый ключ идемпотентности
     * 
     * @return int
     */
    public function actionGenerateKey()
    {
        $component = Yii::$app->idempotency;
        $key = $component->generateKey();

        $this->stdout("Generated idempotency key:\n", Console::FG_YELLOW);
        $this->stdout("{$key}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Проверяет работоспособность хранилища
     * 
     * @return int
     */
    public function actionTestStorage()
    {
        $this->stdout("Testing storage connection...\n", Console::FG_YELLOW);

        try {
            $component = Yii::$app->idempotency;
            $storage = $component->getStorage();

            // Тестовая запись
            $testKey = 'test_' . uniqid();
            $testData = ['test' => 'data', 'timestamp' => time()];

            $saved = $storage->set($testKey, $testData, 60);

            if (!$saved) {
                $this->stderr("Failed to save test data\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Test data saved\n", Console::FG_GREEN);

            // Чтение
            $readData = $storage->get($testKey);

            if ($readData === null) {
                $this->stderr("Failed to read test data\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Test data read successfully\n", Console::FG_GREEN);

            // Проверка существования
            $exists = $storage->exists($testKey);

            if (!$exists) {
                $this->stderr("Exists check failed\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Exists check passed\n", Console::FG_GREEN);

            // Удаление
            $deleted = $storage->delete($testKey);

            if (!$deleted) {
                $this->stderr("Failed to delete test data\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Test data deleted\n", Console::FG_GREEN);
            $this->stdout("\nAll tests passed!\n", Console::FG_GREEN, Console::BOLD);

            return ExitCode::OK;

        } catch (\Exception $e) {
            $this->stderr("Error: " . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Показывает статистику
     * 
     * @return int
     */
    public function actionStats()
    {
        $component = Yii::$app->idempotency;
        $metrics = $component->getMetrics();

        $this->stdout("Idempotency Component Statistics\n", Console::FG_YELLOW, Console::BOLD);
        $this->stdout("================================\n", Console::FG_YELLOW);

        foreach ($metrics as $key => $value) {
            $this->stdout(str_pad($key, 20) . ": ", Console::FG_CYAN);
            $this->stdout($value . "\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}

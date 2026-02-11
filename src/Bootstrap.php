<?php

namespace Idempotency;

use Yii;
use yii\base\BootstrapInterface;

/**
 * Bootstrap класс для автоматической настройки
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        // Регистрируем компонент, если он не указан в конфиге
        if (!$app->has('idempotency')) {
            $app->set('idempotency', [
                'class' => 'Idempotency\IdempotencyComponent',
            ]);
        }
        
        // Добавляем правила валидации для ключей
        $validator = Yii::$app->idempotency->getValidator();
        
        // Регистрируем команду для консоли
        if ($app instanceof \yii\console\Application) {
            $app->controllerMap['idempotency'] = [
                'class' => 'Idempotency\console\IdempotencyController',
            ];
        }
        
        // Регистрируем алиас для простого использования
        Yii::setAlias('@Idempotency', __DIR__);
    }
}

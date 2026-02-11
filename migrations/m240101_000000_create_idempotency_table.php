<?php

use yii\db\Migration;
use yii\db\Expression;

class m240101_000000_create_idempotency_table extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }
        
        $this->createTable('{{%idempotency_keys}}', [
            'id' => $this->bigPrimaryKey(),
            'idempotency_key' => $this->string(255)->notNull(),
            'data' => $this->json()->notNull(),
            'expires_at' => $this->timestamp()->notNull(),
            'created_at' => $this->timestamp()->defaultValue(new Expression('CURRENT_TIMESTAMP')),
            'updated_at' => $this->timestamp()->defaultValue(new Expression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')),
        ], $tableOptions);
        
        // Индексы для производительности
        $this->createIndex('idx-idempotency_key', '{{%idempotency_keys}}', 'idempotency_key', true);
        $this->createIndex('idx-expires_at', '{{%idempotency_keys}}', 'expires_at');
        $this->createIndex('idx-created_at', '{{%idempotency_keys}}', 'created_at');
        
        // Комбинированный индекс для частых запросов
        $this->createIndex('idx-key-expires', '{{%idempotency_keys}}', ['idempotency_key', 'expires_at']);
        
        // Партиционирование по дате для больших таблиц (MySQL)
        if ($this->db->driverName === 'mysql') {
            $this->execute("
                ALTER TABLE {{%idempotency_keys}} 
                PARTITION BY RANGE (TO_DAYS(created_at)) (
                    PARTITION p2024 VALUES LESS THAN (TO_DAYS('2025-01-01')),
                    PARTITION p2025 VALUES LESS THAN (TO_DAYS('2026-01-01')),
                    PARTITION p_future VALUES LESS THAN MAXVALUE
                )
            ");
        }
    }
    
    public function safeDown()
    {
        $this->dropTable('{{%idempotency_keys}}');
    }
}

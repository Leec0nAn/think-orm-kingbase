<?php

declare(strict_types=1);

namespace tests\kingbase;

final class KingbaseMysqlOrmAdvancedTest extends KingbaseOrmAdvancedTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureAdvancedConnection('kingbase_mysql_orm_advanced', [
            'type' => 'kingbaseMysql',
            'hostname' => self::envValue('KINGBASE_MYSQL_HOST', '127.0.0.1'),
            'hostport' => self::envValue('KINGBASE_MYSQL_PORT', '54321'),
            'database' => self::envValue('KINGBASE_MYSQL_DATABASE', 'test'),
            'username' => self::envValue('KINGBASE_MYSQL_USERNAME', 'system'),
            'password' => self::envValue('KINGBASE_MYSQL_PASSWORD'),
            'charset' => self::envValue('KINGBASE_MYSQL_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => self::envValue('KINGBASE_MYSQL_SCHEMA', 'public'),
            'fields_cache' => false,
            'trigger_sql' => true,
        ], 'kb_adv_mysql');
    }

    protected static function createTableSql(): array
    {
        return [
            'CREATE TABLE kb_adv_mysql_author (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_mysql_profile (id INT PRIMARY KEY, author_id INT NOT NULL, nickname VARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_mysql_post (id INT PRIMARY KEY, author_id INT NOT NULL, title VARCHAR(100) NOT NULL, status INT DEFAULT 0)',
            'CREATE TABLE kb_adv_mysql_tag (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_mysql_post_tag (post_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (post_id, tag_id))',
            'CREATE TABLE kb_adv_mysql_soft_item (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, status INT DEFAULT 0, delete_time TIMESTAMP NULL)',
            'CREATE TABLE kb_adv_mysql_item (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, status INT DEFAULT 0, create_time DATETIME NULL, update_time DATETIME NULL)',
            'CREATE TABLE kb_adv_mysql_counter (id INT PRIMARY KEY, counter_value INT NOT NULL)',
        ];
    }
}

<?php

declare(strict_types=1);

namespace tests\kingbase;

final class KingbaseSqlsrvOrmAdvancedTest extends KingbaseOrmAdvancedTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureAdvancedConnection('kingbase_sqlsrv_orm_advanced', [
            'type' => 'kingbaseSqlsrv',
            'hostname' => self::envValue('KINGBASE_SQLSRV_HOST', '127.0.0.1'),
            'hostport' => self::envValue('KINGBASE_SQLSRV_PORT', '54323'),
            'database' => self::envValue('KINGBASE_SQLSRV_DATABASE', 'test'),
            'username' => self::envValue('KINGBASE_SQLSRV_USERNAME', 'system'),
            'password' => self::envValue('KINGBASE_SQLSRV_PASSWORD'),
            'charset' => self::envValue('KINGBASE_SQLSRV_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => self::envValue('KINGBASE_SQLSRV_SCHEMA', 'dbo'),
            'fields_cache' => false,
            'trigger_sql' => true,
        ], 'kb_adv_sqlsrv');
    }

    protected static function createTableSql(): array
    {
        return [
            'CREATE TABLE kb_adv_sqlsrv_author (id INT PRIMARY KEY, name NVARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_sqlsrv_profile (id INT PRIMARY KEY, author_id INT NOT NULL, nickname NVARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_sqlsrv_post (id INT PRIMARY KEY, author_id INT NOT NULL, title NVARCHAR(100) NOT NULL, status INT DEFAULT 0)',
            'CREATE TABLE kb_adv_sqlsrv_tag (id INT PRIMARY KEY, name NVARCHAR(50) NOT NULL)',
            'CREATE TABLE kb_adv_sqlsrv_post_tag (post_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (post_id, tag_id))',
            'CREATE TABLE kb_adv_sqlsrv_soft_item (id INT PRIMARY KEY, name NVARCHAR(50) NOT NULL, status INT DEFAULT 0, delete_time DATETIME2 NULL)',
            'CREATE TABLE kb_adv_sqlsrv_item (id INT PRIMARY KEY, name NVARCHAR(50) NOT NULL, status INT DEFAULT 0, create_time DATETIME2 NULL, update_time DATETIME2 NULL)',
            'CREATE TABLE kb_adv_sqlsrv_counter (id INT PRIMARY KEY, counter_value INT NOT NULL)',
        ];
    }
}

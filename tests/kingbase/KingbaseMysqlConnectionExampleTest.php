<?php

declare(strict_types=1);

namespace tests\kingbase;

use think\db\connector\KingbaseMysql;

/**
 * 面向需求文档截图的最小连接示例。
 *
 * 该用例只验证显式 kingbaseMysql 连接器装载和 SELECT 1，不创建数据库
 * 对象、不安装扩展，也不复用完整回归套件的批量清理逻辑。
 */
final class KingbaseMysqlConnectionExampleTest extends KingbaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureConnection('kingbase_mysql_example', [
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
        ]);
    }

    public function testExplicitMysqlConnectorAndSelectOne(): void
    {
        self::verboseLog('ACTION', 'Creating the explicit kingbaseMysql connection');
        $connection = $this->conn();
        $this->assertInstanceOf(KingbaseMysql::class, $connection);
        self::verboseLog('ASSERT', 'KingbaseMysql connector loaded', [
            'connector' => get_class($connection),
        ]);

        self::verboseLog('ACTION', 'Executing the minimal business query');
        $rows = $connection->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
        self::verboseLog('ASSERT', 'SELECT 1 returned the expected value', [
            'rows' => count($rows),
            'ok' => (int) $rows[0]['ok'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace tests\kingbase;

use PDO;

/**
 * 验证 KingbaseES 结果列名大小写不会改变 ThinkORM 的字段访问约定。
 *
 * 本用例只修改当前连接的会话参数并执行只读 SELECT，不创建或删除数据库对象。
 */
final class KingbaseOracleColumnCaseTest extends KingbaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureConnection('kingbase_oracle_column_case', [
            'type' => 'kingbaseOracle',
            'hostname' => self::envValue('KINGBASE_ORACLE_HOST', '127.0.0.1'),
            'hostport' => self::envValue('KINGBASE_ORACLE_PORT', '54322'),
            'database' => self::envValue('KINGBASE_ORACLE_DATABASE', 'test'),
            'username' => self::envValue('KINGBASE_ORACLE_USERNAME', 'system'),
            'password' => self::envValue('KINGBASE_ORACLE_PASSWORD'),
            'charset' => self::envValue('KINGBASE_ORACLE_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => self::envValue('KINGBASE_ORACLE_SCHEMA', ''),
            'fields_cache' => false,
            'trigger_sql' => true,
        ]);
    }

    public function testResultColumnNamesAreLowercaseAtPdoBoundary(): void
    {
        $connection = $this->conn();
        $pdo = $connection->connect();

        $this->assertSame(PDO::CASE_LOWER, $pdo->getAttribute(PDO::ATTR_CASE));

        try {
            // 模拟 Oracle 兼容环境的推荐设置，验证数据库返回大写列标签时，
            // PDO 边界仍为 ThinkORM 提供稳定的小写字段键。
            $connection->execute('SET enable_upper_colname = on');
            $rows = $connection->query('SELECT 1 AS KDB_COLUMN_CASE_PROBE FROM dual');
        } finally {
            $connection->execute('RESET enable_upper_colname');
        }

        $this->assertSame([['kdb_column_case_probe' => 1]], $rows);
    }
}

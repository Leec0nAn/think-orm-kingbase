<?php

declare(strict_types=1);

namespace tests\kingbase;

use PDO;
use think\facade\Db;
use UnexpectedValueException;

/**
 * PHPUnit tests for KingbaseES SQL Server compatibility mode.
 *
 * Mirrors the 26 test items from tests/kingbase/sqlsrv_compat.php and the
 * testing document ThinkPHP3.2-ThinkPHP8-KingbaseES测试文档_V1.0.md.
 */
final class KingbaseSqlsrvTest extends KingbaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureConnection('kingbase_sqlsrv', [
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
        ]);
    }

    protected function setUp(): void
    {
        $this->dropIfExists('DROP TABLE kb_sqlsrv_composite');
        $this->dropIfExists('DROP VIEW kb_sqlsrv_active_users');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_archive');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_delete_mark');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_order');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_user');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_misc');
    }

    protected function tearDown(): void
    {
        $this->dropIfExists('DROP TABLE kb_sqlsrv_composite');
        $this->dropIfExists('DROP VIEW kb_sqlsrv_active_users');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_archive');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_delete_mark');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_order');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_user');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_misc');
    }

    // 1. connection
    public function testConnection(): void
    {
        $conn = $this->conn();
        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
    }

    // 2. pdo mode schema and reconnect
    public function testPdoModeSchemaAndReconnect(): void
    {
        $conn = $this->conn();
        $this->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)));
        $this->assertSame('sqlserver', $conn->getKingbaseMode());
        $this->assertSame('dbo', strtolower($conn->getKingbaseSchema()));

        $conn->close();
        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
        $this->assertSame('dbo', strtolower($conn->getKingbaseSchema()));
    }

    // 3. compatibility mode mismatch is rejected
    public function testCompatibilityModeMismatchIsRejected(): void
    {
        $config = $this->conn()->getConfig();
        $config['type'] = 'kingbaseMysql';
        $config['schema'] = '';

        try {
            Db::connect($config, true)->query('SELECT 1 AS ok');
            $this->fail('connector accepted the wrong KES compatibility mode');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('mode mismatch', $e->getMessage());
        }
    }

    // 4. ddl and sqlserver-compatible types
    public function testDdlAndSqlserverCompatibleTypes(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TABLE kb_sqlsrv_user (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            name NVARCHAR(50) UNIQUE,
            age INT NULL,
            score DECIMAL(10,2) DEFAULT 0,
            active BIT DEFAULT 1,
            bio NVARCHAR(MAX) NULL,
            data_blob VARBINARY(MAX) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $conn->execute('CREATE TABLE kb_sqlsrv_order (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status NVARCHAR(20) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $conn->execute('CREATE TABLE kb_sqlsrv_misc (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            value_text NVARCHAR(100),
            value_large NVARCHAR(MAX),
            value_blob VARBINARY(MAX) NULL
        )');

        $tables = $conn->getTables();
        $this->assertContains('kb_sqlsrv_user', $tables);
    }

    // 5. raw identity insert and identity function
    public function testRawIdentityInsertAndIdentityFunction(): void
    {
        $this->testDdlAndSqlserverCompatibleTypes();
        $conn = $this->conn();

        $conn->execute("INSERT INTO kb_sqlsrv_user (name, age, score, bio, data_blob) VALUES ('alpha', 18, 98.50, 'first user', 0x01020304)");
        $conn->execute("INSERT INTO kb_sqlsrv_user (name, age, score, bio, data_blob) VALUES ('beta', 20, 88.00, 'second user', 0x01020304)");

        $rows = $conn->query('SELECT @@IDENTITY AS id');
        $this->assertSame(2, (int) $rows[0]['id']);
    }

    // 6. insertGetId and basic CRUD
    public function testInsertGetIdAndBasicCrud(): void
    {
        $this->testRawIdentityInsertAndIdentityFunction();
        $conn = $this->conn();

        $id = $conn->table('kb_sqlsrv_user')->insertGetId([
            'name' => 'gamma', 'age' => 22, 'score' => '77.25', 'bio' => 'third user',
        ]);
        $this->assertSame(3, (int) $id);

        $conn->table('kb_sqlsrv_user')->where('id', $id)->update(['age' => 23]);
        $row = $conn->table('kb_sqlsrv_user')->where('id', $id)->find();
        $this->assertSame(23, (int) $row['age']);
    }

    // 7. metadata tables and fields
    public function testMetadataTablesAndFields(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $tables = $conn->getTables();
        $this->assertContains('kb_sqlsrv_user', $tables);

        $fields = $conn->getFields('kb_sqlsrv_user');
        $this->assertArrayHasKey('id', $fields);
        $this->assertTrue($fields['id']['primary']);
        $this->assertTrue($fields['id']['autoinc']);
    }

    // 8. composite primary key metadata
    public function testCompositePrimaryKeyMetadata(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TABLE kb_sqlsrv_composite (
            tenant_id INT NOT NULL,
            item_id INT NOT NULL,
            label NVARCHAR(50) UNIQUE,
            PRIMARY KEY (tenant_id, item_id)
        )');

        $fields = $conn->getFields('kb_sqlsrv_composite');
        $this->assertTrue($fields['tenant_id']['primary']);
        $this->assertTrue($fields['item_id']['primary']);
        $this->assertFalse($fields['label']['primary']);
    }

    // 9. where operators
    public function testWhereOperators(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $this->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->where('age', '>', 18)->count());
        $this->assertSame(1, (int) $conn->table('kb_sqlsrv_user')->where('name', 'like', 'a%')->count());
        $this->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->whereBetween('age', [18, 20])->count());
        $this->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->whereIn('name', ['alpha', 'gamma'])->count());
    }

    // 10. unique constraint violation
    public function testUniqueConstraintViolation(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $this->expectException(\Throwable::class);
        $conn->table('kb_sqlsrv_user')->insert(['name' => 'alpha', 'age' => 25]);
    }

    // 11. alter table index and defaults
    public function testAlterTableIndexAndDefaults(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->execute("ALTER TABLE kb_sqlsrv_user ADD status_flag NVARCHAR(20) DEFAULT 'new'");
        $conn->execute('CREATE INDEX idx_kb_sqlsrv_user_age ON kb_sqlsrv_user(age)');

        $conn->table('kb_sqlsrv_user')->insert(['name' => 'delta', 'age' => 24, 'score' => '66.00']);

        $status = $conn->table('kb_sqlsrv_user')->where('name', 'delta')->value('status_flag');
        $this->assertSame('new', (string) $status);

        $fields = $conn->getFields('kb_sqlsrv_user');
        $this->assertArrayHasKey('status_flag', $fields);
    }

    // 12. bracket identifier and top
    public function testBracketIdentifierAndTop(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $rows = $conn->query('SELECT [id], [name], [age] FROM [kb_sqlsrv_user] ORDER BY [id]');
        $this->assertGreaterThanOrEqual(3, count($rows));

        $rows = $conn->query('SELECT TOP 2 * FROM kb_sqlsrv_user ORDER BY id');
        $this->assertCount(2, $rows);
    }

    // 13. join query
    public function testJoinQuery(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->table('kb_sqlsrv_order')->insertAll([
            ['user_id' => 1, 'amount' => '10.50', 'status' => 'paid'],
            ['user_id' => 1, 'amount' => '20.00', 'status' => 'paid'],
            ['user_id' => 2, 'amount' => '15.00', 'status' => 'new'],
        ]);

        $rows = $conn->table('kb_sqlsrv_user')
            ->alias('u')
            ->join('kb_sqlsrv_order o', 'u.id = o.user_id')
            ->where('o.status', 'paid')
            ->field('u.name,o.amount')
            ->select()
            ->toArray();
        $this->assertCount(2, $rows);
    }

    // 14. aggregate group having
    public function testAggregateGroupHaving(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $sum = $conn->table('kb_sqlsrv_order')->where('status', 'paid')->sum('amount');
        $this->assertSame('30.50', number_format((float) $sum, 2, '.', ''));

        $rows = $conn->table('kb_sqlsrv_order')
            ->field('user_id,COUNT(*) AS order_count')
            ->group('user_id')
            ->having('COUNT(*) >= 2')
            ->select()
            ->toArray();
        $this->assertCount(1, $rows);
    }

    // 15. union and subquery
    public function testUnionAndSubquery(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $unionRows = $conn->query("SELECT name FROM kb_sqlsrv_user WHERE name = 'alpha' UNION ALL SELECT name FROM kb_sqlsrv_user WHERE name = 'beta'");
        $this->assertCount(2, $unionRows);

        $subRows = $conn->query("SELECT * FROM kb_sqlsrv_user WHERE id IN (SELECT user_id FROM kb_sqlsrv_order WHERE status = 'paid')");
        $this->assertCount(1, $subRows);
    }

    // 16. native pagination
    public function testRowNumberPagination(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $rows = $conn->table('kb_sqlsrv_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
        $this->assertCount(2, $rows);
    }

    // 17. raw sqlserver functions
    public function testRawSqlserverFunctions(): void
    {
        $conn = $this->conn();
        $row = $conn->query("SELECT
            ISNULL(NULL, 'fallback') AS c1,
            COALESCE(NULL, 'coalesce') AS c2,
            CASE WHEN 2 > 1 THEN 'yes' ELSE 'no' END AS c3,
            SUBSTRING('abcdef', 2, 3) AS c4,
            LEN('abc') AS c5,
            CURRENT_TIMESTAMP AS c6
        ");

        $this->assertSame('fallback', (string) $row[0]['c1']);
        $this->assertSame('coalesce', (string) $row[0]['c2']);
        $this->assertSame('yes', (string) $row[0]['c3']);
        $this->assertSame('bcd', (string) $row[0]['c4']);
        $this->assertSame(3, (int) $row[0]['c5']);
        $this->assertNotEmpty($row[0]['c6']);
    }

    // 18. insert select and archive table
    public function testInsertSelectAndArchiveTable(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->execute('CREATE TABLE kb_sqlsrv_archive (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            name NVARCHAR(50),
            archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $affected = $conn->execute("INSERT INTO kb_sqlsrv_archive (user_id, name) SELECT id, name FROM kb_sqlsrv_user WHERE active = 1");
        $this->assertGreaterThanOrEqual(1, $affected);
        $this->assertSame(
            (int) $conn->table('kb_sqlsrv_user')->where('active', 1)->count(),
            (int) $conn->table('kb_sqlsrv_archive')->count()
        );
    }

    // 19. update from and delete join
    public function testUpdateFromAndDeleteJoin(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $conn->execute("UPDATE o SET status = 'vip' FROM kb_sqlsrv_order o JOIN kb_sqlsrv_user u ON u.id = o.user_id WHERE u.name = 'alpha'");
        $this->assertSame(2, (int) $conn->table('kb_sqlsrv_order')->where('status', 'vip')->count());

        $conn->execute('CREATE TABLE kb_sqlsrv_delete_mark (user_id INT NOT NULL)');
        $conn->execute('INSERT INTO kb_sqlsrv_delete_mark VALUES (2)');
        $conn->execute('DELETE o FROM kb_sqlsrv_order o JOIN kb_sqlsrv_delete_mark m ON m.user_id = o.user_id');
        $this->assertSame(0, (int) $conn->table('kb_sqlsrv_order')->where('user_id', 2)->count());
    }

    // 20. large text and varbinary support
    public function testLargeTextAndVarbinarySupport(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TABLE kb_sqlsrv_misc (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            value_text NVARCHAR(100),
            value_large NVARCHAR(MAX),
            value_blob VARBINARY(MAX) NULL
        )');

        $largeText = str_repeat('kingbase-sqlsrv-', 128);
        $binary = "\x00\x01kingbase\x00\xff";

        $conn->table('kb_sqlsrv_misc')->insert([
            'value_text' => 'blob-row',
            'value_large' => $largeText,
            'value_blob' => $binary,
        ]);

        $row = $conn->table('kb_sqlsrv_misc')->where('id', 1)->find();
        $this->assertSame(strlen($largeText), strlen((string) $row['value_large']));
        $this->assertSame($binary, $this->binaryValue($row['value_blob']));
    }

    // 21. view support
    public function testViewSupport(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->execute('CREATE VIEW kb_sqlsrv_active_users AS SELECT id, name FROM kb_sqlsrv_user WHERE active = 1');
        $rows = $conn->query('SELECT * FROM kb_sqlsrv_active_users');
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    // 22. transaction rollback
    public function testTransactionRollback(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_sqlsrv_user')->insert(['name' => 'rollback_user', 'age' => 99]);
            $conn->rollback();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(0, (int) $conn->table('kb_sqlsrv_user')->where('name', 'rollback_user')->count());
    }

    // 23. transaction commit
    public function testTransactionCommit(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_sqlsrv_user')->insert(['name' => 'commit_user', 'age' => 26]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(1, (int) $conn->table('kb_sqlsrv_user')->where('name', 'commit_user')->count());
    }

    // 24. for update lock syntax
    public function testForUpdateLockSyntax(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $rows = $conn->query('SELECT * FROM kb_sqlsrv_user WHERE id = 1 FOR UPDATE');
            $this->assertCount(1, $rows);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    // 25. SQL error recovery
    public function testSqlErrorRecovery(): void
    {
        $conn = $this->conn();

        try {
            $conn->execute('SELECT * FROM kb_sqlsrv_nonexistent_table');
        } catch (\Throwable $e) {
        }

        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
    }

    // 26. final cleanup (verify no residual objects)
    public function testFinalCleanup(): void
    {
        $conn = $this->conn();
        $this->dropIfExists('DROP TABLE kb_sqlsrv_composite');
        $this->dropIfExists('DROP VIEW kb_sqlsrv_active_users');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_archive');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_delete_mark');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_order');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_user');
        $this->dropIfExists('DROP TABLE kb_sqlsrv_misc');

        $tables = $conn->getTables();
        $this->assertNotContains('kb_sqlsrv_user', $tables);
    }
}

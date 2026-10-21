<?php

declare(strict_types=1);

namespace tests\kingbase;

use PDO;
use think\facade\Db;
use UnexpectedValueException;

/**
 * PHPUnit tests for KingbaseES MySQL compatibility mode.
 *
 * Mirrors the 30 test items from tests/kingbase/mysql_compat.php and the
 * testing document ThinkPHP3.2-ThinkPHP8-KingbaseES测试文档_V1.0.md.
 */
final class KingbaseMysqlTest extends KingbaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureConnection('kingbase_mysql', [
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

    protected function setUp(): void
    {
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_composite');
        $this->dropIfExists('DROP VIEW IF EXISTS kb_mysql_active_users');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_archive');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_delete_mark');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_order');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_user');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_misc');
        $this->dropIfExists('DROP TEMPORARY TABLE IF EXISTS kb_mysql_temp');
    }

    protected function tearDown(): void
    {
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_composite');
        $this->dropIfExists('DROP VIEW IF EXISTS kb_mysql_active_users');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_archive');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_delete_mark');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_order');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_user');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_misc');
        $this->dropIfExists('DROP TEMPORARY TABLE IF EXISTS kb_mysql_temp');
    }

    // 1. connection and mysql_json extension
    public function testConnectionAndMysqlJsonExtension(): void
    {
        $conn = $this->conn();
        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);

        try {
            $conn->execute('CREATE EXTENSION IF NOT EXISTS mysql_json');
        } catch (\Throwable $e) {
            $this->markTestSkipped('mysql_json extension not available: ' . $e->getMessage());
        }
    }

    // 2. pdo mode schema and reconnect
    public function testPdoModeSchemaAndReconnect(): void
    {
        $conn = $this->conn();
        $this->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)));
        $this->assertSame('mysql', $conn->getKingbaseMode());
        $this->assertSame('public', strtolower($conn->getKingbaseSchema()));

        $conn->close();
        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
        $this->assertSame('public', strtolower($conn->getKingbaseSchema()));
    }

    // 3. compatibility mode mismatch is rejected
    public function testCompatibilityModeMismatchIsRejected(): void
    {
        $config = $this->conn()->getConfig();
        $config['type'] = 'kingbaseOracle';
        $config['schema'] = '';
        $businessSqlExecuted = false;

        self::verboseLog('ACTION', 'Attempting to use an explicit Oracle connector against a MySQL-mode instance', [
            'configured_type' => $config['type'],
            'actual_database_mode' => 'mysql',
        ]);

        try {
            Db::connect($config, true)->query('SELECT 1 FROM dual');
            $businessSqlExecuted = true;
            $this->fail('connector accepted the wrong KES compatibility mode');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('mode mismatch', $e->getMessage());
            $this->assertFalse($businessSqlExecuted, 'business SQL was executed with a mismatched connector');
            self::verboseLog('ASSERT', 'Mode mismatch exception captured', [
                'configured_type' => $config['type'],
                'actual_database_mode' => 'mysql',
                'exception' => $e->getMessage(),
            ]);
            self::verboseLog('PASS', 'Mode mismatch rejected before business SQL execution');
        }
    }

    // 4. ddl and mysql-compatible types
    public function testDdlAndMysqlCompatibleTypes(): void
    {
        $conn = $this->conn();
        $conn->execute("CREATE TABLE kb_mysql_user (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            age INT DEFAULT 0,
            score DECIMAL(10,2) DEFAULT 0,
            active TINYINT DEFAULT 1,
            tags VARCHAR(100) NULL,
            bio TEXT NULL,
            profile JSON NULL,
            created_at TIMESTAMP NULL
        )");
        $conn->execute("CREATE TABLE kb_mysql_order (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NULL
        )");
        $conn->execute("CREATE TABLE kb_mysql_misc (
            id INT AUTO_INCREMENT PRIMARY KEY,
            value_text VARCHAR(100),
            value_blob BLOB NULL
        )");

        $tables = $conn->getTables();
        $this->assertContains('kb_mysql_user', $tables);
    }

    // 5. insertGetId and basic CRUD
    public function testInsertGetIdAndBasicCrud(): void
    {
        $this->testDdlAndMysqlCompatibleTypes();
        $conn = $this->conn();

        $id = $conn->table('kb_mysql_user')->insertGetId([
            'name' => 'alpha',
            'age' => 18,
            'score' => '98.50',
            'tags' => 'red,blue',
            'bio' => 'first user',
            'profile' => json_encode(['city' => 'beijing', 'level' => 1], JSON_UNESCAPED_UNICODE),
            'created_at' => Db::raw('CURRENT_TIMESTAMP'),
        ]);
        $this->assertSame(1, (int) $id);

        $conn->table('kb_mysql_user')->where('id', $id)->update(['age' => 19]);
        $row = $conn->table('kb_mysql_user')->where('id', $id)->find();
        $this->assertSame(19, (int) $row['age']);
    }

    // 6. bulk insert and column retrieval
    public function testBulkInsertAndColumnRetrieval(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->table('kb_mysql_user')->insertAll([
            ['name' => 'beta', 'age' => 20, 'score' => '88.00', 'tags' => 'green,blue', 'profile' => json_encode(['city' => 'shanghai', 'level' => 2])],
            ['name' => 'gamma', 'age' => 22, 'score' => '77.25', 'tags' => 'red,yellow', 'profile' => json_encode(['city' => 'beijing', 'level' => 3])],
        ]);

        $names = $conn->table('kb_mysql_user')->order('id', 'asc')->column('name');
        $this->assertSame(['alpha', 'beta', 'gamma'], array_values($names));
    }

    // 7. metadata tables and fields
    public function testMetadataTablesAndFields(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $tables = $conn->getTables();
        $this->assertContains('kb_mysql_user', $tables);

        $fields = $conn->getFields('kb_mysql_user');
        $this->assertArrayHasKey('id', $fields);
        $this->assertTrue($fields['id']['primary']);
        $this->assertTrue($fields['id']['autoinc']);
    }

    // 8. composite primary key metadata
    public function testCompositePrimaryKeyMetadata(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TABLE kb_mysql_composite (
            tenant_id INT NOT NULL,
            item_id INT NOT NULL,
            label VARCHAR(50) UNIQUE,
            PRIMARY KEY (tenant_id, item_id)
        )');

        $fields = $conn->getFields('kb_mysql_composite');
        $this->assertTrue($fields['tenant_id']['primary']);
        $this->assertTrue($fields['item_id']['primary']);
        $this->assertFalse($fields['label']['primary']);
    }

    // 9. where operators
    public function testWhereOperators(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $this->assertSame(3, (int) $conn->table('kb_mysql_user')->where('age', '>', 18)->count());
        $this->assertSame(1, (int) $conn->table('kb_mysql_user')->where('name', 'like', 'a%')->count());
        $this->assertSame(2, (int) $conn->table('kb_mysql_user')->whereBetween('age', [18, 20])->count());
        $this->assertSame(2, (int) $conn->table('kb_mysql_user')->whereIn('name', ['alpha', 'gamma'])->count());
        $this->assertSame(2, (int) $conn->table('kb_mysql_user')->whereNull('bio')->count());
    }

    // 10. regexp and find_in_set
    public function testRegexpAndFindInSet(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $this->assertSame(1, (int) $conn->table('kb_mysql_user')->where('name', 'regexp', '^a')->count());
        $this->assertSame(2, (int) $conn->table('kb_mysql_user')->where('tags', 'find in set', 'red')->count());
    }

    // 11. duplicate and replace
    public function testDuplicateAndReplace(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->table('kb_mysql_user')->duplicate(['age'])->insert([
            'name' => 'alpha',
            'age' => 30,
        ]);
        $age = $conn->table('kb_mysql_user')->where('name', 'alpha')->value('age');
        $this->assertSame(30, (int) $age);

        $conn->table('kb_mysql_user')->replace()->insert([
            'id' => 3,
            'name' => 'gamma',
            'age' => 23,
            'score' => '79.00',
        ]);
        $age = $conn->table('kb_mysql_user')->where('name', 'gamma')->value('age');
        $this->assertSame(23, (int) $age);
    }

    // 12. json extract
    public function testJsonExtract(): void
    {
        $this->testDuplicateAndReplace();
        $conn = $this->conn();

        $rows = $conn->table('kb_mysql_user')->where('profile->city', 'beijing')->select()->toArray();
        $this->assertCount(1, $rows);
    }

    // 13. json set
    public function testJsonSet(): void
    {
        $this->testDuplicateAndReplace();
        $conn = $this->conn();

        $conn->table('kb_mysql_user')->where('name', 'alpha')->update(['profile->level' => 9]);
        $level = $conn->table('kb_mysql_user')->where('name', 'alpha')->value('profile->level');
        $this->assertSame('9', (string) $level);
    }

    // 14. alter table index and defaults
    public function testAlterTableIndexAndDefaults(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute("ALTER TABLE kb_mysql_user ADD COLUMN status_flag VARCHAR(20) DEFAULT 'new'");
        $conn->execute('CREATE INDEX idx_kb_mysql_user_age ON kb_mysql_user(age)');

        $conn->table('kb_mysql_user')->insert([
            'name' => 'delta', 'age' => 24, 'score' => '66.00', 'tags' => 'black',
        ]);

        $status = $conn->table('kb_mysql_user')->where('name', 'delta')->value('status_flag');
        $this->assertSame('new', (string) $status);

        $fields = $conn->getFields('kb_mysql_user');
        $this->assertArrayHasKey('status_flag', $fields);
    }

    // 15. unique constraint violation
    public function testUniqueConstraintViolation(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $this->expectException(\Throwable::class);
        $conn->table('kb_mysql_user')->insert(['name' => 'alpha', 'age' => 25]);
    }

    // 16. join query
    public function testJoinQuery(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->table('kb_mysql_order')->insertAll([
            ['user_id' => 1, 'amount' => '10.50', 'status' => 'paid', 'created_at' => Db::raw('CURRENT_TIMESTAMP')],
            ['user_id' => 1, 'amount' => '20.00', 'status' => 'paid', 'created_at' => Db::raw('CURRENT_TIMESTAMP')],
            ['user_id' => 2, 'amount' => '15.00', 'status' => 'new', 'created_at' => Db::raw('CURRENT_TIMESTAMP')],
        ]);

        $rows = $conn->table('kb_mysql_user')
            ->alias('u')
            ->join('kb_mysql_order o', 'u.id = o.user_id')
            ->where('o.status', 'paid')
            ->field('u.name,o.amount')
            ->select()
            ->toArray();
        $this->assertCount(2, $rows);
    }

    // 17. aggregate group having
    public function testAggregateGroupHaving(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $sum = $conn->table('kb_mysql_order')->where('status', 'paid')->sum('amount');
        $this->assertSame('30.50', number_format((float) $sum, 2, '.', ''));

        $rows = $conn->table('kb_mysql_order')
            ->field('user_id,COUNT(*) AS order_count')
            ->group('user_id')
            ->having('COUNT(*) >= 2')
            ->select()
            ->toArray();
        $this->assertCount(1, $rows);
    }

    // 18. union and subquery
    public function testUnionAndSubquery(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $unionRows = $conn->query("SELECT name FROM kb_mysql_user WHERE name = 'alpha' UNION ALL SELECT name FROM kb_mysql_user WHERE name = 'beta'");
        $this->assertCount(2, $unionRows);

        $subRows = $conn->query("SELECT * FROM kb_mysql_user WHERE id IN (SELECT user_id FROM kb_mysql_order WHERE status = 'paid')");
        $this->assertCount(1, $subRows);
    }

    // 19. pagination and random order
    public function testPaginationAndRandomOrder(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $rows = $conn->table('kb_mysql_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
        $this->assertCount(2, $rows);

        $rows = $conn->table('kb_mysql_user')->orderRand()->limit(2)->select()->toArray();
        $this->assertCount(2, $rows);
    }

    // 20. raw mysql functions
    public function testRawMysqlFunctions(): void
    {
        $conn = $this->conn();
        $row = $conn->query("SELECT
            CONCAT('a', 'b') AS c1,
            SUBSTRING('abc', 2, 1) AS c2,
            LENGTH('abc') AS c3,
            DATE_FORMAT(CURRENT_TIMESTAMP, '%Y') AS c4,
            IFNULL(NULL, 'fallback') AS c5,
            COALESCE(NULL, 'coalesce') AS c6,
            CASE WHEN 2 > 1 THEN 'yes' ELSE 'no' END AS c7,
            LEFT('abcdef', 3) AS c8,
            RIGHT('abcdef', 2) AS c9
        ");

        $this->assertSame('ab', (string) $row[0]['c1']);
        $this->assertSame('b', (string) $row[0]['c2']);
        $this->assertSame(3, (int) $row[0]['c3']);
        $this->assertGreaterThanOrEqual(2026, (int) $row[0]['c4']);
        $this->assertSame('fallback', (string) $row[0]['c5']);
        $this->assertSame('coalesce', (string) $row[0]['c6']);
        $this->assertSame('yes', (string) $row[0]['c7']);
        $this->assertSame('abc', (string) $row[0]['c8']);
        $this->assertSame('ef', (string) $row[0]['c9']);
    }

    // 21. insert select and archive table
    public function testInsertSelectAndArchiveTable(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute('CREATE TABLE kb_mysql_archive (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(50) NOT NULL,
            archived_at TIMESTAMP NULL
        )');

        $affected = $conn->execute("INSERT INTO kb_mysql_archive (user_id, name, archived_at) SELECT id, name, CURRENT_TIMESTAMP FROM kb_mysql_user WHERE active = 1");
        $this->assertGreaterThanOrEqual(1, $affected);
        $this->assertSame(
            (int) $conn->table('kb_mysql_user')->where('active', 1)->count(),
            (int) $conn->table('kb_mysql_archive')->count()
        );
    }

    // 22. multi table update and delete
    public function testMultiTableUpdateAndDelete(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $conn->execute("UPDATE kb_mysql_order o JOIN kb_mysql_user u ON u.id = o.user_id SET o.status = 'vip' WHERE u.name = 'alpha'");
        $this->assertSame(2, (int) $conn->table('kb_mysql_order')->where('status', 'vip')->count());

        $conn->execute('CREATE TABLE kb_mysql_delete_mark (user_id INT NOT NULL)');
        $conn->execute('INSERT INTO kb_mysql_delete_mark VALUES (2)');
        $conn->execute('DELETE o FROM kb_mysql_order o JOIN kb_mysql_delete_mark m ON m.user_id = o.user_id');
        $this->assertSame(0, (int) $conn->table('kb_mysql_order')->where('user_id', 2)->count());
    }

    // 23. blob and large text support
    public function testBlobAndLargeTextSupport(): void
    {
        $conn = $this->conn();
        $conn->execute("CREATE TABLE kb_mysql_misc (
            id INT AUTO_INCREMENT PRIMARY KEY,
            value_text VARCHAR(100),
            value_blob BLOB NULL
        )");

        $largeText = str_repeat('kingbase-mysql-', 128);
        $binary = "\x00\x01kingbase\x00\xff";

        $conn->execute('ALTER TABLE kb_mysql_misc ADD COLUMN value_large TEXT NULL');
        $conn->table('kb_mysql_misc')->insert([
            'value_text' => 'blob-row',
            'value_large' => $largeText,
            'value_blob' => $binary,
        ]);

        $row = $conn->table('kb_mysql_misc')->where('id', 1)->find();
        $this->assertSame(strlen($largeText), strlen((string) $row['value_large']));
        $this->assertSame($binary, $this->binaryValue($row['value_blob']));
    }

    // 24. view support
    public function testViewSupport(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute("CREATE VIEW kb_mysql_active_users AS SELECT id, name FROM kb_mysql_user WHERE active = 1");
        $rows = $conn->query('SELECT * FROM kb_mysql_active_users');
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    // 25. temporary table support
    public function testTemporaryTableSupport(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TEMPORARY TABLE kb_mysql_temp (id INT, name VARCHAR(20))');
        $conn->execute("INSERT INTO kb_mysql_temp VALUES (1, 'tmp')");
        $rows = $conn->query('SELECT * FROM kb_mysql_temp');
        $this->assertSame('tmp', (string) $rows[0]['name']);
    }

    // 26. transaction rollback
    public function testTransactionRollback(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_mysql_user')->insert(['name' => 'rollback_user', 'age' => 99]);
            $conn->rollback();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(0, (int) $conn->table('kb_mysql_user')->where('name', 'rollback_user')->count());
    }

    // 27. transaction commit
    public function testTransactionCommit(): void
    {
        $this->testBulkInsertAndColumnRetrieval();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_mysql_user')->insert(['name' => 'commit_user', 'age' => 26]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(1, (int) $conn->table('kb_mysql_user')->where('name', 'commit_user')->count());
    }

    // 28. for update lock syntax
    public function testForUpdateLockSyntax(): void
    {
        $this->testInsertGetIdAndBasicCrud();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $rows = $conn->query('SELECT * FROM kb_mysql_user WHERE id = 1 FOR UPDATE');
            $this->assertCount(1, $rows);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    // 29. SQL error recovery
    public function testSqlErrorRecovery(): void
    {
        $conn = $this->conn();

        try {
            $conn->execute('SELECT * FROM kb_mysql_nonexistent_table');
        } catch (\Throwable $e) {
        }

        $rows = $conn->query('SELECT 1 AS ok');
        $this->assertSame(1, (int) $rows[0]['ok']);
    }

    // 30. final cleanup (verify no residual objects)
    public function testFinalCleanup(): void
    {
        $conn = $this->conn();
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_composite');
        $this->dropIfExists('DROP VIEW IF EXISTS kb_mysql_active_users');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_archive');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_delete_mark');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_order');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_user');
        $this->dropIfExists('DROP TABLE IF EXISTS kb_mysql_misc');
        $this->dropIfExists('DROP TEMPORARY TABLE IF EXISTS kb_mysql_temp');

        $tables = $conn->getTables();
        $this->assertNotContains('kb_mysql_user', $tables);
    }
}

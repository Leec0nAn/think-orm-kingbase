<?php

declare(strict_types=1);

namespace tests\kingbase;

use PDO;
use think\facade\Db;
use UnexpectedValueException;

/**
 * PHPUnit tests for KingbaseES Oracle compatibility mode.
 *
 * Mirrors the 23 test items from tests/kingbase/oracle_compat.php and the
 * testing document ThinkPHP3.2-ThinkPHP8-KingbaseES测试文档_V1.0.md.
 */
final class KingbaseOracleTest extends KingbaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureConnection('kingbase_oracle', [
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

    protected function setUp(): void
    {
        $this->dropIfExists('DROP TABLE KB_ORACLE_COMPOSITE');
        $this->dropIfExists('DROP VIEW KB_ORACLE_ACTIVE_USERS');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ARCHIVE');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ORDER');
        $this->dropIfExists('DROP TABLE KB_ORACLE_USER');
        $this->dropIfExists('DROP SEQUENCE KB_ORACLE_USER_SEQ');
    }

    protected function tearDown(): void
    {
        $this->dropIfExists('DROP TABLE KB_ORACLE_COMPOSITE');
        $this->dropIfExists('DROP VIEW KB_ORACLE_ACTIVE_USERS');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ARCHIVE');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ORDER');
        $this->dropIfExists('DROP TABLE KB_ORACLE_USER');
        $this->dropIfExists('DROP SEQUENCE KB_ORACLE_USER_SEQ');
    }

    // 1. connection and dual
    public function testConnectionAndDual(): void
    {
        $conn = $this->conn();
        $rows = $conn->query('SELECT 1 AS ok FROM dual');
        $this->assertSame(1, (int) $rows[0]['ok']);
    }

    // 2. pdo mode schema and reconnect
    public function testPdoModeSchemaAndReconnect(): void
    {
        $conn = $this->conn();
        $this->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)));
        $this->assertSame('oracle', $conn->getKingbaseMode());

        $schema = $conn->getKingbaseSchema();
        $this->assertNotSame('', $schema);

        $conn->close();
        $rows = $conn->query('SELECT 1 AS ok FROM dual');
        $this->assertSame(1, (int) $rows[0]['ok']);
        $this->assertSame(strtolower($schema), strtolower($conn->getKingbaseSchema()));
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

    // 4. ddl sequence and oracle-compatible types
    public function testDdlSequenceAndOracleCompatibleTypes(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE SEQUENCE KB_ORACLE_USER_SEQ START WITH 1 INCREMENT BY 1');
        $conn->execute('CREATE TABLE KB_ORACLE_USER (
            ID NUMBER(10) NOT NULL PRIMARY KEY,
            NAME VARCHAR2(50) UNIQUE,
            AGE NUMBER(10),
            SCORE NUMBER(10,2),
            ACTIVE NUMBER(1) DEFAULT 1,
            BIO CLOB,
            DATA_BLOB BLOB,
            CREATED_AT DATE DEFAULT SYSDATE
        )');
        $conn->execute('CREATE TABLE KB_ORACLE_ORDER (
            ID NUMBER(10) NOT NULL PRIMARY KEY,
            USER_ID NUMBER(10) NOT NULL,
            AMOUNT NUMBER(10,2) NOT NULL,
            STATUS VARCHAR2(20) NOT NULL,
            CREATED_AT TIMESTAMP DEFAULT SYSTIMESTAMP
        )');

        $tables = $conn->getTables();
        $this->assertContains('KB_ORACLE_USER', $tables);
    }

    // 5. insertGetId with sequence and basic CRUD
    public function testInsertGetIdWithSequenceAndBasicCrud(): void
    {
        $this->testDdlSequenceAndOracleCompatibleTypes();
        $conn = $this->conn();

        $id = $conn->table('kb_oracle_user')->sequence('kb_oracle_user_seq')->insertGetId([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'alpha',
            'age' => 18,
            'score' => '98.50',
            'bio' => 'first user',
            'data_blob' => Db::raw('EMPTY_BLOB()'),
            'created_at' => Db::raw('SYSDATE'),
        ]);
        $this->assertSame(1, (int) $id);

        $conn->table('kb_oracle_user')->where('id', $id)->update(['age' => 19]);
        $row = $conn->table('kb_oracle_user')->where('id', $id)->find();
        $this->assertSame(19, (int) $row['age']);
    }

    // 6. multiple inserts and column retrieval
    public function testMultipleInsertsAndColumnRetrieval(): void
    {
        $this->testInsertGetIdWithSequenceAndBasicCrud();
        $conn = $this->conn();

        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'beta', 'age' => 20, 'score' => '88.00', 'bio' => 'second user',
            'data_blob' => Db::raw('EMPTY_BLOB()'),
        ]);
        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'gamma', 'age' => 22, 'score' => '77.25',
            'data_blob' => Db::raw('EMPTY_BLOB()'),
        ]);

        $names = $conn->table('kb_oracle_user')->order('id', 'asc')->column('name');
        $this->assertSame(['alpha', 'beta', 'gamma'], array_values($names));
    }

    // 7. metadata tables and fields
    public function testMetadataTablesAndFields(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $tables = $conn->getTables();
        $this->assertContains('KB_ORACLE_USER', $tables);

        $fields = $conn->getFields('kb_oracle_user');
        $this->assertArrayHasKey('id', $fields);
        $this->assertTrue($fields['id']['primary']);
        // 表定义为普通主键；写入时显式使用 NEXTVAL 不代表该列有默认自增行为。
        $this->assertFalse($fields['id']['autoinc']);
    }

    // 8. composite primary key metadata
    public function testCompositePrimaryKeyMetadata(): void
    {
        $conn = $this->conn();
        $conn->execute('CREATE TABLE KB_ORACLE_COMPOSITE (
            TENANT_ID NUMBER(10) NOT NULL,
            ITEM_ID NUMBER(10) NOT NULL,
            LABEL VARCHAR2(50) UNIQUE,
            PRIMARY KEY (TENANT_ID, ITEM_ID)
        )');

        $fields = $conn->getFields('kb_oracle_composite');
        $this->assertTrue($fields['tenant_id']['primary']);
        $this->assertTrue($fields['item_id']['primary']);
        $this->assertFalse($fields['label']['primary']);
    }

    // 9. where operators and regexp
    public function testWhereOperatorsAndRegexp(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $this->assertSame(3, (int) $conn->table('kb_oracle_user')->where('age', '>', 18)->count());
        $this->assertSame(1, (int) $conn->table('kb_oracle_user')->where('name', 'like', 'a%')->count());
        $this->assertSame(2, (int) $conn->table('kb_oracle_user')->whereBetween('age', [18, 20])->count());
        $this->assertSame(2, (int) $conn->table('kb_oracle_user')->whereIn('name', ['alpha', 'gamma'])->count());

        $rows = $conn->query("SELECT * FROM KB_ORACLE_USER WHERE REGEXP_LIKE(NAME, '^a')");
        $this->assertCount(1, $rows);
    }

    // 10. unique constraint violation
    public function testUniqueConstraintViolation(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $this->expectException(\Throwable::class);
        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'alpha', 'age' => 25,
        ]);
    }

    // 11. join query
    public function testJoinQuery(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute("INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (1, 1, 10.50, 'paid')");
        $conn->execute("INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (2, 1, 20.00, 'paid')");
        $conn->execute("INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (3, 2, 15.00, 'new')");

        $rows = $conn->table('kb_oracle_user')
            ->alias('u')
            ->join('kb_oracle_order o', 'u.id = o.user_id')
            ->where('o.status', 'paid')
            ->field('u.name,o.amount')
            ->select()
            ->toArray();
        $this->assertCount(2, $rows);
    }

    // 12. aggregate group having
    public function testAggregateGroupHaving(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $sum = $conn->table('kb_oracle_order')->where('status', 'paid')->sum('amount');
        $this->assertSame('30.50', number_format((float) $sum, 2, '.', ''));

        $rows = $conn->table('kb_oracle_order')
            ->field('user_id,COUNT(*) AS order_count')
            ->group('user_id')
            ->having('COUNT(*) >= 2')
            ->select()
            ->toArray();
        $this->assertCount(1, $rows);
    }

    // 13. union and subquery
    public function testUnionAndSubquery(): void
    {
        $this->testJoinQuery();
        $conn = $this->conn();

        $unionRows = $conn->query("SELECT NAME FROM KB_ORACLE_USER WHERE NAME = 'alpha' UNION ALL SELECT NAME FROM KB_ORACLE_USER WHERE NAME = 'beta'");
        $this->assertCount(2, $unionRows);

        $subRows = $conn->query("SELECT * FROM KB_ORACLE_USER WHERE ID IN (SELECT USER_ID FROM KB_ORACLE_ORDER WHERE STATUS = 'paid')");
        $this->assertCount(1, $subRows);
    }

    // 14. native pagination and random order
    public function testRownumPaginationAndRandomOrder(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $rows = $conn->table('kb_oracle_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
        $this->assertCount(2, $rows);

        $rows = $conn->table('kb_oracle_user')->orderRand()->limit(2)->select()->toArray();
        $this->assertCount(2, $rows);
    }

    // 15. raw oracle functions
    public function testRawOracleFunctions(): void
    {
        $conn = $this->conn();
        $row = $conn->query("SELECT
            NVL(NULL, 'fallback') AS c1,
            NVL2('x', 'not-null', 'null') AS c2,
            DECODE(1, 1, 'yes', 'no') AS c3,
            TO_CHAR(SYSDATE, 'YYYY') AS c4,
            SUBSTR('abcdef', 2, 3) AS c5,
            LENGTH('abc') AS c6,
            INSTR('abcdef', 'cd') AS c7,
            ADD_MONTHS(DATE '2026-01-01', 1) AS c8
        FROM dual");

        $this->assertSame('fallback', (string) $row[0]['c1']);
        $this->assertSame('not-null', (string) $row[0]['c2']);
        $this->assertSame('yes', (string) $row[0]['c3']);
        $this->assertGreaterThanOrEqual(2026, (int) $row[0]['c4']);
        $this->assertSame('bcd', (string) $row[0]['c5']);
        $this->assertSame(3, (int) $row[0]['c6']);
        $this->assertSame(3, (int) $row[0]['c7']);
        $this->assertNotEmpty($row[0]['c8']);
    }

    // 16. insert select and archive table
    public function testInsertSelectAndArchiveTable(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute('CREATE TABLE KB_ORACLE_ARCHIVE (
            ID NUMBER(10) NOT NULL PRIMARY KEY,
            USER_ID NUMBER(10) NOT NULL,
            NAME VARCHAR2(50),
            ARCHIVED_AT DATE
        )');

        $affected = $conn->execute("INSERT INTO KB_ORACLE_ARCHIVE (ID, USER_ID, NAME, ARCHIVED_AT) SELECT ROWNUM, ID, NAME, SYSDATE FROM KB_ORACLE_USER WHERE ACTIVE = 1");
        $this->assertGreaterThanOrEqual(1, $affected);
        $this->assertSame(
            (int) $conn->table('kb_oracle_user')->where('active', 1)->count(),
            (int) $conn->table('kb_oracle_archive')->count()
        );
    }

    // 17. clob and blob support
    public function testClobAndBlobSupport(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $largeText = str_repeat('kingbase-oracle-', 128);
        $binary = "\x00\x01kingbase\x00\xff";

        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'delta', 'age' => 24,
            'bio' => $largeText, 'data_blob' => $binary,
        ]);

        $row = $conn->table('kb_oracle_user')->where('name', 'delta')->find();
        $this->assertSame(strlen($largeText), strlen((string) $row['bio']));
        $this->assertSame($binary, $this->binaryValue($row['data_blob']));
    }

    // 18. view support
    public function testViewSupport(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $conn->execute('CREATE VIEW KB_ORACLE_ACTIVE_USERS AS SELECT ID, NAME FROM KB_ORACLE_USER WHERE ACTIVE = 1');
        $rows = $conn->query('SELECT * FROM KB_ORACLE_ACTIVE_USERS');
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    // 19. transaction rollback
    public function testTransactionRollback(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_oracle_user')->insert([
                'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
                'name' => 'rollback_user', 'age' => 99,
            ]);
            $conn->rollback();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(0, (int) $conn->table('kb_oracle_user')->where('name', 'rollback_user')->count());
    }

    // 20. transaction commit
    public function testTransactionCommit(): void
    {
        $this->testMultipleInsertsAndColumnRetrieval();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $conn->table('kb_oracle_user')->insert([
                'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
                'name' => 'commit_user', 'age' => 26,
            ]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->assertSame(1, (int) $conn->table('kb_oracle_user')->where('name', 'commit_user')->count());
    }

    // 21. for update lock syntax
    public function testForUpdateLockSyntax(): void
    {
        $this->testInsertGetIdWithSequenceAndBasicCrud();
        $conn = $this->conn();

        $conn->startTrans();
        try {
            $rows = $conn->query('SELECT * FROM KB_ORACLE_USER WHERE ID = 1 FOR UPDATE');
            $this->assertCount(1, $rows);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    // 22. SQL error recovery
    public function testSqlErrorRecovery(): void
    {
        $conn = $this->conn();

        try {
            $conn->execute('SELECT * FROM KB_ORACLE_NONEXISTENT_TABLE');
        } catch (\Throwable $e) {
        }

        $rows = $conn->query('SELECT 1 AS ok FROM dual');
        $this->assertSame(1, (int) $rows[0]['ok']);
    }

    // 23. final cleanup (verify no residual objects)
    public function testFinalCleanup(): void
    {
        $conn = $this->conn();
        $this->dropIfExists('DROP TABLE KB_ORACLE_COMPOSITE');
        $this->dropIfExists('DROP VIEW KB_ORACLE_ACTIVE_USERS');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ARCHIVE');
        $this->dropIfExists('DROP TABLE KB_ORACLE_ORDER');
        $this->dropIfExists('DROP TABLE KB_ORACLE_USER');
        $this->dropIfExists('DROP SEQUENCE KB_ORACLE_USER_SEQ');

        $tables = $conn->getTables();
        $this->assertNotContains('KB_ORACLE_USER', $tables);
    }
}

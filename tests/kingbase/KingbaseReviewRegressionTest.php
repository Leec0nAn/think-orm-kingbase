<?php

declare(strict_types=1);

namespace tests\kingbase;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\DbManager;
use think\db\Raw;

/** 实库回归：覆盖代码 Review 发现的五类边界缺陷。 */
final class KingbaseReviewRegressionTest extends TestCase
{
    private array $connections = [];
    private array $tables = [];
    private array $sequences = [];

    public static function modes(): array
    {
        return [['mysql'], ['oracle'], ['sqlsrv']];
    }

    private function connection(string $mode)
    {
        $prefix = 'KINGBASE_' . strtoupper($mode) . '_';
        $port = ['mysql' => '54321', 'oracle' => '54322', 'sqlsrv' => '54323'][$mode];
        $schema = ['mysql' => 'public', 'oracle' => '', 'sqlsrv' => 'dbo'][$mode];
        $env = static function (string $key, string $default = ''): string {
            $value = getenv($key);
            return false === $value ? $default : $value;
        };
        // 本套件只测试独立 Query 连接；禁止注册全局 Model::maker，避免覆盖后续模型套件的管理器。
        $manager = new class extends DbManager {
            protected function modelMaker() {}
        };
        $conn = $manager->connect([
            'type' => 'kingbase' . ucfirst($mode),
            'hostname' => $env($prefix . 'HOST', '127.0.0.1'),
            'hostport' => $env($prefix . 'PORT', $port),
            'database' => $env($prefix . 'DATABASE', 'test'),
            'username' => $env($prefix . 'USERNAME', 'system'),
            'password' => $env($prefix . 'PASSWORD'),
            'schema' => $env($prefix . 'SCHEMA', $schema),
            'fields_cache' => false,
            'trigger_sql' => false,
        ]);
        $this->connections[] = $conn;
        return $conn;
    }

    private function table($conn, string $definition = 'id INT PRIMARY KEY, name VARCHAR(40), age INT'): string
    {
        $table = 'kb_review_' . bin2hex(random_bytes(6));
        $conn->execute('CREATE TABLE ' . $table . ' (' . $definition . ')');
        $this->tables[] = [$conn, $table];
        return $table;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        foreach (array_reverse($this->tables) as [$conn, $table]) {
            $conn->execute('DROP TABLE ' . $table);
        }
        foreach ($this->sequences as [$conn, $sequence]) {
            $conn->execute('DROP SEQUENCE ' . $sequence);
        }
        foreach ($this->connections as $conn) {
            $conn->close();
        }
    }

    /** @dataProvider modes */
    public function testOrmFindAndPagedSelectActuallyLockRows(string $mode): void
    {
        $conn = $this->connection($mode);
        $table = $this->table($conn);
        $conn->table($table)->insert(['id' => 1, 'name' => 'first', 'age' => 10]);
        $conn->table($table)->insert(['id' => 2, 'name' => 'second', 'age' => 20]);
        $other = $this->connection($mode);
        // 保证失败用例也有明确时间上限。
        $other->execute("SET lock_timeout TO '300ms'");
        foreach (['find', 'page'] as $operation) {
            $conn->startTrans();
            try {
                if ('find' === $operation) {
                    $row = $conn->table($table)->where('id', 1)->lock(true)->find();
                    $target = 1;
                    $this->assertSame(1, (int) $row['id']);
                } else {
                    $rows = $conn->table($table)->order('id')->limit(1, 1)->lock(true)->select()->toArray();
                    $target = 2;
                    $this->assertSame([2], array_map('intval', array_column($rows, 'id')));
                }
                $error = null;
                try {
                    $other->table($table)->where('id', $target)->update(['age' => 99]);
                } catch (\Throwable $e) {
                    $error = $e;
                }
                $this->assertNotNull($error, $mode . ' ' . $operation . ': second connection updated a locked row');
                $this->assertMatchesRegularExpression('/lock|锁|55P03/i', $error->getMessage());
            } finally {
                $conn->rollback();
            }
            $this->assertSame(1, $other->table($table)->where('id', $target)->update(['age' => 21]));
        }
    }

    public function testSqlsrvBatchAlignsValuesByFieldName(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $this->assertSame(2, $conn->table($table)->insertAll([
            ['id' => 1, 'name' => 'alpha', 'age' => 20],
            ['age' => 30, 'name' => 'beta', 'id' => 2],
        ]));
        $row = $conn->table($table)->where('id', 2)->find();
        $this->assertNotNull($row, 'The second row must retain id=2 despite reordered keys');
        $this->assertSame('beta', $row['name']);
        $this->assertSame(30, (int) $row['age']);
    }

    public function testSqlsrvBatchRejectsDifferentFieldSetsBeforeWriting(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        foreach ([
            [['id' => 1, 'name' => 'one'], ['id' => 2, 'age' => 20]],
            [['id' => 1, 'name' => 'one'], ['id' => 2]],
        ] as $data) {
            $error = null;
            try { $conn->table($table)->insertAll($data); }
            catch (\InvalidArgumentException $e) { $error = $e; }
            $this->assertNotNull($error);
            $this->assertSame(0, (int) $conn->table($table)->count());
        }
    }

    public function testSqlsrvInnerRollbackPreservesOnlyOuterWrites(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $conn->transaction(function ($conn) use ($table): void {
            $conn->table($table)->insert(['id' => 1, 'name' => 'outer']);
            try {
                $conn->transaction(function ($conn) use ($table): void {
                    $conn->table($table)->insert(['id' => 2, 'name' => 'inner']);
                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException $e) {
                $this->assertSame('inner failure', $e->getMessage());
            }
            // 回滚一个保存点后再次进入相同层级，验证保存点可重新使用。
            $conn->transaction(function ($conn) use ($table): void {
                $conn->table($table)->insert(['id' => 3, 'name' => 'inner success']);
            });
        });
        $this->assertSame([1, 3], array_map('intval', $conn->table($table)->order('id')->column('id')));
    }

    public function testSqlsrvOuterRollbackUndoesInnerCommit(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $conn->startTrans();
        $conn->transaction(function ($conn) use ($table): void {
            $conn->table($table)->insert(['id' => 1, 'name' => 'inner']);
        });
        $conn->rollback();
        $this->assertSame(0, (int) $conn->table($table)->count());
        $conn->commit();
        $conn->rollback();
        $conn->transaction(function ($conn) use ($table): void {
            $conn->table($table)->insert(['id' => 2, 'name' => 'new transaction']);
        });
        $this->assertSame([2], array_map('intval', $conn->table($table)->column('id')));
    }

    public function testSqlsrvSelectInsertDoesNotChangeLaterPagination(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $archive = $this->table($conn);
        $conn->table($table)->insertAll([
            ['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two'], ['id' => 3, 'name' => 'three'],
        ]);
        $conn->table($table)->field(['id', 'name'])->selectInsert(['id', 'name'], $archive);
        $rows = $conn->table($table)->order('id', 'desc')->limit(1, 1)->select()->toArray();
        $this->assertSame([2], array_map('intval', array_column($rows, 'id')));
        $this->assertSame(3, (int) $conn->table($archive)->count());
    }

    /** @dataProvider modes */
    public function testLimitedSelectInsertAndLaterPagination(string $mode): void
    {
        $conn = $this->connection($mode);
        $table = $this->table($conn);
        $archive = $this->table($conn);
        foreach ([1, 2, 3] as $id) {
            $conn->table($table)->insert(['id' => $id, 'name' => 'row-' . $id, 'age' => 10 * $id]);
        }
        $this->assertSame(1, $conn->table($table)->field(['id', 'name'])->order('id')->limit(1, 1)
            ->selectInsert(['id', 'name'], $archive));
        $this->assertSame([2], array_map('intval', $conn->table($archive)->column('id')));
        $rows = $conn->table($table)->field('id')->order('id', 'desc')->limit(1)->select()->toArray();
        $this->assertSame([['id' => 3]], array_map(static fn ($row) => array_map('intval', $row), $rows));
    }

    /** @dataProvider modes */
    public function testPaginationKeepsUnionAndScalarSubquery(string $mode): void
    {
        $conn = $this->connection($mode);
        $table = $this->table($conn);
        foreach ([1, 2, 3] as $id) {
            $conn->table($table)->insert(['id' => $id, 'name' => 'row-' . $id, 'age' => 10 * $id]);
        }
        $rows = $conn->table($table)->field('id')->where('id', 1)
            ->union('SELECT id FROM ' . $table . ' WHERE id > 1')
            ->order('id')->limit(1, 1)->select()->toArray();
        $this->assertSame([2], array_map('intval', array_column($rows, 'id')));
        $subquery = $conn->table($table)->field('age')->order('id')->limit(1)->buildSql();
        $rows = $conn->query('SELECT ' . $subquery . ' AS first_age' . ('oracle' === $mode ? ' FROM dual' : ''));
        $this->assertSame(10, (int) $rows[0]['first_age']);
    }

    public function testSqlsrvConstraintFailureCanRollBackInnerTransaction(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $conn->transaction(function ($conn) use ($table): void {
            $conn->table($table)->insert(['id' => 1]);
            $error = null;
            try {
                $conn->transaction(function ($conn) use ($table): void {
                    $conn->table($table)->insert(['id' => 2]);
                    $conn->table($table)->insert(['id' => 1]);
                });
            } catch (\think\db\exception\PDOException $e) {
                $error = $e;
            }
            $this->assertNotNull($error);
            $conn->table($table)->insert(['id' => 3]);
        });
        $this->assertSame([1, 3], array_map('intval', $conn->table($table)->order('id')->column('id')));
    }

    public function testOracleManualAndCompositeKeysAreNotAutoIncrement(): void
    {
        $conn = $this->connection('oracle');
        $table = $this->table($conn, 'id VARCHAR2(40) PRIMARY KEY, name VARCHAR2(40)');
        $fields = $conn->getFields($table);
        $this->assertTrue($fields['id']['primary']);
        $this->assertFalse($fields['id']['autoinc']);
        $table = $this->table($conn, 'id INT, tenant INT, PRIMARY KEY (id, tenant)');
        $fields = $conn->getFields($table);
        $this->assertTrue($fields['id']['primary']);
        $this->assertTrue($fields['tenant']['primary']);
        $this->assertFalse($fields['id']['autoinc']);
        $this->assertFalse($fields['tenant']['autoinc']);
        $literal = $this->table($conn, "id VARCHAR2(40) DEFAULT 'order.nextval' PRIMARY KEY");
        $this->assertFalse($conn->getFields($literal)['id']['autoinc']);
    }

    public function testOracleIdentityAndDefaultSequenceAreAutoIncrement(): void
    {
        $conn = $this->connection('oracle');
        $identity = $this->table($conn, 'id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name VARCHAR2(40)');
        $this->assertTrue($conn->getFields($identity)['id']['autoinc']);
        $sequence = 'kb_review_seq_' . bin2hex(random_bytes(6));
        $conn->execute('CREATE SEQUENCE ' . $sequence);
        $this->sequences[] = [$conn, $sequence];
        $table = $this->table($conn, 'id INT DEFAULT nextval(\'"' . strtoupper($sequence) . '"\') PRIMARY KEY, name VARCHAR2(40)');
        $fields = $conn->getFields($table);
        $this->assertTrue($fields['id']['autoinc']);
        $this->assertSame(strtolower($sequence), strtolower((string) $fields['id']['sequence']));
        $this->assertSame(1, (int) $conn->table($table)->sequence($sequence)->insertGetId(['name' => 'generated']));
    }

    /** @dataProvider modes */
    public function testDdlInvalidatesPreviouslyReadSchemaInformation(string $mode): void
    {
        $conn = $this->connection($mode);
        $table = $this->table($conn);

        $this->assertArrayNotHasKey('extra_value', array_flip($conn->getTableFields($table)));
        $type = 'oracle' === $mode ? 'VARCHAR2(40)' : 'VARCHAR(40)';
        $conn->execute('ALTER TABLE ' . $table . ' ADD extra_value ' . $type);

        $this->assertContains('extra_value', $conn->getTableFields($table));
    }

    /** @dataProvider modes */
    public function testExplicitPrimaryKeyInsertDoesNotReusePreviousGeneratedId(string $mode): void
    {
        $conn = $this->connection($mode);

        if ('mysql' === $mode) {
            $generated = $this->table($conn, 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40)');
            $firstId = $conn->table($generated)->insertGetId(['name' => 'generated']);
            $manual = $this->table($conn, 'id INT PRIMARY KEY, name VARCHAR(40)');
            $manualResult = $conn->table($manual)->insertGetId(['id' => 99, 'name' => 'manual']);
        } elseif ('sqlsrv' === $mode) {
            $generated = $this->table($conn, 'id INT IDENTITY(1,1) PRIMARY KEY, name VARCHAR(40)');
            $firstId = $conn->table($generated)->insertGetId(['name' => 'generated']);
            $manual = $this->table($conn, 'id INT PRIMARY KEY, name VARCHAR(40)');
            $manualResult = $conn->table($manual)->insertGetId(['id' => 99, 'name' => 'manual']);
        } else {
            $sequence = 'kb_review_seq_' . bin2hex(random_bytes(6));
            $conn->execute('CREATE SEQUENCE ' . $sequence);
            $this->sequences[] = [$conn, $sequence];
            $generated = $this->table($conn, 'id INT PRIMARY KEY, name VARCHAR2(40)');
            $firstId = $conn->table($generated)->sequence($sequence)->insertGetId([
                'id' => new Raw(strtoupper($sequence) . '.NEXTVAL'),
                'name' => 'generated',
            ]);
            $manual = $this->table($conn, 'id INT PRIMARY KEY, name VARCHAR2(40)');
            $manualResult = $conn->table($manual)->sequence($sequence)->insertGetId([
                'id' => 99,
                'name' => 'manual',
            ]);
        }

        $this->assertNotSame('', (string) $firstId);
        $this->assertSame('', $manualResult, 'Explicit primary key insert reused a historical generated id');
        $this->assertSame(99, (int) $conn->table($manual)->where('id', 99)->value('id'));
        $this->assertSame('', $conn->table($manual)->getLastInsID());
    }

    public function testSqlsrvReturnsNonEmptyColumnComment(): void
    {
        $conn = $this->connection('sqlsrv');
        $table = $this->table($conn);
        $conn->execute("COMMENT ON COLUMN {$table}.name IS 'review comment'");

        $this->assertSame('review comment', $conn->getFields($table)['name']['comment']);
    }
}

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use think\facade\Db;

function env_value(string $name, string $default = ''): string
{
    $value = getenv($name);

    return false === $value ? $default : (string) $value;
}

function binary_value($value): string
{
    return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
}

Db::setConfig([
    'default' => 'kingbase_oracle',
    'connections' => [
        'kingbase_oracle' => [
            'type' => 'kingbaseOracle',
            'hostname' => env_value('KINGBASE_ORACLE_HOST', '127.0.0.1'),
            'hostport' => env_value('KINGBASE_ORACLE_PORT', '54322'),
            'database' => env_value('KINGBASE_ORACLE_DATABASE', 'test'),
            'username' => env_value('KINGBASE_ORACLE_USERNAME', 'system'),
            'password' => env_value('KINGBASE_ORACLE_PASSWORD'),
            'charset' => env_value('KINGBASE_ORACLE_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => env_value('KINGBASE_ORACLE_SCHEMA', ''),
            'fields_cache' => false,
            'trigger_sql' => true,
        ],
    ],
]);

final class CompatRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(private $conn)
    {
    }

    public function test(string $name, callable $callback): void
    {
        echo "\n==== {$name} ====\n";

        try {
            $callback($this->conn, $this);
            $this->passed++;
            echo "PASS {$name}\n";
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = [$name, $e->getMessage()];
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }

    public function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function assertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }

    public function assertCount(int $expected, $value, string $message): void
    {
        $actual = is_countable($value) ? count($value) : -1;
        $this->assertSame($expected, $actual, $message);
    }

    public function summary(): int
    {
        echo "\n==== summary ====\n";
        echo "passed: {$this->passed}\n";
        echo "failed: {$this->failed}\n";

        foreach ($this->failures as [$name, $message]) {
            echo "- {$name}: {$message}\n";
        }

        return $this->failed > 0 ? 1 : 0;
    }
}

$conn = Db::connect('kingbase_oracle');
$runner = new CompatRunner($conn);

$drop = function (string $sql) use ($conn): void {
    try {
        $conn->execute($sql);
    } catch (Throwable $e) {
    }
};

$runner->test('cleanup', function () use ($drop): void {
    $drop('DROP TABLE KB_ORACLE_COMPOSITE');
    $drop('DROP VIEW KB_ORACLE_ACTIVE_USERS');
    $drop('DROP TABLE KB_ORACLE_ARCHIVE');
    $drop('DROP TABLE KB_ORACLE_ORDER');
    $drop('DROP TABLE KB_ORACLE_USER');
    $drop('DROP SEQUENCE KB_ORACLE_USER_SEQ');
});

$runner->test('connection and dual', function ($conn, CompatRunner $t): void {
    $rows = $conn->query('SELECT 1 AS ok FROM dual');
    $t->assertSame(1, (int) $rows[0]['ok'], 'select dual failed');
});

$runner->test('pdo mode schema and reconnect', function ($conn, CompatRunner $t): void {
    $t->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)), 'PDO driver mismatch');
    $t->assertSame('oracle', $conn->getKingbaseMode(), 'configured mode mismatch');
    $schema = $conn->getKingbaseSchema();
    $t->assertTrue('' !== $schema, 'default schema was not discovered');

    $conn->close();
    $rows = $conn->query('SELECT 1 AS ok FROM dual');
    $t->assertSame(1, (int) $rows[0]['ok'], 'query after reconnect failed');
    $t->assertSame(strtolower($schema), strtolower($conn->getKingbaseSchema()), 'schema changed after reconnect');
});

$runner->test('compatibility mode mismatch is rejected', function ($conn, CompatRunner $t): void {
    $config           = $conn->getConfig();
    $config['type']   = 'kingbaseMysql';
    $config['schema'] = '';

    try {
        Db::connect($config, true)->query('SELECT 1 AS ok');
    } catch (UnexpectedValueException $e) {
        $t->assertTrue(str_contains($e->getMessage(), 'mode mismatch'), 'mode mismatch error is unclear');
        return;
    }

    throw new RuntimeException('connector accepted the wrong KES compatibility mode');
});

$runner->test('ddl sequence and oracle-compatible types', function ($conn): void {
    $conn->execute('CREATE SEQUENCE KB_ORACLE_USER_SEQ START WITH 1 INCREMENT BY 1');

    $conn->execute('
CREATE TABLE KB_ORACLE_USER (
    ID NUMBER(10) NOT NULL PRIMARY KEY,
    NAME VARCHAR2(50) UNIQUE,
    AGE NUMBER(10),
    SCORE NUMBER(10,2),
    ACTIVE NUMBER(1) DEFAULT 1,
    BIO CLOB,
    DATA_BLOB BLOB,
    CREATED_AT DATE DEFAULT SYSDATE
)
');

    $conn->execute('
CREATE TABLE KB_ORACLE_ORDER (
    ID NUMBER(10) NOT NULL PRIMARY KEY,
    USER_ID NUMBER(10) NOT NULL,
    AMOUNT NUMBER(10,2) NOT NULL,
    STATUS VARCHAR2(20) NOT NULL,
    CREATED_AT TIMESTAMP DEFAULT SYSTIMESTAMP
)
');
});

$runner->test('insertGetId with sequence and basic CRUD', function ($conn, CompatRunner $t): void {
    $id = $conn->table('kb_oracle_user')->sequence('kb_oracle_user_seq')->insertGetId([
        'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
        'name' => 'alpha',
        'age' => 18,
        'score' => '98.50',
        'bio' => 'first user',
        'data_blob' => Db::raw('EMPTY_BLOB()'),
        'created_at' => Db::raw('SYSDATE'),
    ]);

    $t->assertSame(1, (int) $id, 'insertGetId should return first id');

    $conn->table('kb_oracle_user')->where('id', $id)->update(['age' => 19]);
    $row = $conn->table('kb_oracle_user')->where('id', $id)->find();
    $t->assertSame(19, (int) $row['age'], 'update/find failed');
});

$runner->test('multiple inserts and column retrieval', function ($conn, CompatRunner $t): void {
    $conn->table('kb_oracle_user')->insert([
        'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
        'name' => 'beta',
        'age' => 20,
        'score' => '88.00',
        'bio' => 'second user',
        'data_blob' => Db::raw('EMPTY_BLOB()'),
    ]);

    $conn->table('kb_oracle_user')->insert([
        'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
        'name' => 'gamma',
        'age' => 22,
        'score' => '77.25',
        'data_blob' => Db::raw('EMPTY_BLOB()'),
    ]);

    $names = $conn->table('kb_oracle_user')->order('id', 'asc')->column('name');
    $t->assertSame(['alpha', 'beta', 'gamma'], array_values($names), 'column/order failed');
});

$runner->test('metadata tables and fields', function ($conn, CompatRunner $t): void {
    $tables = $conn->getTables();
    $t->assertTrue(in_array('KB_ORACLE_USER', $tables, true), 'getTables missing KB_ORACLE_USER');

    $fields = $conn->getFields('kb_oracle_user');
    $t->assertTrue(isset($fields['id']), 'getFields missing id');
    $t->assertTrue($fields['id']['primary'], 'id primary not detected');
    $t->assertTrue(!$fields['id']['autoinc'], 'manually supplied sequence primary key must not be marked as auto-generated');
});

$runner->test('composite primary key metadata', function ($conn, CompatRunner $t): void {
    try {
        $conn->execute('DROP TABLE KB_ORACLE_COMPOSITE');
    } catch (Throwable $e) {
    }
    $conn->execute('CREATE TABLE KB_ORACLE_COMPOSITE (
        TENANT_ID NUMBER(10) NOT NULL,
        ITEM_ID NUMBER(10) NOT NULL,
        LABEL VARCHAR2(50) UNIQUE,
        PRIMARY KEY (TENANT_ID, ITEM_ID)
    )');

    $fields = $conn->getFields('kb_oracle_composite');
    $t->assertTrue($fields['tenant_id']['primary'], 'first composite key column not detected');
    $t->assertTrue($fields['item_id']['primary'], 'second composite key column not detected');
    $t->assertTrue(!$fields['label']['primary'], 'unique column incorrectly detected as primary');
});

$runner->test('where operators and regexp', function ($conn, CompatRunner $t): void {
    $t->assertSame(3, (int) $conn->table('kb_oracle_user')->where('age', '>', 18)->count(), 'compare failed');
    $t->assertSame(1, (int) $conn->table('kb_oracle_user')->where('name', 'like', 'a%')->count(), 'like failed');
    $t->assertSame(2, (int) $conn->table('kb_oracle_user')->whereBetween('age', [18, 20])->count(), 'between failed');
    $t->assertSame(2, (int) $conn->table('kb_oracle_user')->whereIn('name', ['alpha', 'gamma'])->count(), 'in failed');

    $rows = $conn->query("SELECT * FROM KB_ORACLE_USER WHERE REGEXP_LIKE(NAME, '^a')");
    $t->assertCount(1, $rows, 'regexp_like failed');
});

$runner->test('unique constraint violation', function ($conn, CompatRunner $t): void {
    try {
        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'alpha',
            'age' => 25,
        ]);
    } catch (Throwable $e) {
        $t->assertTrue(true, 'unique violation caught');
        return;
    }

    throw new RuntimeException('unique violation was not raised');
});

$runner->test('join query', function ($conn, CompatRunner $t): void {
    $conn->execute("
INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (1, 1, 10.50, 'paid')
");
    $conn->execute("
INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (2, 1, 20.00, 'paid')
");
    $conn->execute("
INSERT INTO KB_ORACLE_ORDER (ID, USER_ID, AMOUNT, STATUS) VALUES (3, 2, 15.00, 'new')
");

    $rows = $conn->table('kb_oracle_user')
        ->alias('u')
        ->join('kb_oracle_order o', 'u.id = o.user_id')
        ->where('o.status', 'paid')
        ->field('u.name,o.amount')
        ->select()
        ->toArray();

    $t->assertCount(2, $rows, 'join failed');
});

$runner->test('aggregate group having', function ($conn, CompatRunner $t): void {
    $sum = $conn->table('kb_oracle_order')->where('status', 'paid')->sum('amount');
    $t->assertSame('30.50', number_format((float) $sum, 2, '.', ''), 'sum failed');

    $rows = $conn->table('kb_oracle_order')
        ->field('user_id,COUNT(*) AS order_count')
        ->group('user_id')
        ->having('COUNT(*) >= 2')
        ->select()
        ->toArray();

    $t->assertCount(1, $rows, 'group/having failed');
});

$runner->test('union and subquery', function ($conn, CompatRunner $t): void {
    $unionRows = $conn->query("
SELECT NAME FROM KB_ORACLE_USER WHERE NAME = 'alpha'
UNION ALL
SELECT NAME FROM KB_ORACLE_USER WHERE NAME = 'beta'
");
    $t->assertCount(2, $unionRows, 'union all failed');

    $subRows = $conn->query("
SELECT * FROM KB_ORACLE_USER
WHERE ID IN (SELECT USER_ID FROM KB_ORACLE_ORDER WHERE STATUS = 'paid')
");
    $t->assertCount(1, $subRows, 'subquery failed');
});

$runner->test('native pagination and random order', function ($conn, CompatRunner $t): void {
    $rows = $conn->table('kb_oracle_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
    $t->assertCount(2, $rows, 'native limit offset failed');

    $rows = $conn->table('kb_oracle_user')->orderRand()->limit(2)->select()->toArray();
    $t->assertCount(2, $rows, 'random order failed');
});

$runner->test('raw oracle functions', function ($conn, CompatRunner $t): void {
    $row = $conn->query("
SELECT
    NVL(NULL, 'fallback') AS c1,
    NVL2('x', 'not-null', 'null') AS c2,
    DECODE(1, 1, 'yes', 'no') AS c3,
    TO_CHAR(SYSDATE, 'YYYY') AS c4,
    SUBSTR('abcdef', 2, 3) AS c5,
    LENGTH('abc') AS c6,
    INSTR('abcdef', 'cd') AS c7,
    ADD_MONTHS(DATE '2026-01-01', 1) AS c8
FROM dual
");

    $t->assertSame('fallback', (string) $row[0]['c1'], 'nvl failed');
    $t->assertSame('not-null', (string) $row[0]['c2'], 'nvl2 failed');
    $t->assertSame('yes', (string) $row[0]['c3'], 'decode failed');
    $t->assertTrue((int) $row[0]['c4'] >= 2026, 'to_char failed');
    $t->assertSame('bcd', (string) $row[0]['c5'], 'substr failed');
    $t->assertSame(3, (int) $row[0]['c6'], 'length failed');
    $t->assertSame(3, (int) $row[0]['c7'], 'instr failed');
    $t->assertTrue(!empty($row[0]['c8']), 'add_months failed');
});

$runner->test('insert select and archive table', function ($conn, CompatRunner $t): void {
    $conn->execute('
CREATE TABLE KB_ORACLE_ARCHIVE (
    ID NUMBER(10) NOT NULL PRIMARY KEY,
    USER_ID NUMBER(10) NOT NULL,
    NAME VARCHAR2(50),
    ARCHIVED_AT DATE
)
');

    $affected = $conn->execute("
INSERT INTO KB_ORACLE_ARCHIVE (ID, USER_ID, NAME, ARCHIVED_AT)
SELECT ROWNUM, ID, NAME, SYSDATE FROM KB_ORACLE_USER WHERE ACTIVE = 1
");

    $t->assertTrue($affected >= 1, 'insert select affected rows failed');
    $t->assertSame((int) $conn->table('kb_oracle_user')->where('active', 1)->count(), (int) $conn->table('kb_oracle_archive')->count(), 'insert select count failed');
});

$runner->test('clob and blob support', function ($conn, CompatRunner $t): void {
    $largeText = str_repeat('kingbase-oracle-', 128);
    $binary    = "\x00\x01kingbase\x00\xff";

    $conn->table('kb_oracle_user')->insert([
        'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
        'name' => 'delta',
        'age' => 24,
        'bio' => $largeText,
        'data_blob' => $binary,
    ]);

    $row = $conn->table('kb_oracle_user')->where('name', 'delta')->find();
    $t->assertSame(strlen($largeText), strlen((string) $row['bio']), 'clob failed');
    $t->assertSame($binary, binary_value($row['data_blob']), 'bound blob with NUL bytes failed');
});

$runner->test('view support', function ($conn, CompatRunner $t): void {
    $conn->execute('
CREATE VIEW KB_ORACLE_ACTIVE_USERS AS
SELECT ID, NAME FROM KB_ORACLE_USER WHERE ACTIVE = 1
');

    $rows = $conn->query('SELECT * FROM KB_ORACLE_ACTIVE_USERS');
    $t->assertTrue(count($rows) >= 1, 'view query failed');
});

$runner->test('transaction rollback', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'rollback_user',
            'age' => 99,
        ]);
        $conn->rollback();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_oracle_user')->where('name', 'rollback_user')->count();
    $t->assertSame(0, (int) $count, 'rollback failed');
});

$runner->test('transaction commit', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_oracle_user')->insert([
            'id' => Db::raw('KB_ORACLE_USER_SEQ.NEXTVAL'),
            'name' => 'commit_user',
            'age' => 26,
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_oracle_user')->where('name', 'commit_user')->count();
    $t->assertSame(1, (int) $count, 'commit failed');
});

$runner->test('for update lock syntax', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $rows = $conn->query('SELECT * FROM KB_ORACLE_USER WHERE ID = 1 FOR UPDATE');
        $t->assertCount(1, $rows, 'for update failed');
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
});

$runner->test('final cleanup', function () use ($drop): void {
    $drop('DROP TABLE KB_ORACLE_COMPOSITE');
    $drop('DROP VIEW KB_ORACLE_ACTIVE_USERS');
    $drop('DROP TABLE KB_ORACLE_ARCHIVE');
    $drop('DROP TABLE KB_ORACLE_ORDER');
    $drop('DROP TABLE KB_ORACLE_USER');
    $drop('DROP SEQUENCE KB_ORACLE_USER_SEQ');
});

exit($runner->summary());

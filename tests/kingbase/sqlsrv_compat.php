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
    if (is_resource($value)) {
        return (string) stream_get_contents($value);
    }

    $value = (string) $value;
    if (str_starts_with($value, '0x')) {
        return (string) hex2bin(substr($value, 2));
    }

    return $value;
}

Db::setConfig([
    'default' => 'kingbase_sqlsrv',
    'connections' => [
        'kingbase_sqlsrv' => [
            'type' => 'kingbaseSqlsrv',
            'hostname' => env_value('KINGBASE_SQLSRV_HOST', '127.0.0.1'),
            'hostport' => env_value('KINGBASE_SQLSRV_PORT', '54323'),
            'database' => env_value('KINGBASE_SQLSRV_DATABASE', 'test'),
            'username' => env_value('KINGBASE_SQLSRV_USERNAME', 'system'),
            'password' => env_value('KINGBASE_SQLSRV_PASSWORD'),
            'charset' => env_value('KINGBASE_SQLSRV_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => env_value('KINGBASE_SQLSRV_SCHEMA', 'dbo'),
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

$conn = Db::connect('kingbase_sqlsrv');
$runner = new CompatRunner($conn);

$drop = function (string $sql) use ($conn): void {
    try {
        $conn->execute($sql);
    } catch (Throwable $e) {
    }
};

$runner->test('cleanup', function () use ($drop): void {
    $drop('DROP TABLE kb_sqlsrv_composite');
    $drop('DROP VIEW kb_sqlsrv_active_users');
    $drop('DROP TABLE kb_sqlsrv_archive');
    $drop('DROP TABLE kb_sqlsrv_delete_mark');
    $drop('DROP TABLE kb_sqlsrv_order');
    $drop('DROP TABLE kb_sqlsrv_user');
    $drop('DROP TABLE kb_sqlsrv_misc');
});

$runner->test('connection', function ($conn, CompatRunner $t): void {
    $rows = $conn->query('SELECT 1 AS ok');
    $t->assertSame(1, (int) $rows[0]['ok'], 'select 1 failed');
});

$runner->test('pdo mode schema and reconnect', function ($conn, CompatRunner $t): void {
    $t->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)), 'PDO driver mismatch');
    $t->assertSame('sqlserver', $conn->getKingbaseMode(), 'configured mode mismatch');
    $t->assertSame('dbo', strtolower($conn->getKingbaseSchema()), 'resolved schema mismatch');

    $conn->close();
    $rows = $conn->query('SELECT 1 AS ok');
    $t->assertSame(1, (int) $rows[0]['ok'], 'query after reconnect failed');
    $t->assertSame('dbo', strtolower($conn->getKingbaseSchema()), 'schema was not reinitialized');
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

$runner->test('ddl and sqlserver-compatible types', function ($conn): void {
    $conn->execute('
CREATE TABLE kb_sqlsrv_user (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    name NVARCHAR(50) UNIQUE,
    age INT NULL,
    score DECIMAL(10,2) DEFAULT 0,
    active BIT DEFAULT 1,
    bio NVARCHAR(MAX) NULL,
    data_blob VARBINARY(MAX) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
');

    $conn->execute('
CREATE TABLE kb_sqlsrv_order (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status NVARCHAR(20) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
');

    $conn->execute('
CREATE TABLE kb_sqlsrv_misc (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    value_text NVARCHAR(100),
    value_large NVARCHAR(MAX),
    value_blob VARBINARY(MAX) NULL
)
');
});

$runner->test('raw identity insert and identity function', function ($conn, CompatRunner $t): void {
    $conn->execute("
INSERT INTO kb_sqlsrv_user (name, age, score, bio, data_blob)
VALUES ('alpha', 18, 98.50, 'first user', 0x01020304)
");

    $conn->execute("
INSERT INTO kb_sqlsrv_user (name, age, score, bio, data_blob)
VALUES ('beta', 20, 88.00, 'second user', 0x01020304)
");

    $rows = $conn->query('SELECT @@IDENTITY AS id');
    $t->assertSame(2, (int) $rows[0]['id'], '@@IDENTITY failed');
});

$runner->test('insertGetId and basic CRUD', function ($conn, CompatRunner $t): void {
    $id = $conn->table('kb_sqlsrv_user')->insertGetId([
        'name' => 'gamma',
        'age' => 22,
        'score' => '77.25',
        'bio' => 'third user',
    ]);

    $t->assertSame(3, (int) $id, 'insertGetId should return third id');

    $conn->table('kb_sqlsrv_user')->where('id', $id)->update(['age' => 23]);
    $row = $conn->table('kb_sqlsrv_user')->where('id', $id)->find();
    $t->assertSame(23, (int) $row['age'], 'update/find failed');
});

$runner->test('metadata tables and fields', function ($conn, CompatRunner $t): void {
    $tables = $conn->getTables();
    $t->assertTrue(in_array('kb_sqlsrv_user', $tables, true), 'getTables missing kb_sqlsrv_user');

    $fields = $conn->getFields('kb_sqlsrv_user');
    $t->assertTrue(isset($fields['id']), 'getFields missing id');
    $t->assertTrue($fields['id']['primary'], 'id primary not detected');
    $t->assertTrue($fields['id']['autoinc'], 'id identity not detected');
});

$runner->test('composite primary key metadata', function ($conn, CompatRunner $t): void {
    try {
        $conn->execute('DROP TABLE kb_sqlsrv_composite');
    } catch (Throwable $e) {
    }
    $conn->execute('CREATE TABLE kb_sqlsrv_composite (
        tenant_id INT NOT NULL,
        item_id INT NOT NULL,
        label NVARCHAR(50) UNIQUE,
        PRIMARY KEY (tenant_id, item_id)
    )');

    $fields = $conn->getFields('kb_sqlsrv_composite');
    $t->assertTrue($fields['tenant_id']['primary'], 'first composite key column not detected');
    $t->assertTrue($fields['item_id']['primary'], 'second composite key column not detected');
    $t->assertTrue(!$fields['label']['primary'], 'unique column incorrectly detected as primary');
});

$runner->test('where operators', function ($conn, CompatRunner $t): void {
    $t->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->where('age', '>', 18)->count(), 'compare failed');
    $t->assertSame(1, (int) $conn->table('kb_sqlsrv_user')->where('name', 'like', 'a%')->count(), 'like failed');
    $t->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->whereBetween('age', [18, 20])->count(), 'between failed');
    $t->assertSame(2, (int) $conn->table('kb_sqlsrv_user')->whereIn('name', ['alpha', 'gamma'])->count(), 'in failed');
});

$runner->test('unique constraint violation', function ($conn, CompatRunner $t): void {
    try {
        $conn->table('kb_sqlsrv_user')->insert([
            'name' => 'alpha',
            'age' => 25,
        ]);
    } catch (Throwable $e) {
        $t->assertTrue(true, 'unique violation caught');
        return;
    }

    throw new RuntimeException('unique violation was not raised');
});

$runner->test('alter table index and defaults', function ($conn, CompatRunner $t): void {
    $conn->execute("ALTER TABLE kb_sqlsrv_user ADD status_flag NVARCHAR(20) DEFAULT 'new'");
    $conn->execute('CREATE INDEX idx_kb_sqlsrv_user_age ON kb_sqlsrv_user(age)');

    $conn->table('kb_sqlsrv_user')->insert([
        'name' => 'delta',
        'age' => 24,
        'score' => '66.00',
    ]);

    $status = $conn->table('kb_sqlsrv_user')->where('name', 'delta')->value('status_flag');
    $t->assertSame('new', (string) $status, 'default value failed');

    $fields = $conn->getFields('kb_sqlsrv_user');
    $t->assertTrue(isset($fields['status_flag']), 'alter add column not detected');
});

$runner->test('bracket identifier and top', function ($conn, CompatRunner $t): void {
    $rows = $conn->query('SELECT [id], [name], [age] FROM [kb_sqlsrv_user] ORDER BY [id]');
    $t->assertTrue(count($rows) >= 3, 'bracket identifier failed');

    $rows = $conn->query('SELECT TOP 2 * FROM kb_sqlsrv_user ORDER BY id');
    $t->assertCount(2, $rows, 'top failed');
});

$runner->test('join query', function ($conn, CompatRunner $t): void {
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

    $t->assertCount(2, $rows, 'join failed');
});

$runner->test('aggregate group having', function ($conn, CompatRunner $t): void {
    $sum = $conn->table('kb_sqlsrv_order')->where('status', 'paid')->sum('amount');
    $t->assertSame('30.50', number_format((float) $sum, 2, '.', ''), 'sum failed');

    $rows = $conn->table('kb_sqlsrv_order')
        ->field('user_id,COUNT(*) AS order_count')
        ->group('user_id')
        ->having('COUNT(*) >= 2')
        ->select()
        ->toArray();

    $t->assertCount(1, $rows, 'group/having failed');
});

$runner->test('union and subquery', function ($conn, CompatRunner $t): void {
    $unionRows = $conn->query("
SELECT name FROM kb_sqlsrv_user WHERE name = 'alpha'
UNION ALL
SELECT name FROM kb_sqlsrv_user WHERE name = 'beta'
");
    $t->assertCount(2, $unionRows, 'union all failed');

    $subRows = $conn->query("
SELECT * FROM kb_sqlsrv_user
WHERE id IN (SELECT user_id FROM kb_sqlsrv_order WHERE status = 'paid')
");
    $t->assertCount(1, $subRows, 'subquery failed');
});

$runner->test('native pagination', function ($conn, CompatRunner $t): void {
    $rows = $conn->table('kb_sqlsrv_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
    $t->assertCount(2, $rows, 'native limit offset failed');
});

$runner->test('raw sqlserver functions', function ($conn, CompatRunner $t): void {
    $row = $conn->query("
SELECT
    ISNULL(NULL, 'fallback') AS c1,
    COALESCE(NULL, 'coalesce') AS c2,
    CASE WHEN 2 > 1 THEN 'yes' ELSE 'no' END AS c3,
    SUBSTRING('abcdef', 2, 3) AS c4,
    LEN('abc') AS c5,
    CURRENT_TIMESTAMP AS c6
");

    $t->assertSame('fallback', (string) $row[0]['c1'], 'isnull failed');
    $t->assertSame('coalesce', (string) $row[0]['c2'], 'coalesce failed');
    $t->assertSame('yes', (string) $row[0]['c3'], 'case when failed');
    $t->assertSame('bcd', (string) $row[0]['c4'], 'substring failed');
    $t->assertSame(3, (int) $row[0]['c5'], 'len failed');
    $t->assertTrue(!empty($row[0]['c6']), 'current_timestamp failed');
});

$runner->test('insert select and archive table', function ($conn, CompatRunner $t): void {
    $conn->execute('
CREATE TABLE kb_sqlsrv_archive (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    name NVARCHAR(50),
    archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
');

    $affected = $conn->execute("
INSERT INTO kb_sqlsrv_archive (user_id, name)
SELECT id, name FROM kb_sqlsrv_user WHERE active = 1
");

    $t->assertTrue($affected >= 1, 'insert select affected rows failed');
    $t->assertSame((int) $conn->table('kb_sqlsrv_user')->where('active', 1)->count(), (int) $conn->table('kb_sqlsrv_archive')->count(), 'insert select count failed');
});

$runner->test('update from and delete join', function ($conn, CompatRunner $t): void {
    $conn->execute("
UPDATE o
SET status = 'vip'
FROM kb_sqlsrv_order o
JOIN kb_sqlsrv_user u ON u.id = o.user_id
WHERE u.name = 'alpha'
");

    $t->assertSame(2, (int) $conn->table('kb_sqlsrv_order')->where('status', 'vip')->count(), 'update from failed');

    $conn->execute('CREATE TABLE kb_sqlsrv_delete_mark (user_id INT NOT NULL)');
    $conn->execute('INSERT INTO kb_sqlsrv_delete_mark VALUES (2)');
    $conn->execute('
DELETE o
FROM kb_sqlsrv_order o
JOIN kb_sqlsrv_delete_mark m ON m.user_id = o.user_id
');

    $t->assertSame(0, (int) $conn->table('kb_sqlsrv_order')->where('user_id', 2)->count(), 'delete join failed');
});

$runner->test('large text and varbinary support', function ($conn, CompatRunner $t): void {
    $largeText = str_repeat('kingbase-sqlsrv-', 128);
    $binary    = "\x00\x01kingbase\x00\xff";

    $conn->table('kb_sqlsrv_misc')->insert([
        'value_text' => 'blob-row',
        'value_large' => $largeText,
        'value_blob' => $binary,
    ]);

    $row = $conn->table('kb_sqlsrv_misc')->where('id', 1)->find();
    $t->assertSame(strlen($largeText), strlen((string) $row['value_large']), 'large text failed');
    $t->assertSame($binary, binary_value($row['value_blob']), 'bound varbinary with NUL bytes failed');
});

$runner->test('view support', function ($conn, CompatRunner $t): void {
    $conn->execute('
CREATE VIEW kb_sqlsrv_active_users AS
SELECT id, name FROM kb_sqlsrv_user WHERE active = 1
');

    $rows = $conn->query('SELECT * FROM kb_sqlsrv_active_users');
    $t->assertTrue(count($rows) >= 1, 'view query failed');
});

$runner->test('transaction rollback', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_sqlsrv_user')->insert([
            'name' => 'rollback_user',
            'age' => 99,
        ]);
        $conn->rollback();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_sqlsrv_user')->where('name', 'rollback_user')->count();
    $t->assertSame(0, (int) $count, 'rollback failed');
});

$runner->test('transaction commit', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_sqlsrv_user')->insert([
            'name' => 'commit_user',
            'age' => 26,
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_sqlsrv_user')->where('name', 'commit_user')->count();
    $t->assertSame(1, (int) $count, 'commit failed');
});

$runner->test('for update lock syntax', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $rows = $conn->query('SELECT * FROM kb_sqlsrv_user WHERE id = 1 FOR UPDATE');
        $t->assertCount(1, $rows, 'for update failed');
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
});

$runner->test('final cleanup', function () use ($drop): void {
    $drop('DROP TABLE kb_sqlsrv_composite');
    $drop('DROP VIEW kb_sqlsrv_active_users');
    $drop('DROP TABLE kb_sqlsrv_archive');
    $drop('DROP TABLE kb_sqlsrv_delete_mark');
    $drop('DROP TABLE kb_sqlsrv_order');
    $drop('DROP TABLE kb_sqlsrv_user');
    $drop('DROP TABLE kb_sqlsrv_misc');
});

exit($runner->summary());

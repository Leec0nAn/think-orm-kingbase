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
    'default' => 'kingbase_mysql',
    'connections' => [
        'kingbase_mysql' => [
            'type' => 'kingbaseMysql',
            'hostname' => env_value('KINGBASE_MYSQL_HOST', '127.0.0.1'),
            'hostport' => env_value('KINGBASE_MYSQL_PORT', '54321'),
            'database' => env_value('KINGBASE_MYSQL_DATABASE', 'test'),
            'username' => env_value('KINGBASE_MYSQL_USERNAME', 'system'),
            'password' => env_value('KINGBASE_MYSQL_PASSWORD'),
            'charset' => env_value('KINGBASE_MYSQL_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => env_value('KINGBASE_MYSQL_SCHEMA', 'public'),
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

$conn = Db::connect('kingbase_mysql');
$runner = new CompatRunner($conn);

$drop = function (string $sql) use ($conn): void {
    try {
        $conn->execute($sql);
    } catch (Throwable $e) {
    }
};

$runner->test('cleanup', function () use ($drop): void {
    $drop('DROP TABLE IF EXISTS kb_mysql_composite');
    $drop('DROP VIEW IF EXISTS kb_mysql_active_users');
    $drop('DROP TABLE IF EXISTS kb_mysql_archive');
    $drop('DROP TABLE IF EXISTS kb_mysql_delete_mark');
    $drop('DROP TABLE IF EXISTS kb_mysql_order');
    $drop('DROP TABLE IF EXISTS kb_mysql_user');
    $drop('DROP TABLE IF EXISTS kb_mysql_misc');
    $drop('DROP TEMPORARY TABLE IF EXISTS kb_mysql_temp');
});

$runner->test('connection and mysql_json extension', function ($conn, CompatRunner $t): void {
    $rows = $conn->query('SELECT 1 AS ok');
    $t->assertSame(1, (int) $rows[0]['ok'], 'select 1 failed');

    try {
        $conn->execute('CREATE EXTENSION IF NOT EXISTS mysql_json');
    } catch (Throwable $e) {
        echo "mysql_json extension setup skipped: {$e->getMessage()}\n";
    }
});

$runner->test('pdo mode schema and reconnect', function ($conn, CompatRunner $t): void {
    $t->assertSame('kdb', strtolower((string) $conn->connect()->getAttribute(PDO::ATTR_DRIVER_NAME)), 'PDO driver mismatch');
    $t->assertSame('mysql', $conn->getKingbaseMode(), 'configured mode mismatch');
    $t->assertSame('public', strtolower($conn->getKingbaseSchema()), 'resolved schema mismatch');

    $conn->close();
    $rows = $conn->query('SELECT 1 AS ok');
    $t->assertSame(1, (int) $rows[0]['ok'], 'query after reconnect failed');
    $t->assertSame('public', strtolower($conn->getKingbaseSchema()), 'schema was not reinitialized');
});

$runner->test('compatibility mode mismatch is rejected', function ($conn, CompatRunner $t): void {
    $config           = $conn->getConfig();
    $config['type']   = 'kingbaseOracle';
    $config['schema'] = '';

    try {
        Db::connect($config, true)->query('SELECT 1 FROM dual');
    } catch (UnexpectedValueException $e) {
        $t->assertTrue(str_contains($e->getMessage(), 'mode mismatch'), 'mode mismatch error is unclear');
        return;
    }

    throw new RuntimeException('connector accepted the wrong KES compatibility mode');
});

$runner->test('ddl and mysql-compatible types', function ($conn): void {
    $conn->execute("
CREATE TABLE kb_mysql_user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    age INT DEFAULT 0,
    score DECIMAL(10,2) DEFAULT 0,
    active TINYINT DEFAULT 1,
    tags VARCHAR(100) NULL,
    bio TEXT NULL,
    profile JSON NULL,
    created_at TIMESTAMP NULL
)
");

    $conn->execute("
CREATE TABLE kb_mysql_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NULL
)
");

    $conn->execute("
CREATE TABLE kb_mysql_misc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    value_text VARCHAR(100),
    value_blob BLOB NULL
)
");
});

$runner->test('insertGetId and basic CRUD', function ($conn, CompatRunner $t): void {
    $id = $conn->table('kb_mysql_user')->insertGetId([
        'name' => 'alpha',
        'age' => 18,
        'score' => '98.50',
        'tags' => 'red,blue',
        'bio' => 'first user',
        'profile' => json_encode(['city' => 'beijing', 'level' => 1], JSON_UNESCAPED_UNICODE),
        'created_at' => Db::raw('CURRENT_TIMESTAMP'),
    ]);

    $t->assertSame(1, (int) $id, 'insertGetId should return first id');

    $conn->table('kb_mysql_user')->where('id', $id)->update(['age' => 19]);
    $row = $conn->table('kb_mysql_user')->where('id', $id)->find();
    $t->assertSame(19, (int) $row['age'], 'update/find failed');
});

$runner->test('bulk insert and column retrieval', function ($conn, CompatRunner $t): void {
    $conn->table('kb_mysql_user')->insertAll([
        [
            'name' => 'beta',
            'age' => 20,
            'score' => '88.00',
            'tags' => 'green,blue',
            'profile' => json_encode(['city' => 'shanghai', 'level' => 2]),
        ],
        [
            'name' => 'gamma',
            'age' => 22,
            'score' => '77.25',
            'tags' => 'red,yellow',
            'profile' => json_encode(['city' => 'beijing', 'level' => 3]),
        ],
    ]);

    $names = $conn->table('kb_mysql_user')->order('id', 'asc')->column('name');
    $t->assertSame(['alpha', 'beta', 'gamma'], array_values($names), 'column/order failed');
});

$runner->test('metadata tables and fields', function ($conn, CompatRunner $t): void {
    $tables = $conn->getTables();
    $t->assertTrue(in_array('kb_mysql_user', $tables, true), 'getTables missing kb_mysql_user');

    $fields = $conn->getFields('kb_mysql_user');
    $t->assertTrue(isset($fields['id']), 'getFields missing id');
    $t->assertTrue($fields['id']['primary'], 'id primary not detected');
    $t->assertTrue($fields['id']['autoinc'], 'id autoinc not detected');
});

$runner->test('composite primary key metadata', function ($conn, CompatRunner $t): void {
    $conn->execute('DROP TABLE IF EXISTS kb_mysql_composite');
    $conn->execute('CREATE TABLE kb_mysql_composite (
        tenant_id INT NOT NULL,
        item_id INT NOT NULL,
        label VARCHAR(50) UNIQUE,
        PRIMARY KEY (tenant_id, item_id)
    )');

    $fields = $conn->getFields('kb_mysql_composite');
    $t->assertTrue($fields['tenant_id']['primary'], 'first composite key column not detected');
    $t->assertTrue($fields['item_id']['primary'], 'second composite key column not detected');
    $t->assertTrue(!$fields['label']['primary'], 'unique column incorrectly detected as primary');
});

$runner->test('where operators', function ($conn, CompatRunner $t): void {
    $t->assertSame(3, (int) $conn->table('kb_mysql_user')->where('age', '>', 18)->count(), 'compare failed');
    $t->assertSame(1, (int) $conn->table('kb_mysql_user')->where('name', 'like', 'a%')->count(), 'like failed');
    $t->assertSame(2, (int) $conn->table('kb_mysql_user')->whereBetween('age', [18, 20])->count(), 'between failed');
    $t->assertSame(2, (int) $conn->table('kb_mysql_user')->whereIn('name', ['alpha', 'gamma'])->count(), 'in failed');
    $t->assertSame(2, (int) $conn->table('kb_mysql_user')->whereNull('bio')->count(), 'null failed');
});

$runner->test('regexp and find_in_set', function ($conn, CompatRunner $t): void {
    $t->assertSame(1, (int) $conn->table('kb_mysql_user')->where('name', 'regexp', '^a')->count(), 'regexp failed');
    $t->assertSame(2, (int) $conn->table('kb_mysql_user')->where('tags', 'find in set', 'red')->count(), 'find_in_set failed');
});

$runner->test('duplicate and replace', function ($conn, CompatRunner $t): void {
    $conn->table('kb_mysql_user')->duplicate(['age'])->insert([
        'name' => 'alpha',
        'age' => 30,
    ]);

    $age = $conn->table('kb_mysql_user')->where('name', 'alpha')->value('age');
    $t->assertSame(30, (int) $age, 'duplicate update failed');

    $conn->table('kb_mysql_user')->replace()->insert([
        'id' => 3,
        'name' => 'gamma',
        'age' => 23,
        'score' => '79.00',
    ]);

    $age = $conn->table('kb_mysql_user')->where('name', 'gamma')->value('age');
    $t->assertSame(23, (int) $age, 'replace failed');
});

$runner->test('json extract', function ($conn, CompatRunner $t): void {
    $rows = $conn->table('kb_mysql_user')->where('profile->city', 'beijing')->select()->toArray();
    $t->assertCount(1, $rows, 'json where failed after replace');
});

$runner->test('json set', function ($conn, CompatRunner $t): void {
    $conn->table('kb_mysql_user')->where('name', 'alpha')->update(['profile->level' => 9]);
    $level = $conn->table('kb_mysql_user')->where('name', 'alpha')->value('profile->level');
    $t->assertSame('9', (string) $level, 'json set/value failed');
});

$runner->test('alter table index and defaults', function ($conn, CompatRunner $t): void {
    $conn->execute('ALTER TABLE kb_mysql_user ADD COLUMN status_flag VARCHAR(20) DEFAULT \'new\'');
    $conn->execute('CREATE INDEX idx_kb_mysql_user_age ON kb_mysql_user(age)');

    $conn->table('kb_mysql_user')->insert([
        'name' => 'delta',
        'age' => 24,
        'score' => '66.00',
        'tags' => 'black',
    ]);

    $status = $conn->table('kb_mysql_user')->where('name', 'delta')->value('status_flag');
    $t->assertSame('new', (string) $status, 'default value failed');

    $fields = $conn->getFields('kb_mysql_user');
    $t->assertTrue(isset($fields['status_flag']), 'alter add column not detected');
});

$runner->test('unique constraint violation', function ($conn, CompatRunner $t): void {
    try {
        $conn->table('kb_mysql_user')->insert([
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

    $t->assertCount(2, $rows, 'join failed');
});

$runner->test('aggregate group having', function ($conn, CompatRunner $t): void {
    $sum = $conn->table('kb_mysql_order')->where('status', 'paid')->sum('amount');
    $t->assertSame('30.50', number_format((float) $sum, 2, '.', ''), 'sum failed');

    $rows = $conn->table('kb_mysql_order')
        ->field('user_id,COUNT(*) AS order_count')
        ->group('user_id')
        ->having('COUNT(*) >= 2')
        ->select()
        ->toArray();

    $t->assertCount(1, $rows, 'group/having failed');
});

$runner->test('union and subquery', function ($conn, CompatRunner $t): void {
    $unionRows = $conn->query("
SELECT name FROM kb_mysql_user WHERE name = 'alpha'
UNION ALL
SELECT name FROM kb_mysql_user WHERE name = 'beta'
");
    $t->assertCount(2, $unionRows, 'union all failed');

    $subRows = $conn->query("
SELECT * FROM kb_mysql_user
WHERE id IN (SELECT user_id FROM kb_mysql_order WHERE status = 'paid')
");
    $t->assertCount(1, $subRows, 'subquery failed');
});

$runner->test('pagination and random order', function ($conn, CompatRunner $t): void {
    $rows = $conn->table('kb_mysql_user')->order('id', 'asc')->limit(1, 2)->select()->toArray();
    $t->assertCount(2, $rows, 'limit offset failed');

    $rows = $conn->table('kb_mysql_user')->orderRand()->limit(2)->select()->toArray();
    $t->assertCount(2, $rows, 'rand order failed');
});

$runner->test('raw mysql functions', function ($conn, CompatRunner $t): void {
    $row = $conn->query("
SELECT
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

    $t->assertSame('ab', (string) $row[0]['c1'], 'concat failed');
    $t->assertSame('b', (string) $row[0]['c2'], 'substring failed');
    $t->assertSame(3, (int) $row[0]['c3'], 'length failed');
    $t->assertTrue((int) $row[0]['c4'] >= 2026, 'date_format failed');
    $t->assertSame('fallback', (string) $row[0]['c5'], 'ifnull failed');
    $t->assertSame('coalesce', (string) $row[0]['c6'], 'coalesce failed');
    $t->assertSame('yes', (string) $row[0]['c7'], 'case when failed');
    $t->assertSame('abc', (string) $row[0]['c8'], 'left failed');
    $t->assertSame('ef', (string) $row[0]['c9'], 'right failed');
});

$runner->test('insert select and archive table', function ($conn, CompatRunner $t): void {
    $conn->execute('
CREATE TABLE kb_mysql_archive (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    archived_at TIMESTAMP NULL
)
');

    $affected = $conn->execute("
INSERT INTO kb_mysql_archive (user_id, name, archived_at)
SELECT id, name, CURRENT_TIMESTAMP FROM kb_mysql_user WHERE active = 1
");

    $t->assertTrue($affected >= 1, 'insert select affected rows failed');
    $t->assertSame((int) $conn->table('kb_mysql_user')->where('active', 1)->count(), (int) $conn->table('kb_mysql_archive')->count(), 'insert select count failed');
});

$runner->test('multi table update and delete', function ($conn, CompatRunner $t): void {
    $conn->execute("
UPDATE kb_mysql_order o
JOIN kb_mysql_user u ON u.id = o.user_id
SET o.status = 'vip'
WHERE u.name = 'alpha'
");

    $t->assertSame(2, (int) $conn->table('kb_mysql_order')->where('status', 'vip')->count(), 'update join failed');

    $conn->execute('CREATE TABLE kb_mysql_delete_mark (user_id INT NOT NULL)');
    $conn->execute('INSERT INTO kb_mysql_delete_mark VALUES (2)');
    $conn->execute('
DELETE o FROM kb_mysql_order o
JOIN kb_mysql_delete_mark m ON m.user_id = o.user_id
');

    $t->assertSame(0, (int) $conn->table('kb_mysql_order')->where('user_id', 2)->count(), 'delete join failed');
});

$runner->test('blob and large text support', function ($conn, CompatRunner $t): void {
    $largeText = str_repeat('kingbase-mysql-', 128);
    $binary    = "\x00\x01kingbase\x00\xff";

    $conn->execute('ALTER TABLE kb_mysql_misc ADD COLUMN value_large TEXT NULL');

    $conn->table('kb_mysql_misc')->insert([
        'value_text' => 'blob-row',
        'value_large' => $largeText,
        'value_blob' => $binary,
    ]);

    $row = $conn->table('kb_mysql_misc')->where('id', 1)->find();
    $t->assertSame(strlen($largeText), strlen((string) $row['value_large']), 'large text failed');
    $t->assertSame($binary, binary_value($row['value_blob']), 'bound blob with NUL bytes failed');
});

$runner->test('view support', function ($conn, CompatRunner $t): void {
    $conn->execute("
CREATE VIEW kb_mysql_active_users AS
SELECT id, name FROM kb_mysql_user WHERE active = 1
");

    $rows = $conn->query('SELECT * FROM kb_mysql_active_users');
    $t->assertTrue(count($rows) >= 1, 'view query failed');
});

$runner->test('temporary table support', function ($conn, CompatRunner $t): void {
    $conn->execute('CREATE TEMPORARY TABLE kb_mysql_temp (id INT, name VARCHAR(20))');
    $conn->execute("INSERT INTO kb_mysql_temp VALUES (1, 'tmp')");
    $rows = $conn->query('SELECT * FROM kb_mysql_temp');
    $t->assertSame('tmp', (string) $rows[0]['name'], 'temporary table failed');
});

$runner->test('transaction rollback', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_mysql_user')->insert([
            'name' => 'rollback_user',
            'age' => 99,
        ]);
        $conn->rollback();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_mysql_user')->where('name', 'rollback_user')->count();
    $t->assertSame(0, (int) $count, 'rollback failed');
});

$runner->test('transaction commit', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $conn->table('kb_mysql_user')->insert([
            'name' => 'commit_user',
            'age' => 26,
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $count = $conn->table('kb_mysql_user')->where('name', 'commit_user')->count();
    $t->assertSame(1, (int) $count, 'commit failed');
});

$runner->test('for update lock syntax', function ($conn, CompatRunner $t): void {
    $conn->startTrans();
    try {
        $rows = $conn->query('SELECT * FROM kb_mysql_user WHERE id = 1 FOR UPDATE');
        $t->assertCount(1, $rows, 'for update failed');
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
});

$runner->test('final cleanup', function () use ($drop): void {
    $drop('DROP TABLE IF EXISTS kb_mysql_composite');
    $drop('DROP VIEW IF EXISTS kb_mysql_active_users');
    $drop('DROP TABLE IF EXISTS kb_mysql_archive');
    $drop('DROP TABLE IF EXISTS kb_mysql_delete_mark');
    $drop('DROP TABLE IF EXISTS kb_mysql_order');
    $drop('DROP TABLE IF EXISTS kb_mysql_user');
    $drop('DROP TABLE IF EXISTS kb_mysql_misc');
    $drop('DROP TEMPORARY TABLE IF EXISTS kb_mysql_temp');
});

exit($runner->summary());

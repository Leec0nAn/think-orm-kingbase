<?php

declare(strict_types=1);

/**
 * KingbaseES complex/conditional data-type layered diagnostic.
 *
 * The probe deliberately tests three boundaries in order:
 *   1. KingbaseES accepts the type declaration;
 *   2. PDO_KDB can bind and fetch a value;
 *   3. ThinkORM can bind, fetch and describe the same column.
 *
 * A database-side rejection is reported as UNSUPPORTED (or BLOCKED when the
 * environment/permission is missing), never as PASS.  ThinkORM is blamed only
 * when the first two boundaries pass and the framework boundary fails.
 */

use think\facade\Db;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$args = getopt('', ['mode:', 'allow-ddl']);
$modeArg = strtolower((string) ($args['mode'] ?? 'all'));
$modes = 'all' === $modeArg ? ['mysql', 'oracle', 'sqlsrv'] : [$modeArg];
$allowedModes = ['mysql', 'oracle', 'sqlsrv'];

foreach ($modes as $mode) {
    if (!in_array($mode, $allowedModes, true)) {
        fwrite(STDERR, "Usage: php complex_type_diagnostic.php --mode=all|mysql|oracle|sqlsrv --allow-ddl\n");
        exit(2);
    }
}

if (!isset($args['allow-ddl'])) {
    fwrite(STDERR, "[KDB-COMPLEX][BLOCKED][ALL][runtime][Environment] Isolated fixture DDL is required; add --allow-ddl.\n");
    exit(2);
}

$counts = ['PASS' => 0, 'FAIL' => 0, 'BLOCKED' => 0, 'UNSUPPORTED' => 0];
$results = [];
$secrets = [];

function complexEnv(string $name, string $default = ''): string
{
    $value = getenv($name);

    return false === $value || '' === $value ? $default : (string) $value;
}

function complexSanitize(string $text): string
{
    global $secrets;
    foreach ($secrets as $secret) {
        if ('' !== $secret) {
            $text = str_replace($secret, '***', $text);
        }
    }

    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/** @param array<string, mixed> $context */
function complexLog(string $status, string $mode, string $case, string $layer, string $message, array $context = []): void
{
    $safe = [];
    foreach ($context as $key => $value) {
        if (preg_match('/password|passwd|pwd|secret|token/i', (string) $key)) {
            $safe[$key] = '***';
        } elseif (is_string($value)) {
            $safe[$key] = complexSanitize($value);
        } else {
            $safe[$key] = $value;
        }
    }
    $suffix = [] === $safe
        ? ''
        : ' | ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, sprintf(
        "[KDB-COMPLEX][%s][%s][%s][%s] %s%s\n",
        strtoupper($status),
        strtoupper($mode),
        $case,
        $layer,
        complexSanitize($message),
        $suffix
    ));
}

/** @param array<string, mixed> $context */
function complexRecord(string $status, string $mode, string $case, string $layer, string $message, array $context = []): void
{
    global $counts, $results;
    $counts[$status]++;
    $results[] = compact('status', 'mode', 'case', 'layer', 'message', 'context');
    complexLog($status, $mode, $case, $layer, $message, $context);
}

function complexError(Throwable $exception): string
{
    return substr(complexSanitize($exception->getMessage()), 0, 700);
}

/** @return array{state:string,code:string} */
function complexErrorCodes(Throwable $exception): array
{
    $state = '';
    $code = (string) $exception->getCode();
    if ($exception instanceof PDOException && is_array($exception->errorInfo ?? null)) {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $code = (string) ($exception->errorInfo[1] ?? $code);
    }

    return ['state' => $state, 'code' => $code];
}

function complexDatabaseRejectionStatus(Throwable $exception): string
{
    $codes = complexErrorCodes($exception);
    $message = strtolower($exception->getMessage());
    if (str_starts_with($codes['state'], '08') || '42501' === $codes['state']
        || str_contains($message, 'permission denied') || str_contains($message, 'not authorized')) {
        return 'BLOCKED';
    }

    return 'UNSUPPORTED';
}

function complexPdoStatus(Throwable $exception): string
{
    $codes = complexErrorCodes($exception);
    $message = strtolower($exception->getMessage());
    if ('0A000' === $codes['state'] || str_contains($message, 'not supported')
        || str_contains($message, 'unsupported') || str_contains($message, 'not implemented')) {
        return 'UNSUPPORTED';
    }

    return 'FAIL';
}

/** @param array<string, string> $config */
function complexConnect(array $config): PDO
{
    $dsn = 'kdb:host=' . $config['host'] . ';dbname=' . $config['database'] . ';port=' . $config['port'];

    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function complexQuote(string $mode, string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $name)) {
        throw new RuntimeException('Unsafe diagnostic identifier: ' . $name);
    }
    if ('oracle' === $mode) {
        return '"' . strtoupper($name) . '"';
    }
    if ('sqlsrv' === $mode) {
        // Use a bare, validated identifier for direct PDO probes.  Bracket
        // quoting is verified by the SQL builder suite; on some KES SQL
        // Server-mode baselines bracketed DDL and prepared DML resolve the
        // same spelling differently, which would make a type probe report a
        // false PDO_KDB failure.
        return $name;
    }

    return '`' . $name . '`';
}

/** @param mixed $value */
function complexScalar($value): string
{
    if (is_resource($value)) {
        return (string) stream_get_contents($value);
    }

    return (string) $value;
}

/** @param mixed $actual */
function complexAssertValue(string $case, string $expected, $actual): void
{
    $actual = complexScalar($actual);
    if ('binary' === $case) {
        if ($expected !== $actual) {
            throw new RuntimeException('Binary value changed during round trip');
        }
        return;
    }
    if ('contains' === $case) {
        if (!str_contains(strtolower($actual), strtolower($expected))) {
            throw new RuntimeException('Expected fragment not found: ' . $expected . '; actual=' . $actual);
        }
        return;
    }
    if ($expected !== $actual) {
        throw new RuntimeException('Value changed; expected=' . $expected . '; actual=' . $actual);
    }
}

/** @return list<array<string, mixed>> */
function complexCases(string $mode): array
{
    $cases = [
        [
            'name' => 'control_varchar',
            'group' => 'CONTROL',
            'declaration' => 'VARCHAR(32)',
            'value' => 'control-value',
            'assert' => 'exact',
            'expected' => 'control-value',
        ],
        [
            'name' => 'integer_array',
            'group' => 'ARRAY',
            'declaration' => 'INTEGER[]',
            'value' => '{1,2,3}',
            'assert' => 'exact',
            'expected' => '{1,2,3}',
        ],
    ];

    if ('oracle' === $mode) {
        $cases = array_merge($cases, [
            [
                'name' => 'refcursor_identifier',
                'group' => 'REFCURSOR',
                'declaration' => 'REFCURSOR',
                'value' => 'kdb_cursor_token',
                'assert' => 'exact',
                'expected' => 'kdb_cursor_token',
            ],
            [
                'name' => 'composite_type',
                'group' => 'STRUCT_UDT_COMPOSITE',
                'setup' => ['CREATE TYPE {type} AS (code INTEGER, label VARCHAR(32))'],
                'cleanup' => ['DROP TYPE {type}'],
                'declaration' => '{type}',
                'value' => '(7,label)',
                'assert' => 'contains',
                'expected' => 'label',
            ],
            [
                'name' => 'oracle_object_type',
                'group' => 'STRUCT_UDT_COMPOSITE',
                'setup' => ['CREATE TYPE {type} AS OBJECT (code NUMBER(10), label VARCHAR2(32))'],
                'cleanup' => ['DROP TYPE {type}'],
                'declaration' => '{type}',
                'value' => '(8,object_label)',
                'assert' => 'contains',
                'expected' => 'object_label',
            ],
            [
                'name' => 'integer_range',
                'group' => 'RANGE',
                'declaration' => 'INT4RANGE',
                'value' => '[1,10)',
                'assert' => 'exact',
                'expected' => '[1,10)',
            ],
            [
                'name' => 'network_inet',
                'group' => 'NETWORK',
                'declaration' => 'INET',
                'value' => '192.0.2.1/24',
                'assert' => 'contains',
                'expected' => '192.0.2.1',
            ],
            [
                'name' => 'spatial_point',
                'group' => 'SPATIAL',
                'declaration' => 'POINT',
                'value' => '(1.5,2.5)',
                'assert' => 'exact',
                'expected' => '(1.5,2.5)',
            ],
            [
                'name' => 'fulltext_vector',
                'group' => 'FULLTEXT',
                'declaration' => 'TSVECTOR',
                'value' => "'kingbase':1 'thinkphp':2",
                'assert' => 'contains',
                'expected' => 'kingbase',
            ],
            [
                'name' => 'raw_alias',
                'group' => 'CONDITIONAL_ALIAS',
                'declaration' => 'RAW(32)',
                'value' => "\x00\x01KDB\xff",
                'bind' => PDO::PARAM_LOB,
                'assert' => 'binary',
                'expected' => "\x00\x01KDB\xff",
            ],
        ]);
    }

    if ('sqlsrv' === $mode) {
        $cases = array_merge($cases, [
            [
                'name' => 'alias_udt',
                'group' => 'STRUCT_UDT_COMPOSITE',
                'setup' => ['CREATE TYPE {type} FROM VARCHAR(32)'],
                'cleanup' => ['DROP TYPE {type}'],
                'declaration' => '{type}',
                'value' => 'udt-value',
                'assert' => 'exact',
                'expected' => 'udt-value',
            ],
            ['name' => 'smallmoney_alias', 'group' => 'CONDITIONAL_ALIAS', 'declaration' => 'SMALLMONEY', 'value' => '12.34', 'assert' => 'contains', 'expected' => '12.34'],
            ['name' => 'ntext_alias', 'group' => 'CONDITIONAL_ALIAS', 'declaration' => 'NTEXT', 'value' => '金仓 NTEXT', 'assert' => 'exact', 'expected' => '金仓 NTEXT'],
            ['name' => 'image_alias', 'group' => 'CONDITIONAL_ALIAS', 'declaration' => 'IMAGE', 'value' => "\x00\x01KDB\xff", 'bind' => PDO::PARAM_LOB, 'assert' => 'binary', 'expected' => "\x00\x01KDB\xff"],
            ['name' => 'clob_alias', 'group' => 'CONDITIONAL_ALIAS', 'declaration' => 'CLOB', 'value' => 'SQL Server CLOB', 'assert' => 'exact', 'expected' => 'SQL Server CLOB'],
            ['name' => 'datetimeoffset_alias', 'group' => 'CONDITIONAL_ALIAS', 'declaration' => 'DATETIMEOFFSET', 'value' => '2024-02-29 23:59:58+08:00', 'assert' => 'contains', 'expected' => '2024-02-29 23:59:58'],
        ]);
    }

    return $cases;
}

if (!extension_loaded('pdo') || !extension_loaded('pdo_kdb')) {
    complexRecord('BLOCKED', 'ALL', 'runtime', 'Environment', 'PDO and PDO_KDB extensions are required');
    exit(2);
}

complexLog('INFO', 'ALL', 'runtime', 'Environment', 'Complex type diagnostic started', [
    'php' => PHP_VERSION,
    'pdo_kdb' => phpversion('pdo_kdb') ?: 'unknown',
    'modes' => $modes,
]);

foreach ($modes as $mode) {
    $prefix = 'KINGBASE_' . strtoupper($mode) . '_';
    $defaults = [
        'mysql' => ['54321', 'public', 'kingbaseMysql', 'mysql'],
        'oracle' => ['54322', 'system', 'kingbaseOracle', 'oracle'],
        'sqlsrv' => ['54323', 'dbo', 'kingbaseSqlsrv', 'sqlserver'],
    ];
    $config = [
        'host' => complexEnv($prefix . 'HOST', '127.0.0.1'),
        'port' => complexEnv($prefix . 'PORT', $defaults[$mode][0]),
        'database' => complexEnv($prefix . 'DATABASE', 'test'),
        'username' => complexEnv($prefix . 'USERNAME', 'system'),
        'password' => complexEnv($prefix . 'PASSWORD'),
        'schema' => complexEnv($prefix . 'SCHEMA', $defaults[$mode][1]),
        'orm_type' => $defaults[$mode][2],
        'expected_mode' => $defaults[$mode][3],
    ];
    if ('' === $config['password']) {
        complexRecord('BLOCKED', $mode, 'credentials', 'Environment', 'Missing ' . $prefix . 'PASSWORD');
        continue;
    }
    $secrets[] = $config['password'];

    try {
        $pdo = complexConnect($config);
        $actualMode = strtolower((string) $pdo->query("SELECT current_setting('database_mode', true)")->fetchColumn());
        if ($actualMode !== $config['expected_mode']) {
            throw new RuntimeException('database_mode mismatch: expected ' . $config['expected_mode'] . ', got ' . $actualMode);
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $config['schema'])) {
            throw new RuntimeException('Unsafe diagnostic schema: ' . $config['schema']);
        }
        $schema = '"' . str_replace('"', '""', $config['schema']) . '"';
        $searchPath = 'public' === strtolower($config['schema']) ? $schema : $schema . ', public';
        $pdo->exec('SET search_path TO ' . $searchPath);
        complexLog('INFO', $mode, 'connect', 'KingbaseES', 'Direct PDO_KDB connection established and mode verified', [
            'host' => $config['host'],
            'port' => $config['port'],
            'database_mode' => $actualMode,
            'schema' => $config['schema'],
        ]);
    } catch (Throwable $exception) {
        complexRecord('BLOCKED', $mode, 'connect', 'Environment', complexError($exception), complexErrorCodes($exception));
        continue;
    }

    $connectionName = 'kingbase_complex_' . $mode;
    Db::setConfig([
        'default' => $connectionName,
        'connections' => [
            $connectionName => [
                'type' => $config['orm_type'],
                'hostname' => $config['host'],
                'hostport' => $config['port'],
                'database' => $config['database'],
                'username' => $config['username'],
                'password' => $config['password'],
                'charset' => complexEnv($prefix . 'CHARSET', 'utf8'),
                'prefix' => '',
                'schema' => $config['schema'],
                'fields_cache' => false,
                'trigger_sql' => true,
            ],
        ],
    ]);
    $orm = Db::connect($connectionName);

    foreach (complexCases($mode) as $index => $case) {
        $token = substr(hash('sha256', $mode . ':' . $case['name'] . ':' . getmypid() . ':' . microtime(true)), 0, 10);
        $tableName = 'kbc_' . $token;
        $typeName = 'kbt_' . $token;
        $table = complexQuote($mode, $tableName);
        $id = complexQuote($mode, 'id');
        $valueColumn = complexQuote($mode, 'value');
        $replace = static fn (string $sql): string => str_replace(
            ['{table}', '{type}'],
            [$table, $typeName],
            $sql
        );
        $cleanup = array_map($replace, array_reverse((array) ($case['cleanup'] ?? [])));
        array_unshift($cleanup, 'DROP TABLE ' . $table);

        foreach ($cleanup as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $ignored) {
            }
        }

        $created = false;
        try {
            foreach ((array) ($case['setup'] ?? []) as $sql) {
                $pdo->exec($replace($sql));
            }
            $declaration = str_replace('{type}', $typeName, (string) $case['declaration']);
            $pdo->exec('CREATE TABLE ' . $table . ' (' . $id . ' INT PRIMARY KEY, ' . $valueColumn . ' ' . $declaration . ')');
            $visible = $pdo->prepare(
                'SELECT table_schema, table_name FROM information_schema.tables'
                . ' WHERE lower(table_schema)=lower(:schema) AND lower(table_name)=lower(:table)'
            );
            $visible->execute([':schema' => $config['schema'], ':table' => $tableName]);
            $visibleRow = $visible->fetch(PDO::FETCH_ASSOC);
            if (!is_array($visibleRow)) {
                $status = 'CONTROL' === $case['group'] ? 'FAIL' : 'UNSUPPORTED';
                complexRecord($status, $mode, $case['name'], 'KingbaseES', 'CREATE TABLE returned success, but no table was materialized in the configured schema', [
                    'group' => $case['group'],
                    'declaration' => $declaration,
                    'schema' => $config['schema'],
                ]);
                foreach ($cleanup as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (Throwable $ignored) {
                    }
                }
                continue;
            }
            $created = true;
            complexLog('INFO', $mode, $case['name'], 'KingbaseES', 'Type declaration accepted', [
                'group' => $case['group'],
                'declaration' => $declaration,
            ]);
        } catch (Throwable $exception) {
            $status = complexDatabaseRejectionStatus($exception);
            complexRecord($status, $mode, $case['name'], 'KingbaseES', 'Current instance/mode rejected the type declaration: ' . complexError($exception), array_merge(
                ['group' => $case['group'], 'declaration' => $case['declaration']],
                complexErrorCodes($exception)
            ));
            foreach ($cleanup as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $ignored) {
                }
            }
            continue;
        }

        try {
            $statement = $pdo->prepare('INSERT INTO ' . $table . ' (' . $id . ', ' . $valueColumn . ') VALUES (:id, :value)');
            $statement->bindValue(':id', 1, PDO::PARAM_INT);
            $statement->bindValue(':value', $case['value'], (int) ($case['bind'] ?? PDO::PARAM_STR));
            $statement->execute();
            $statement = $pdo->prepare('SELECT ' . $valueColumn . ' FROM ' . $table . ' WHERE ' . $id . '=:id');
            $statement->bindValue(':id', 1, PDO::PARAM_INT);
            $statement->execute();
            $rawValue = $statement->fetchColumn();
            complexAssertValue((string) $case['assert'], (string) $case['expected'], $rawValue);
            $pdo->exec('DELETE FROM ' . $table);
            complexLog('INFO', $mode, $case['name'], 'PDO_KDB', 'Direct parameter binding and fetch passed', [
                'returned_type' => get_debug_type($rawValue),
            ]);
        } catch (Throwable $exception) {
            $status = complexPdoStatus($exception);
            complexRecord($status, $mode, $case['name'], 'PDO_KDB', 'Database accepted the type, but direct PDO_KDB round trip failed: ' . complexError($exception), array_merge(
                ['group' => $case['group']],
                complexErrorCodes($exception)
            ));
            foreach ($cleanup as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $ignored) {
                }
            }
            continue;
        }

        try {
            $inserted = $orm->table($tableName)->insert(['id' => 2, 'value' => $case['value']]);
            if (1 !== $inserted) {
                throw new RuntimeException('ThinkORM insert returned ' . var_export($inserted, true));
            }
            $row = $orm->table($tableName)->where('id', 2)->find();
            if (!is_array($row) || !array_key_exists('value', array_change_key_case($row))) {
                throw new RuntimeException('ThinkORM query did not return the value column');
            }
            $row = array_change_key_case($row);
            complexAssertValue((string) $case['assert'], (string) $case['expected'], $row['value']);
            $fields = array_change_key_case($orm->getFields($tableName));
            if (!isset($fields['value'])) {
                throw new RuntimeException('ThinkORM metadata omitted the complex value column');
            }
            complexRecord('PASS', $mode, $case['name'], 'ThinkORM', 'KingbaseES declaration, PDO_KDB round trip and ThinkORM round trip/metadata all passed', [
                'group' => $case['group'],
                'metadata_type' => (string) ($fields['value']['type'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            complexRecord('FAIL', $mode, $case['name'], 'ThinkORM', 'KingbaseES and PDO_KDB passed, but ThinkORM failed: ' . complexError($exception), array_merge(
                ['group' => $case['group']],
                complexErrorCodes($exception)
            ));
        } finally {
            foreach ($cleanup as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $cleanupException) {
                    complexLog('WARN', $mode, $case['name'], 'Cleanup', complexError($cleanupException));
                }
            }
        }
    }
}

complexLog('SUMMARY', 'ALL', 'summary', 'Diagnostic', 'Complex type layered diagnostic completed', array_merge(
    $counts,
    ['TOTAL' => array_sum($counts), 'results' => $results]
));

exit($counts['FAIL'] > 0 ? 1 : ($counts['BLOCKED'] > 0 ? 2 : 0));

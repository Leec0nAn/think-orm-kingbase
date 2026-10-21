<?php

/**
 * PDO_KDB transaction-layer diagnostic for PHP 5.6 and PHP 8+.
 *
 * This intentionally bypasses ThinkORM and ThinkPHP 3.2 so a failure can be
 * attributed to PDO_KDB/KES rather than hidden by a framework workaround.
 * Fixture DDL is allowed only with --allow-ddl and uses a unique kptx_* table.
 */

$args = getopt('', array('mode:', 'allow-ddl'));
$modeArg = isset($args['mode']) ? strtolower((string) $args['mode']) : 'all';
$modes = 'all' === $modeArg ? array('mysql', 'oracle', 'sqlsrv') : array($modeArg);
$allowedModes = array('mysql', 'oracle', 'sqlsrv');
foreach ($modes as $mode) {
    if (!in_array($mode, $allowedModes, true)) {
        fwrite(STDERR, "Usage: php pdo_transaction_diagnostic.php --mode=all|mysql|oracle|sqlsrv --allow-ddl\n");
        exit(2);
    }
}
if (!isset($args['allow-ddl'])) {
    fwrite(STDERR, "BLOCKED: transaction semantics require an isolated fixture table; add --allow-ddl in a dedicated test database.\n");
    exit(2);
}

$requiredPass = 0;
$requiredFail = 0;
$blocked = 0;
$observedSupported = 0;
$observedUnsupported = 0;
$secrets = array();

function txEnv($name, $default = '')
{
    $value = getenv($name);
    return false === $value || '' === $value ? $default : (string) $value;
}

function txLog($level, $mode, $case, $message, $context = array())
{
    global $secrets;
    $text = '[KDB-PDO-TX][' . strtoupper($level) . '][' . $mode . '][' . $case . '] '
        . trim((string) preg_replace('/\s+/', ' ', $message));
    if (!empty($context)) {
        $text .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    foreach ($secrets as $secret) {
        if ('' !== $secret) {
            $text = str_replace($secret, '***', $text);
        }
    }
    fwrite(STDOUT, $text . PHP_EOL);
}

function txError($exception)
{
    return substr(trim((string) preg_replace('/\s+/', ' ', $exception->getMessage())), 0, 600);
}

function txQuote($mode, $name)
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $name)) {
        throw new RuntimeException('Unsafe diagnostic identifier');
    }
    if ('oracle' === $mode) {
        return '"' . strtoupper($name) . '"';
    }
    if ('sqlsrv' === $mode) {
        return '[' . $name . ']';
    }
    return '`' . $name . '`';
}

function txConnect($config)
{
    $dsn = 'kdb:host=' . $config['host'] . ';dbname=' . $config['database'] . ';port=' . $config['port'];
    return new PDO($dsn, $config['username'], $config['password'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ));
}

function txSafeRollback($pdo)
{
    if (!$pdo) {
        return;
    }
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            return;
        }
    } catch (Exception $ignored) {
    }
    try {
        $pdo->exec('ROLLBACK');
    } catch (Exception $ignored) {
    }
}

function txRequired($mode, $case, $callback)
{
    global $requiredPass, $requiredFail;
    $started = microtime(true);
    try {
        $context = call_user_func($callback);
        $requiredPass++;
        txLog('PASS', $mode, $case, 'Required transaction behavior passed', array_merge(
            array('runtime_ms' => (int) round((microtime(true) - $started) * 1000)),
            is_array($context) ? $context : array()
        ));
        return true;
    } catch (Exception $exception) {
        $requiredFail++;
        txLog('FAIL', $mode, $case, txError($exception), array(
            'exception' => get_class($exception),
            'code' => (string) $exception->getCode(),
            'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
        return false;
    }
}

function txObservedSql($mode, $case, $config, $sql)
{
    global $observedSupported, $observedUnsupported;
    $pdo = null;
    $started = microtime(true);
    try {
        $pdo = txConnect($config);
        $result = $pdo->exec($sql);
        $pdo->exec('ROLLBACK');
        $observedSupported++;
        txLog('SUPPORTED', $mode, $case, 'Syntax/API accepted by this PDO_KDB and KES mode', array(
            'sql' => $sql,
            'result' => $result,
            'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
    } catch (Exception $exception) {
        $observedUnsupported++;
        txLog('UNSUPPORTED', $mode, $case, txError($exception), array(
            'exception' => get_class($exception),
            'code' => (string) $exception->getCode(),
            'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
    }
    txSafeRollback($pdo);
}

function txObserved($mode, $case, $callback)
{
    global $observedSupported, $observedUnsupported;
    $started = microtime(true);
    try {
        $context = call_user_func($callback);
        $observedSupported++;
        txLog('SUPPORTED', $mode, $case, 'API accepted by this PDO_KDB and KES mode', array_merge(
            array('runtime_ms' => (int) round((microtime(true) - $started) * 1000)),
            is_array($context) ? $context : array()
        ));
        return true;
    } catch (Exception $exception) {
        $observedUnsupported++;
        txLog('UNSUPPORTED', $mode, $case, txError($exception), array(
            'exception' => get_class($exception),
            'code' => (string) $exception->getCode(),
            'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
        ));
        return false;
    }
}

function txInsert($pdo, $table, $id, $marker)
{
    $statement = $pdo->prepare('INSERT INTO ' . $table . ' (id, marker) VALUES (:id, :marker)');
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->bindValue(':marker', $marker, PDO::PARAM_STR);
    $statement->execute();
}

function txCount($pdo, $table, $id)
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id=:id');
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    return (int) $statement->fetchColumn();
}

txLog('INFO', 'ALL', 'runtime', 'PDO_KDB transaction diagnostic started', array(
    'php' => PHP_VERSION,
    'pdo_kdb' => phpversion('pdo_kdb') ?: 'unknown',
    'modes' => $modes,
));

if (!extension_loaded('pdo') || !extension_loaded('pdo_kdb')) {
    txLog('BLOCKED', 'ALL', 'runtime', 'PDO and PDO_KDB extensions are required');
    exit(2);
}

foreach ($modes as $mode) {
    $prefix = 'KINGBASE_' . strtoupper($mode) . '_';
    $defaults = array(
        'mysql' => array('54321', 'public'),
        'oracle' => array('54322', 'system'),
        'sqlsrv' => array('54323', 'dbo'),
    );
    $config = array(
        'host' => txEnv($prefix . 'HOST', '127.0.0.1'),
        'port' => txEnv($prefix . 'PORT', $defaults[$mode][0]),
        'database' => txEnv($prefix . 'DATABASE', 'test'),
        'username' => txEnv($prefix . 'USERNAME', 'system'),
        'password' => txEnv($prefix . 'PASSWORD'),
        'schema' => txEnv($prefix . 'SCHEMA', $defaults[$mode][1]),
    );
    if ('' === $config['password']) {
        $blocked++;
        txLog('BLOCKED', $mode, 'credentials', 'Missing ' . $prefix . 'PASSWORD');
        continue;
    }
    $secrets[] = $config['password'];
    $tableName = 'kptx_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8);
    $table = txQuote($mode, $tableName);
    $base = null;
    $created = false;

    try {
        $base = txConnect($config);
        $actualMode = strtolower((string) $base->query("SELECT current_setting('database_mode', true)")->fetchColumn());
        $expectedMode = 'sqlsrv' === $mode ? 'sqlserver' : $mode;
        if ($actualMode !== $expectedMode) {
            throw new RuntimeException('database_mode mismatch: expected ' . $expectedMode . ', got ' . $actualMode);
        }
        txLog('PASS', $mode, 'connect', 'Direct PDO_KDB connection established', array(
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'schema' => $config['schema'],
            'database_mode' => $actualMode,
        ));
        $base->exec('CREATE TABLE ' . $table . ' (id INT PRIMARY KEY, marker VARCHAR(64))');
        $created = true;
        txLog('PASS', $mode, 'fixture', 'Unique diagnostic table created', array('table' => $tableName));

        $pdoBeginCheck = function () use ($config) {
            $pdo = txConnect($config);
            try {
                $result = $pdo->beginTransaction();
                if (true !== $result) {
                    throw new RuntimeException('PDO::beginTransaction() returned false');
                }
                $pdo->rollBack();
                return array('begin_return' => true, 'rollback_return' => true);
            } catch (Exception $exception) {
                txSafeRollback($pdo);
                throw $exception;
            }
        };
        $pdoBeginPassed = 'sqlsrv' === $mode
            ? txObserved($mode, 'pdo_begin_transaction', $pdoBeginCheck)
            : txRequired($mode, 'pdo_begin_transaction', $pdoBeginCheck);

        txObservedSql($mode, 'raw_begin', $config, 'BEGIN');
        txObservedSql($mode, 'raw_begin_transaction', $config, 'BEGIN TRANSACTION');

        if ($pdoBeginPassed) {
            txRequired($mode, 'pdo_commit_persistence', function () use ($config, $base, $table) {
                $pdo = txConnect($config);
                try {
                    $pdo->beginTransaction();
                    txInsert($pdo, $table, 1001, 'pdo-commit');
                    if (true !== $pdo->commit()) {
                        throw new RuntimeException('PDO::commit() returned false');
                    }
                    $count = txCount($base, $table, 1001);
                    if (1 !== $count) {
                        throw new RuntimeException('Committed row is not visible; actual count=' . $count);
                    }
                    return array('row_count_after_commit' => $count);
                } catch (Exception $exception) {
                    txSafeRollback($pdo);
                    throw $exception;
                }
            });
            txRequired($mode, 'pdo_rollback_visibility', function () use ($config, $base, $table) {
                $pdo = txConnect($config);
                try {
                    $pdo->beginTransaction();
                    txInsert($pdo, $table, 1002, 'pdo-rollback');
                    if (true !== $pdo->rollBack()) {
                        throw new RuntimeException('PDO::rollBack() returned false');
                    }
                    $count = txCount($base, $table, 1002);
                    if (0 !== $count) {
                        throw new RuntimeException('Rolled-back row remains visible; actual count=' . $count);
                    }
                    return array('row_count_after_rollback' => $count);
                } catch (Exception $exception) {
                    txSafeRollback($pdo);
                    throw $exception;
                }
            });
        } elseif ('sqlsrv' === $mode) {
            txLog(
                'NOT_APPLICABLE',
                $mode,
                'pdo_commit_persistence',
                'PDO::beginTransaction() uses unsupported bare BEGIN; adapter uses BEGIN TRANSACTION instead'
            );
            txLog(
                'NOT_APPLICABLE',
                $mode,
                'pdo_rollback_visibility',
                'Validated by sqlsrv_explicit_transaction_semantics'
            );
        } else {
            $blocked += 2;
            txLog('BLOCKED', $mode, 'pdo_commit_persistence', 'Prerequisite PDO::beginTransaction() failed');
            txLog('BLOCKED', $mode, 'pdo_rollback_visibility', 'Prerequisite PDO::beginTransaction() failed');
        }

        if ('sqlsrv' === $mode) {
            txRequired($mode, 'sqlsrv_explicit_transaction_semantics', function () use ($config, $base, $table) {
                $pdo = txConnect($config);
                try {
                    $pdo->exec('BEGIN TRANSACTION');
                    txInsert($pdo, $table, 1003, 'explicit-commit');
                    $pdo->exec('COMMIT');
                    $committed = txCount($base, $table, 1003);
                    $pdo->exec('BEGIN TRANSACTION');
                    txInsert($pdo, $table, 1004, 'explicit-rollback');
                    $pdo->exec('ROLLBACK');
                    $rolledBack = txCount($base, $table, 1004);
                    if (1 !== $committed || 0 !== $rolledBack) {
                        throw new RuntimeException(
                            'Explicit SQL semantics mismatch: committed=' . $committed . ', rolled_back=' . $rolledBack
                        );
                    }
                    return array('committed_count' => $committed, 'rolled_back_count' => $rolledBack);
                } catch (Exception $exception) {
                    txSafeRollback($pdo);
                    throw $exception;
                }
            });
        }
    } catch (Exception $exception) {
        $requiredFail++;
        txLog('FAIL', $mode, 'mode_setup', txError($exception), array(
            'exception' => get_class($exception),
            'code' => (string) $exception->getCode(),
        ));
    }

    if ($base && $created) {
        try {
            $base->exec('DROP TABLE ' . $table);
            txLog('PASS', $mode, 'cleanup', 'Diagnostic table removed', array('table' => $tableName));
        } catch (Exception $exception) {
            $requiredFail++;
            txLog('FAIL', $mode, 'cleanup', txError($exception), array('table' => $tableName));
        }
    }
    $base = null;
}

txLog('SUMMARY', 'ALL', 'summary', 'PDO_KDB transaction diagnostic completed', array(
    'REQUIRED_PASS' => $requiredPass,
    'REQUIRED_FAIL' => $requiredFail,
    'BLOCKED' => $blocked,
    'OBSERVED_SUPPORTED' => $observedSupported,
    'OBSERVED_UNSUPPORTED' => $observedUnsupported,
));

exit($requiredFail > 0 ? 1 : ($blocked > 0 ? 2 : 0));

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use think\facade\Db;

function monitorEnv(string $name, string $default = ''): string
{
    $value = getenv($name);

    return false === $value || '' === $value ? $default : (string) $value;
}

function monitorLog(string $level, string $mode, string $message, array $context = []): void
{
    $safe = [];
    foreach ($context as $key => $value) {
        $safe[$key] = preg_match('/password|passwd|pwd|secret|token/i', (string) $key)
            ? '***'
            : $value;
    }
    $suffix = [] === $safe
        ? ''
        : ' | ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, sprintf(
        "[KDB-RECOVERY][%s][%s] %s%s\n",
        strtoupper($level),
        $mode,
        trim((string) preg_replace('/\s+/', ' ', $message)),
        $suffix
    ));
}

$profiles = [
    'MySQL' => [
        'name' => 'kingbase_mysql_recovery',
        'probe' => 'SELECT 1 AS ok',
        'password_env' => 'KINGBASE_MYSQL_PASSWORD',
        'config' => [
            'type' => 'kingbaseMysql',
            'hostname' => monitorEnv('KINGBASE_MYSQL_HOST', '127.0.0.1'),
            'hostport' => monitorEnv('KINGBASE_MYSQL_PORT', '54321'),
            'database' => monitorEnv('KINGBASE_MYSQL_DATABASE', 'test'),
            'username' => monitorEnv('KINGBASE_MYSQL_USERNAME', 'system'),
            'password' => monitorEnv('KINGBASE_MYSQL_PASSWORD'),
            'schema' => monitorEnv('KINGBASE_MYSQL_SCHEMA', 'public'),
            'charset' => 'utf8',
            'prefix' => '',
            'fields_cache' => false,
            'trigger_sql' => false,
        ],
    ],
    'Oracle' => [
        'name' => 'kingbase_oracle_recovery',
        'probe' => 'SELECT 1 AS ok FROM dual',
        'password_env' => 'KINGBASE_ORACLE_PASSWORD',
        'config' => [
            'type' => 'kingbaseOracle',
            'hostname' => monitorEnv('KINGBASE_ORACLE_HOST', '127.0.0.1'),
            'hostport' => monitorEnv('KINGBASE_ORACLE_PORT', '54322'),
            'database' => monitorEnv('KINGBASE_ORACLE_DATABASE', 'test'),
            'username' => monitorEnv('KINGBASE_ORACLE_USERNAME', 'system'),
            'password' => monitorEnv('KINGBASE_ORACLE_PASSWORD'),
            'schema' => monitorEnv('KINGBASE_ORACLE_SCHEMA'),
            'charset' => 'utf8',
            'prefix' => '',
            'fields_cache' => false,
            'trigger_sql' => false,
        ],
    ],
    'SQL Server' => [
        'name' => 'kingbase_sqlsrv_recovery',
        'probe' => 'SELECT 1 AS ok',
        'password_env' => 'KINGBASE_SQLSRV_PASSWORD',
        'config' => [
            'type' => 'kingbaseSqlsrv',
            'hostname' => monitorEnv('KINGBASE_SQLSRV_HOST', '127.0.0.1'),
            'hostport' => monitorEnv('KINGBASE_SQLSRV_PORT', '54323'),
            'database' => monitorEnv('KINGBASE_SQLSRV_DATABASE', 'test'),
            'username' => monitorEnv('KINGBASE_SQLSRV_USERNAME', 'system'),
            'password' => monitorEnv('KINGBASE_SQLSRV_PASSWORD'),
            'schema' => monitorEnv('KINGBASE_SQLSRV_SCHEMA', 'dbo'),
            'charset' => 'utf8',
            'prefix' => '',
            'fields_cache' => false,
            'trigger_sql' => false,
        ],
    ],
];

$selected = array_filter(array_map('trim', explode(',', monitorEnv(
    'KINGBASE_FAILURE_MODES',
    'MySQL,Oracle,SQL Server'
))));
$profiles = array_intersect_key($profiles, array_flip($selected));
if ([] === $profiles) {
    fwrite(STDERR, "KINGBASE_FAILURE_MODES did not select a supported mode\n");
    exit(2);
}

foreach ($profiles as $mode => $profile) {
    if ('' === $profile['config']['password']) {
        fwrite(STDERR, 'Missing environment variable: ' . $profile['password_env'] . PHP_EOL);
        exit(2);
    }
}

Db::setConfig([
    'default' => reset($profiles)['name'],
    'connections' => array_column($profiles, 'config', 'name'),
]);

$duration = max(20, (int) monitorEnv('KINGBASE_FAILURE_DURATION', '120'));
$interval = max(1, (int) monitorEnv('KINGBASE_FAILURE_INTERVAL', '2'));
$states = [];
foreach ($profiles as $mode => $profile) {
    $states[$mode] = [
        'initial_up' => false,
        'outage_seen' => false,
        'recovered' => false,
        'force' => false,
        'attempts' => 0,
    ];
    monitorLog('INFO', $mode, 'Monitor configured', [
        'host' => $profile['config']['hostname'],
        'port' => $profile['config']['hostport'],
        'database' => $profile['config']['database'],
        'schema' => '' === $profile['config']['schema'] ? '(auto)' : $profile['config']['schema'],
    ]);
}

monitorLog('INFO', 'ALL', 'Stop and restart every selected KingbaseES instance during the monitoring window', [
    'duration_seconds' => $duration,
    'interval_seconds' => $interval,
]);

$started = microtime(true);
while ((microtime(true) - $started) < $duration) {
    foreach ($profiles as $mode => $profile) {
        $states[$mode]['attempts']++;
        $attemptStarted = microtime(true);
        unset($connection);
        try {
            $connection = Db::connect($profile['name'], $states[$mode]['force']);
            $states[$mode]['force'] = false;
            $rows = $connection->query($profile['probe']);
            if (1 !== (int) ($rows[0]['ok'] ?? 0)) {
                throw new RuntimeException('Probe returned an unexpected value');
            }

            if (!$states[$mode]['initial_up']) {
                $states[$mode]['initial_up'] = true;
                $event = 'Initial connection succeeded';
            } elseif ($states[$mode]['outage_seen'] && !$states[$mode]['recovered']) {
                $states[$mode]['recovered'] = true;
                $event = 'Connection recovered after outage';
            } else {
                $event = 'Probe succeeded';
            }
            monitorLog('UP', $mode, $event, [
                'attempt' => $states[$mode]['attempts'],
                'runtime_ms' => (int) round((microtime(true) - $attemptStarted) * 1000),
            ]);
        } catch (Throwable $e) {
            if ($states[$mode]['initial_up']) {
                $states[$mode]['outage_seen'] = true;
            }
            $states[$mode]['force'] = true;
            try {
                if (isset($connection)) {
                    $connection->close();
                }
            } catch (Throwable $ignored) {
            }
            $message = $e->getMessage();
            $password = $profile['config']['password'];
            if ('' !== $password) {
                $message = str_replace($password, '***', $message);
            }
            monitorLog('DOWN', $mode, $message, [
                'attempt' => $states[$mode]['attempts'],
                'runtime_ms' => (int) round((microtime(true) - $attemptStarted) * 1000),
            ]);
        }
    }

    $complete = true;
    foreach ($states as $state) {
        $complete = $complete && $state['initial_up'] && $state['outage_seen'] && $state['recovered'];
    }
    if ($complete) {
        break;
    }
    sleep($interval);
}

$failed = 0;
foreach ($states as $mode => $state) {
    $passed = $state['initial_up'] && $state['outage_seen'] && $state['recovered'];
    if (!$passed) {
        $failed++;
    }
    monitorLog($passed ? 'PASS' : 'FAIL', $mode, 'Failure-recovery result', $state);
}
monitorLog('SUMMARY', 'ALL', 'Failure-recovery monitor completed', [
    'modes' => count($states),
    'passed' => count($states) - $failed,
    'failed' => $failed,
]);

exit(0 === $failed ? 0 : 1);

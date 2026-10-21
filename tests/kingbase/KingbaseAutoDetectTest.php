<?php

declare(strict_types=1);

namespace tests\kingbase;

use PHPUnit\Framework\TestCase;
use think\db\connector\Kingbase;
use think\db\connector\KingbaseModeDetector;
use think\db\connector\KingbaseMysql;
use think\db\connector\KingbaseOracle;
use think\db\connector\KingbaseSqlsrv;
use think\DbManager;

/** 验证type=kingbase统一入口能够自动选择并二次校验三模式连接器。 */
final class KingbaseAutoDetectTest extends TestCase
{
    /** @var array<int, object> */
    private array $connections = [];

    private static int $passedModes = 0;

    private static int $failedModes = 0;

    private static int $executedModes = 0;

    public static function modes(): array
    {
        return [
            ['mysql', 'MYSQL', '54321', 'public', KingbaseMysql::class],
            ['oracle', 'ORACLE', '54322', 'system', KingbaseOracle::class],
            ['sqlserver', 'SQLSRV', '54323', 'dbo', KingbaseSqlsrv::class],
        ];
    }

    public function testConnectorTypeMapping(): void
    {
        self::verboseLog('INFO', 'Common', __FUNCTION__, 'Connector type mapping verification started');
        self::assertSame('kingbaseMysql', KingbaseModeDetector::connectorType('mysql'));
        self::assertSame('kingbaseOracle', KingbaseModeDetector::connectorType('oracle'));
        self::assertSame('kingbaseSqlsrv', KingbaseModeDetector::connectorType('sqlserver'));
        self::assertSame('kingbaseSqlsrv', KingbaseModeDetector::connectorType('sqlsrv'));
        self::verboseLog('PASS', 'Common', __FUNCTION__, 'Compatibility modes map to the expected connector types', [
            'mysql' => 'kingbaseMysql',
            'oracle' => 'kingbaseOracle',
            'sqlserver' => 'kingbaseSqlsrv',
        ]);
    }

    /** @dataProvider modes */
    public function testUnifiedTypeDetectsModeAndCreatesConnector(
        string $expectedMode,
        string $envMode,
        string $defaultPort,
        string $defaultSchema,
        string $expectedClass
    ): void {
        $env = static function (string $name, string $default = ''): string {
            $value = getenv($name);

            return false === $value ? $default : (string) $value;
        };
        $prefix = 'KINGBASE_' . $envMode . '_';
        $modeLabel = self::modeLabel($expectedMode);
        $testName = __FUNCTION__;
        $startedAt = microtime(true);
        ++self::$executedModes;
        $config = [
            'type' => 'kingbase',
            'hostname' => $env($prefix . 'HOST', '127.0.0.1'),
            'hostport' => $env($prefix . 'PORT', $defaultPort),
            'database' => $env($prefix . 'DATABASE', 'test'),
            'username' => $env($prefix . 'USERNAME', 'system'),
            'password' => $env($prefix . 'PASSWORD'),
            'schema' => $env($prefix . 'SCHEMA', $defaultSchema),
            'fields_cache' => false,
            'trigger_sql' => false,
        ];

        self::verboseLog('INFO', $modeLabel, $testName, 'Unified type=kingbase example started', [
            'host' => $config['hostname'],
            'port' => $config['hostport'],
            'database' => $config['database'],
            'username' => $config['username'],
            'schema' => $config['schema'],
            'expected_mode' => $expectedMode,
        ]);

        try {
            $manager = new class extends DbManager {
                protected function modelMaker()
                {
                }
            };
            self::verboseLog(
                'ACTION',
                $modeLabel,
                $testName,
                "DbManager->connect() called with type=kingbase; probing SELECT current_setting('database_mode', true)"
            );
            $connection = $manager->connect($config);
            $this->connections[] = $connection;

            $resolvedConnector = $connection->getResolvedConnector();
            $detectedMode = $connection->getKingbaseMode();
            self::verboseLog('CONNECT', $modeLabel, $testName, 'Mode detected and connector resolved', [
                'detected_mode' => $detectedMode,
                'connector' => get_class($resolvedConnector),
            ]);

            self::assertInstanceOf(Kingbase::class, $connection);
            self::assertInstanceOf($expectedClass, $resolvedConnector);
            self::assertSame($expectedMode, $detectedMode);
            self::assertSame($expectedMode, $connection->getConfig('kingbase_mode'));
            self::verboseLog('ASSERT', $modeLabel, $testName, 'Detected mode and connector match expectations', [
                'expected_mode' => $expectedMode,
                'expected_connector' => $expectedClass,
            ]);

            self::verboseLog('ACTION', $modeLabel, $testName, 'Executing first business query');
            $rows = $connection->query('SELECT 1 AS ok');
            self::assertSame(1, (int) $rows[0]['ok']);
            self::verboseLog('SQL', $modeLabel, $testName, 'SELECT 1 AS ok', [
                'rows' => count($rows),
                'ok' => (int) $rows[0]['ok'],
            ]);

            self::verboseLog('ACTION', $modeLabel, $testName, 'Closing connection and executing query again to trigger reconnect');
            $connection->close();
            $rows = $connection->query('SELECT 1 AS ok');
            self::assertSame(1, (int) $rows[0]['ok']);
            self::verboseLog('SQL', $modeLabel, $testName, 'SELECT 1 AS ok after reconnect', [
                'rows' => count($rows),
                'ok' => (int) $rows[0]['ok'],
            ]);

            ++self::$passedModes;
            self::verboseLog('PASS', $modeLabel, $testName, 'Automatic mode detection, connector selection and reconnect passed', [
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $exception) {
            ++self::$failedModes;
            self::verboseLog('FAIL', $modeLabel, $testName, $exception->getMessage(), [
                'exception' => get_class($exception),
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            throw $exception;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::verboseLog('SUMMARY', 'ALL', 'KingbaseAutoDetectTest', 'Three-mode automatic detection summary', [
            'PASS' => self::$passedModes,
            'FAIL' => self::$failedModes,
            'BLOCKED' => 0,
            'expected_modes' => self::$executedModes,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }

    private static function verboseEnabled(): bool
    {
        $value = strtolower(trim((string) getenv('KINGBASE_TEST_VERBOSE')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function modeLabel(string $mode): string
    {
        if ('mysql' === $mode) {
            return 'MySQL';
        }
        if ('oracle' === $mode) {
            return 'Oracle';
        }
        if ('sqlserver' === $mode || 'sqlsrv' === $mode) {
            return 'SQL Server';
        }

        return 'Common';
    }

    /** @param array<string, mixed> $context */
    private static function verboseLog(
        string $level,
        string $mode,
        string $test,
        string $message,
        array $context = []
    ): void {
        if (!self::verboseEnabled()) {
            return;
        }

        $safeContext = [];
        foreach ($context as $key => $value) {
            $safeContext[$key] = preg_match('/password|passwd|pwd|secret|token/i', (string) $key)
                ? '***'
                : $value;
        }
        $suffix = [] === $safeContext
            ? ''
            : ' | ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        fwrite(STDOUT, sprintf(
            "[KDB-TEST][%s][%s][%s] %s%s\n",
            strtoupper($level),
            $mode,
            $test,
            $message,
            $suffix
        ));
    }
}

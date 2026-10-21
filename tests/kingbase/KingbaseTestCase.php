<?php

declare(strict_types=1);

namespace tests\kingbase;

use PHPUnit\Framework\TestCase;
use think\facade\Db;

abstract class KingbaseTestCase extends TestCase
{
    protected static string $connectionName;

    protected static array $connectionConfig = [];

    private static bool $verboseListenerRegistered = false;

    /** 清理夹具时默认不输出重复的 DROP 日志。 */
    private static bool $verboseSqlMuted = false;

    private static string $activeMode = 'Common';

    private static string $activeTest = 'class setup';

    private static float $activeTestStartedAt = 0.0;

    protected static function envValue(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return false === $value ? $default : (string) $value;
    }

    protected static function configureConnection(string $connectionName, array $connectionConfig): void
    {
        static::$connectionName = $connectionName;
        static::$connectionConfig = $connectionConfig;
        self::$activeMode = self::modeLabel((string) ($connectionConfig['type'] ?? ''));
        self::$activeTest = 'class setup';

        Db::setConfig([
            'default' => $connectionName,
            'connections' => [
                $connectionName => $connectionConfig,
            ],
        ]);

        self::registerVerboseSqlListener();
        self::verboseLog('INFO', 'Database connection configured', [
            'connection' => $connectionName,
            'host' => (string) ($connectionConfig['hostname'] ?? ''),
            'port' => (string) ($connectionConfig['hostport'] ?? ''),
            'database' => (string) ($connectionConfig['database'] ?? ''),
            'schema' => (string) ($connectionConfig['schema'] ?? '(auto)'),
        ]);
    }

    public function runBare(): void
    {
        self::$activeMode = self::modeLabel((string) (static::$connectionConfig['type'] ?? ''));
        self::$activeTest = static::class . '::' . $this->getName(false);
        self::$activeTestStartedAt = microtime(true);
        self::verboseLog('INFO', 'Test started');

        try {
            parent::runBare();
            self::verboseLog('PASS', 'Test passed', [
                'runtime_ms' => (int) round((microtime(true) - self::$activeTestStartedAt) * 1000),
            ]);
        } catch (\PHPUnit\Framework\SkippedTestError $e) {
            self::verboseLog('SKIP', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            self::verboseLog('FAIL', $e->getMessage(), [
                'exception' => get_class($e),
                'runtime_ms' => (int) round((microtime(true) - self::$activeTestStartedAt) * 1000),
            ]);
            throw $e;
        }
    }

    protected function conn()
    {
        return Db::connect(static::$connectionName);
    }

    protected function dropIfExists(string $sql): void
    {
        $logCleanup = in_array(
            strtolower(trim((string) getenv('KINGBASE_TEST_LOG_CLEANUP'))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if (!$logCleanup) {
            self::$verboseSqlMuted = true;
        }

        try {
            $this->conn()->execute($sql);
        } catch (\Throwable $e) {
        } finally {
            self::$verboseSqlMuted = false;
        }
    }

    protected function binaryValue($value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        $value = (string) $value;
        if (str_starts_with($value, '0x')) {
            $decoded = hex2bin(substr($value, 2));

            return false === $decoded ? '' : $decoded;
        }

        return $value;
    }

    protected function assertRowCount(int $expected, string $table): void
    {
        $count = (int) $this->conn()->table($table)->count();
        $this->assertSame($expected, $count);
    }

    private static function registerVerboseSqlListener(): void
    {
        if (!self::verboseEnabled() || self::$verboseListenerRegistered) {
            return;
        }

        Db::listen(static function ($sql, $runtime): void {
            if (self::$verboseSqlMuted) {
                return;
            }

            $sql = self::sanitizeLogText((string) $sql);
            $level = str_starts_with($sql, 'CONNECT:') ? 'CONNECT' : 'SQL';
            $context = 'CONNECT' === $level ? [] : [
                'runtime_ms' => (int) round((float) $runtime * 1000),
            ];
            self::verboseLog($level, $sql, $context);
        });
        self::$verboseListenerRegistered = true;
    }

    private static function verboseEnabled(): bool
    {
        $value = strtolower(trim((string) getenv('KINGBASE_TEST_VERBOSE')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function modeLabel(string $type): string
    {
        $type = strtolower($type);
        if (str_contains($type, 'mysql')) {
            return 'MySQL';
        }
        if (str_contains($type, 'oracle')) {
            return 'Oracle';
        }
        if (str_contains($type, 'sqlsrv')) {
            return 'SQL Server';
        }

        return 'Common';
    }

    /** @param array<string, mixed> $context */
    protected static function verboseLog(string $level, string $message, array $context = []): void
    {
        if (!self::verboseEnabled()) {
            return;
        }

        $safeContext = [];
        foreach ($context as $key => $value) {
            if (preg_match('/password|passwd|pwd|secret|token/i', (string) $key)) {
                $safeContext[$key] = '***';
                continue;
            }
            $safeContext[$key] = is_string($value) ? self::sanitizeLogText($value) : $value;
        }

        $suffix = '';
        if ([] !== $safeContext) {
            $suffix = ' | ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        fwrite(STDOUT, sprintf(
            "[KDB-TEST][%s][%s][%s] %s%s\n",
            strtoupper($level),
            self::$activeMode,
            self::$activeTest,
            self::sanitizeLogText($message),
            $suffix
        ));
    }

    private static function sanitizeLogText(string $text): string
    {
        $password = (string) (static::$connectionConfig['password'] ?? '');
        if ('' !== $password) {
            $text = str_replace($password, '***', $text);
        }

        $text = (string) preg_replace('/((?:password|passwd|pwd|secret|token)\s*[=:]\s*)[^;\s]+/i', '$1***', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}

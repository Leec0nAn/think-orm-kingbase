<?php
declare(strict_types=1);

namespace think\db\connector;

use PDOException;
use Throwable;

/** 兼容模式探测与物理连接共用的安全诊断处理。 */
final class KingbaseConnectionDiagnostics
{
    public static function exception(string $message, PDOException $original, string $password): PDOException
    {
        $previous = self::needsSafeCopy($original, $password)
            ? self::copyException($original, $password) : $original;
        $failure = new PDOException(self::redact($message, $password), 0, $previous);
        self::setState($failure, [
            'code' => $original->getCode(),
            'trace' => self::safeTrace($failure->getTrace(), $password),
        ]);
        if (is_array($original->errorInfo)) {
            $failure->errorInfo = self::safeErrorInfo($original->errorInfo, $password);
        }

        return $failure;
    }

    private static function needsSafeCopy(Throwable $exception, string $password): bool
    {
        if ('' !== $password && str_contains((string) $exception, $password)) {
            return true;
        }
        do {
            // 即使异常文本未包含凭据，调用参数或附带对象中仍可能包含凭据。
            foreach ($exception->getTrace() as $frame) {
                if (array_key_exists('args', $frame) || array_key_exists('object', $frame)) {
                    return true;
                }
            }
            if ($exception instanceof PDOException && is_array($exception->errorInfo)
                && self::safeErrorInfo($exception->errorInfo, $password) !== $exception->errorInfo) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception);

        return false;
    }

    private static function copyException(Throwable $original, string $password): Throwable
    {
        $message = self::redact($original->getMessage(), $password);
        $copy = $original instanceof PDOException ? new PDOException($message)
            : ($original instanceof \Error ? new \Error($message) : new \RuntimeException($message));
        self::setState($copy, [
            'code' => $original->getCode(),
            'file' => self::redact($original->getFile(), $password),
            'line' => $original->getLine(),
            'trace' => self::safeTrace($original->getTrace(), $password),
            'previous' => $original->getPrevious()
                ? self::copyException($original->getPrevious(), $password) : null,
        ]);
        if ($copy instanceof PDOException && is_array($original->errorInfo)) {
            $copy->errorInfo = self::safeErrorInfo($original->errorInfo, $password);
        }

        return $copy;
    }

    /** 保留源码位置，不保留调用参数或附带对象。 */
    private static function safeTrace(array $trace, string $password): array
    {
        $safe = [];
        foreach ($trace as $frame) {
            $item = [];
            foreach (['file', 'line', 'class', 'type', 'function'] as $key) {
                if (isset($frame[$key]) && (is_string($frame[$key]) || is_int($frame[$key]))) {
                    $item[$key] = is_string($frame[$key])
                        ? self::redact($frame[$key], $password) : $frame[$key];
                }
            }
            $safe[] = $item;
        }

        return $safe;
    }

    private static function safeErrorInfo(array $info, string $password): array
    {
        $safe = [];
        foreach ($info as $key => $value) {
            $key = is_string($key) ? self::redact($key, $password) : $key;
            $safe[$key] = is_array($value) ? self::safeErrorInfo($value, $password)
                : (is_string($value) ? self::redact($value, $password)
                    : (is_scalar($value) || null === $value ? $value : '[redacted]'));
        }

        return $safe;
    }

    private static function redact(string $value, string $password): string
    {
        return '' === $password ? $value : str_replace($password, '***', $value);
    }

    /** 异常代码可能是 SQLSTATE 字符串，而构造函数仅接收整数代码。 */
    private static function setState(Throwable $exception, array $values): void
    {
        $base = $exception instanceof \Error ? \Error::class : \Exception::class;
        foreach ($values as $name => $value) {
            $property = new \ReflectionProperty($base, $name);
            $property->setAccessible(true);
            $property->setValue($exception, $value);
        }
    }
}

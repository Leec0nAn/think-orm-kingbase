<?php
declare(strict_types=1);

namespace think\db\connector;

use PDO;

/**
 * 保留当前物理连接的字符串化设置，弥补 PDO_KDB 无法读取该属性的限制。
 * 其他 PDO 行为直接继承，不改变 getAttribute() 的驱动支持范围。
 */
class KingbasePDO extends PDO
{
    private bool $kingbaseStringifyFetches = false;
    private ?string $kingbasePersistentKey = null;

    /** 同一进程内，PDO 持久池的多个对象共享取回属性；仅保存连接身份的摘要。 */
    private static array $kingbaseStringifyPools = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $persistent = $options[PDO::ATTR_PERSISTENT] ?? false;
        $poolName = is_string($persistent) && '' !== $persistent && !is_numeric($persistent);
        if ($poolName || (int) $persistent) {
            // 对齐 PDO 的持久池身份规则，不因增加子类而拆分或关闭原持久池。
            $key = 'PDO:DBH:DSN=' . $dsn . ':' . ($username ?? '') . ':' . ($password ?? '');
            $this->kingbasePersistentKey = hash('sha256', $key . ($poolName ? ':' . $persistent : ''));
        }
        if (array_key_exists(PDO::ATTR_STRINGIFY_FETCHES, $options ?? [])) {
            $this->rememberKingbaseStringify((bool) (int) $options[PDO::ATTR_STRINGIFY_FETCHES]);
        }
    }

    /** 仅在原生调用成功后记录；失败或抛异常时保留原状态。 */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $result = parent::setAttribute($attribute, $value);
        if ($result && PDO::ATTR_STRINGIFY_FETCHES === $attribute) {
            // PDO 按整数选项处理该属性，例如字符串 "false" 等效于 0。
            $this->rememberKingbaseStringify((bool) (int) $value);
        }
        return $result;
    }

    /** 返回本物理连接最后一次成功设置的字符串化状态。 */
    public function getKingbaseStringifyFetches(): bool
    {
        if (null !== $this->kingbasePersistentKey) {
            return self::$kingbaseStringifyPools[$this->kingbasePersistentKey] ?? false;
        }
        return $this->kingbaseStringifyFetches;
    }

    private function rememberKingbaseStringify(bool $value): void
    {
        $this->kingbaseStringifyFetches = $value;
        if (null !== $this->kingbasePersistentKey) {
            self::$kingbaseStringifyPools[$this->kingbasePersistentKey] = $value;
        }
    }
}

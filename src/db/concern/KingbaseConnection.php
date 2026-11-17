<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2025 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think\db\concern;

use PDO;

/**
 * KingbaseES 三种兼容模式连接器共用的 PDO_KDB 初始化逻辑。
 */
trait KingbaseConnection
{
    use KingbaseResult;

    /**
     * 已完成初始化的 PDO 对象，按对象哈希去重。
     *
     * @var array<string, bool>
     */
    protected array $kingbaseInitializedLinks = [];

    /**
     * 根据 ThinkORM 连接配置生成三个兼容模式共用的 PDO_KDB DSN。
     *
     * @param array $config 数据库连接配置
     *
     * @return string kdb: 协议 DSN
     */
    protected function parseDsn(array $config): string
    {
        $dsn = 'kdb:host=' . $config['hostname'] . ';dbname=' . $config['database'];

        if (!empty($config['hostport'])) {
            $dsn .= ';port=' . $config['hostport'];
        }

        return $dsn;
    }

    /** MySQL 结果转换需要跟随运行时设置；其余模式保留原 PDO 创建流程。 */
    protected function createPdo($dsn, $username, $password, $params)
    {
        if ('mysql' === $this->getExpectedKingbaseMode()) {
            return new \think\db\connector\KingbasePDO($dsn, $username, $password, $params);
        }

        return parent::createPdo($dsn, $username, $password, $params);
    }

    /**
     * 建立 PDO_KDB 连接并初始化 KingbaseES 会话。
     *
     * 在父连接器完成物理连接后校验驱动、数据库兼容模式并读取会话默认 Schema；连接失败时补充
     * 主机、端口及数据库名等诊断信息，同时保留 PDO 异常类型和安全错误信息，避免异常链泄露口令。
     *
     * @param array      $config         本次连接覆盖配置，为空时使用连接器默认配置
     * @param int        $linkNum        连接编号，用于读写分离或多连接场景
     * @param array|bool $autoConnection 自动连接配置或是否启用自动连接
     *
     * @throws \PDOException             物理连接失败
     * @throws \UnexpectedValueException 驱动或兼容模式不符合预期
     * @throws \InvalidArgumentException 当前会话的 Schema 标识符不合法
     *
     * @return PDO 已完成 KingbaseES 会话初始化的 PDO 对象
     */
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false): PDO
    {
        $mergedConfig = empty($config) ? $this->config : array_merge($this->config, $config);

        try {
            $pdo = parent::connect($config, $linkNum, $autoConnection);
            $this->initializeKingbaseSession($pdo);

            return $pdo;
        } catch (\PDOException $e) {
            $message = $this->formatKingbaseConnectionFailure($mergedConfig, $e);
            throw \think\db\connector\KingbaseConnectionDiagnostics::exception(
                $message,
                $e,
                (string) ($mergedConfig['password'] ?? '')
            );
        }
    }

    /**
     * 关闭全部数据库连接并清理 KingbaseES 会话派生状态。
     *
     * @return $this 当前连接器实例
     */
    public function close()
    {
        $this->kingbaseInitializedLinks = [];

        return parent::close();
    }

    /**
     * 获取当前连接器要求的 KingbaseES 兼容模式。
     *
     * @return string 兼容模式标识：mysql、oracle 或 sqlserver
     */
    public function getKingbaseMode(): string
    {
        return $this->getExpectedKingbaseMode();
    }

    /**
     * 从本次元数据查询实际使用的连接读取当前 Schema。
     *
     * 路由与 getPDOStatement 一致，包含写后读主库和事务固定连接；每次直接
     * 读取都确认会话状态，不复用另一物理连接留下的 Schema。
     *
     * @throws \InvalidArgumentException 当前 Schema 标识符不合法
     *
     * @return string 当前有效的 Schema 名称
     */
    public function getKingbaseSchema(): string
    {
        $this->initConnect($this->readMaster);
        return $this->validateKingbaseIdentifier(
            (string) $this->linkID->query('SELECT current_schema()')->fetchColumn(),
            'current schema'
        );
    }

    /**
     * 返回具体连接器实现的 KingbaseES 兼容模式。
     *
     * @return string 兼容模式标识
     */
    abstract protected function getExpectedKingbaseMode(): string;

    /**
     * 将 KingbaseES 特殊字段映射为正确的 PDO 参数绑定类型。
     *
     * 数组、范围、INTERVAL 和几何类型使用文本形式传输，避免其类型名中的 int
     * 被通用规则误判为整数；仅 MySQL 模式的二进制字段自动使用
     * PDO::PARAM_LOB。Oracle、SQL Server 模式沿用原连接器的二进制字段
     * 类型映射；业务显式指定的 PDO 绑定类型不受影响。
     *
     * @param string $type 数据库返回的原始字段类型
     *
     * @return string ThinkORM 内部字段类型
     */
    protected function getFieldType(string $type): string
    {
        $normalizedType = strtolower(trim($type));

        // point、int4range、interval 等复杂类型名包含 int。必须先按文本类型识别，
        // 否则通用规则会使用 PDO::PARAM_INT，将 “{1,2,3}” 或 “[1,10)” 静默转换为 0。
        if (1 === preg_match('/(?:\[\]\s*$|^_)/', $normalizedType)
            || 1 === preg_match('/(?:^|\b)(?:int4range|int8range|numrange|tsrange|tstzrange|daterange|range)(?:\b|$)/', $normalizedType)
            || 1 === preg_match('/^(?:interval|point|line|lseg|box|path|polygon|circle)(?:\b|\s|\()/i', $normalizedType)) {
            return 'string';
        }

        // 金仓将 MySQL 无符号整数报告为 uint2/uint4/uint8，统一按字符串绑定，
        // 避免超出 PHP_INT_MAX 的 uint8 值在 PDO_KDB 发送到服务端之前，
        // 因 PHP 整数转换而被截断。
        if (1 === preg_match('/^uint\d+$/i', $normalizedType)) {
            return 'bigint';
        }

        if (1 === preg_match('/^bit(?:\s+varying)?\s*\((\d+)\)/i', trim($type), $match)
            && (int) $match[1] > 1) {
            return 'kingbase_bit_string';
        }

        if ('mysql' === $this->getExpectedKingbaseMode()
            && 1 === preg_match('/blob|binary|bytea|\braw\b|\bimage\b/i', $type)) {
            return 'kingbase_lob';
        }

        return parent::getFieldType($type);
    }

    /**
     * 根据内部字段类型返回 PDO 参数绑定类型。
     *
     * @param string $type ThinkORM 内部字段类型
     *
     * @return int PDO 参数类型常量
     */
    public function getFieldBindType(string $type): int
    {
        return match ($type) {
            'kingbase_lob' => PDO::PARAM_LOB,
            'kingbase_bit_string' => PDO::PARAM_STR,
            default => parent::getFieldBindType($type),
        };
    }

    /**
     * 初始化并校验一个物理 PDO_KDB 连接。
     *
     * 每个 PDO 对象仅检查一次；确认驱动名、服务器兼容模式并读取默认 Schema。
     * 不主动设置 search_path，不要求不同物理连接的默认 Schema 相同。
     *
     * @param PDO $pdo 待初始化的物理连接
     *
     * @throws \UnexpectedValueException 驱动或兼容模式不符合预期
     * @throws \InvalidArgumentException Schema 标识符不合法
     *
     * @return void
     */
    protected function initializeKingbaseSession(PDO $pdo): void
    {
        $hash = spl_object_hash($pdo);
        if (isset($this->kingbaseInitializedLinks[$hash])) {
            return;
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ('kdb' !== $driver) {
            throw new \UnexpectedValueException('KingbaseES connector requires PDO_KDB, got PDO_' . $driver);
        }

        $serverMode = strtolower((string) $pdo
            ->query("SELECT current_setting('database_mode', true)")
            ->fetchColumn());
        $expectedMode = $this->getExpectedKingbaseMode();
        if ($expectedMode !== $serverMode) {
            throw new \UnexpectedValueException(
                'KES compatibility mode mismatch: configured ' . $expectedMode . ', server ' . $serverMode
            );
        }

        // search_path 由数据库控制，不应用旧版 schema 配置。
        $this->validateKingbaseIdentifier(
            (string) $pdo->query('SELECT current_schema()')->fetchColumn(),
            'current schema'
        );

        $this->kingbaseInitializedLinks[$hash] = true;
    }

    /**
     * 校验不带限定符的 KingbaseES 数据库对象标识符。
     *
     * 只允许字母或下划线开头，后续可包含字母、数字、下划线、美元符号和井号，
     * 确保会话返回的名称可供后续元数据读取使用。
     *
     * @param string $value 待校验的标识符
     * @param string $label 错误信息中使用的对象说明
     *
     * @throws \InvalidArgumentException 标识符格式不合法
     *
     * @return string 去除首尾空白后的合法标识符
     */
    protected function validateKingbaseIdentifier(string $value, string $label): string
    {
        $value = trim($value);
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_$#]*$/', $value)) {
            throw new \InvalidArgumentException('Invalid KES ' . $label . ': ' . $value);
        }

        return $value;
    }

    /**
     * 生成不泄露凭据的 KingbaseES 连接失败信息。
     *
     * @param array         $config    当前连接配置
     * @param \PDOException $exception 原始 PDO 连接异常
     *
     * @return string 单行且长度受限的诊断信息
     */
    protected function formatKingbaseConnectionFailure(array $config, \PDOException $exception): string
    {
        $details = [];
        foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database'] as $key => $label) {
            if (isset($config[$key]) && '' !== (string) $config[$key]) {
                $details[] = $label . '=' . $this->sanitizeKingbaseDiagnostic((string) $config[$key]);
            }
        }

        $message = $exception->getMessage();
        $password = (string) ($config['password'] ?? '');
        if ('' !== $password) {
            $message = str_replace($password, '***', $message);
        }

        return 'KES connection failed (' . ($details ? implode(', ', $details) : 'parameters unavailable') . '): '
            . $this->sanitizeKingbaseDiagnostic($message);
    }

    /**
     * 清理并限制连接诊断文本。
     *
     * 将换行和制表符替换为空格，并将文本限制在 500 字节内，便于日志和页面安全展示。
     *
     * @param string $value 原始诊断文本
     *
     * @return string 清理后的单行诊断文本
     */
    protected function sanitizeKingbaseDiagnostic(string $value): string
    {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));

        return strlen($value) > 500 ? substr($value, 0, 497) . '...' : $value;
    }
}

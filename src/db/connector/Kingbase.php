<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2025 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think\db\connector;

use Psr\SimpleCache\CacheInterface;
use think\db\BaseQuery;
use think\db\ConnectionInterface;
use think\DbManager;

/**
 * KingbaseES统一连接入口。
 *
 * 原有连接工厂。先读取服务端database_mode，再把所有数据库操作委托
 * 给对应的MySQL、Oracle或SQL Server兼容模式连接器。
 */
class Kingbase implements ConnectionInterface
{
    /** @var ConnectionInterface 实际承担数据库操作的模式专用连接器 */
    private ConnectionInterface $connector;

    /** @var string 自动探测并规范化后的兼容模式 */
    private string $mode;

    /**
     * 自动识别KingbaseES兼容模式并创建实际连接器。
     *
     * @param array $config ThinkORM连接配置
     */
    public function __construct(array $config = [])
    {
        $this->mode = KingbaseModeDetector::detect($config);
        $config['kingbase_mode'] = $this->mode;
        $config['type'] = KingbaseModeDetector::connectorType($this->mode);
        $class = $this->resolveConnectorClass($this->mode);
        $this->connector = new $class($config);
    }

    /**
     * 返回自动探测后使用的模式专用连接器。
     *
     */
    public function getResolvedConnector(): ConnectionInterface
    {
        return $this->connector;
    }

    /**
     * 返回服务端实际兼容模式。
     */
    public function getKingbaseMode(): string
    {
        return $this->mode;
    }

    /**
     * 将规范化模式映射为模式专用连接器类。
     *
     * @return class-string<ConnectionInterface>
     */
    private function resolveConnectorClass(string $mode): string
    {
        return match ($mode) {
            'mysql' => KingbaseMysql::class,
            'oracle' => KingbaseOracle::class,
            'sqlserver' => KingbaseSqlsrv::class,
            default => throw new \InvalidArgumentException('Unsupported KES database_mode: ' . $mode),
        };
    }

    /** {@inheritdoc} 将查询类名获取请求转交模式专用连接器。 */
    public function getQueryClass(): string
    {
        return $this->connector->getQueryClass();
    }

    /** {@inheritdoc} 将连接建立请求转交模式专用连接器。 */
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false)
    {
        return $this->connector->connect($config, $linkNum, $autoConnection);
    }

    /** {@inheritdoc} 将数据库管理器设置转交模式专用连接器。 */
    public function setDb(DbManager $db)
    {
        $this->connector->setDb($db);
    }

    /** {@inheritdoc} 将缓存对象设置转交模式专用连接器。 */
    public function setCache(CacheInterface $cache)
    {
        $this->connector->setCache($cache);
    }

    /** {@inheritdoc} 将连接配置读取请求转交模式专用连接器。 */
    public function getConfig(string $config = '')
    {
        return $this->connector->getConfig($config);
    }

    /**
     * 关闭实际连接器并保持统一入口对象不变。
     *
     * @return $this
     */
    public function close()
    {
        $this->connector->close();

        return $this;
    }

    /** {@inheritdoc} 将单条记录查询转交模式专用连接器。 */
    public function find(BaseQuery $query): array
    {
        return $this->connector->find($query);
    }

    /** {@inheritdoc} 将多条记录查询转交模式专用连接器。 */
    public function select(BaseQuery $query): array
    {
        return $this->connector->select($query);
    }

    /** {@inheritdoc} 将单条数据插入转交模式专用连接器。 */
    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        return $this->connector->insert($query, $getLastInsID);
    }

    /** {@inheritdoc} 将批量数据插入转交模式专用连接器。 */
    public function insertAll(BaseQuery $query, array $dataSet = []): int
    {
        return $this->connector->insertAll($query, $dataSet);
    }

    /** {@inheritdoc} 将数据更新转交模式专用连接器。 */
    public function update(BaseQuery $query): int
    {
        return $this->connector->update($query);
    }

    /** {@inheritdoc} 将数据删除转交模式专用连接器。 */
    public function delete(BaseQuery $query): int
    {
        return $this->connector->delete($query);
    }

    /** {@inheritdoc} 将单个字段值查询转交模式专用连接器。 */
    public function value(BaseQuery $query, string $field, $default = null)
    {
        return $this->connector->value($query, $field, $default);
    }

    /** {@inheritdoc} 将指定列数据查询转交模式专用连接器。 */
    public function column(BaseQuery $query, string|array $column, string $key = ''): array
    {
        return $this->connector->column($query, $column, $key);
    }

    /** {@inheritdoc} 将闭包事务处理转交模式专用连接器。 */
    public function transaction(callable $callback)
    {
        return $this->connector->transaction($callback);
    }

    /** {@inheritdoc} 将事务开始操作转交模式专用连接器。 */
    public function startTrans()
    {
        return $this->connector->startTrans();
    }

    /** {@inheritdoc} 将事务提交操作转交模式专用连接器。 */
    public function commit()
    {
        return $this->connector->commit();
    }

    /** {@inheritdoc} 将事务回滚操作转交模式专用连接器。 */
    public function rollback()
    {
        return $this->connector->rollback();
    }

    /** {@inheritdoc} 保留原连接器接受的表名及数组别名形式。 */
    public function getTableFields($tableName): array
    {
        return $this->connector->getTableFields($tableName);
    }

    /** {@inheritdoc} 将最近执行的 SQL 读取请求转交模式专用连接器。 */
    public function getLastSql(): string
    {
        return $this->connector->getLastSql();
    }

    /** {@inheritdoc} 将新增主键值读取请求转交模式专用连接器。 */
    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        return $this->connector->getLastInsID($query, $sequence);
    }

    /**
     * 转发ConnectionInterface之外的ThinkORM连接方法。
     *
     * Query、Model和Builder还会调用table、query、execute、newQuery、
     * getBuilder、getFields、getPk、pdo等连接器公开方法。统一入口通过
     * 此方法保持这些扩展接口与实际连接器完全一致。
     *
     * @param string $method    方法名
     * @param array  $arguments 参数列表
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $result = $this->connector->{$method}(...$arguments);

        return $result === $this->connector ? $this : $result;
    }
}

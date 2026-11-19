<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2025 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think\db\builder;

use think\db\BaseQuery as Query;
use think\db\Connection;
use think\db\Raw;

/**
 * KingbaseES MySQL 兼容模式 SQL 构造器。
 */
class KingbaseMysql extends Mysql
{
    /**
     * 为等值内连接的主表投影补充分组主键。
     *
     * 多态关联会按关联外键分组并返回主表全部字段。只有结构及物理目录能证明
     * 每个原分组只对应一个真实主键时，才保留原分组并补充该主键；不改查询状态、
     * HAVING、绑定值或软删除条件。离线SQL生成、分布式连接及未验证的复杂结构
     * 沿用原流程，避免使用另一物理连接的目录属性作为证明。
     */
    protected function parseGroup(Query $query, string|array $group): string
    {
        $options = $query->getOptions();
        $groups = is_string($group) ? explode(',', $group) : array_values($group);
        $joins = $options['join'] ?? [];
        if (!$query->getModel() || !empty($options['fetch_sql']) || count($groups) !== 1
            || !is_string($groups[0]) || count($joins) !== 1) {
            return parent::parseGroup($query, $group);
        }

        $main = $this->parseGroupTable($options['table'] ?? null, $options['alias'] ?? []);
        $join = reset($joins);
        if (!$main || !is_array($join) || count($join) !== 3
            || !in_array($join[1], ['', 'INNER'], true) || !is_string($join[2])
            || array_values($options['field'] ?? []) !== [$main[1] . '.*']) {
            return parent::parseGroup($query, $group);
        }

        $related = $this->parseGroupTable($join[0]);
        $identifier = '[A-Za-z_][A-Za-z0-9_]*';
        if (!$related || $main[1] === $related[1]
            || !preg_match('/\A\s*(' . $identifier . ')\.(' . $identifier . ')\s*=\s*(' . $identifier . ')\.(' . $identifier . ')\s*\z/', $join[2], $on)) {
            return parent::parseGroup($query, $group);
        }
        if ($on[1] === $related[1] && $on[3] === $main[1]) {
            $on = ['', $on[3], $on[4], $on[1], $on[2]];
        }
        if ($on[1] !== $main[1] || $on[3] !== $related[1]
            || trim($groups[0]) !== $related[1] . '.' . $on[4]) {
            return parent::parseGroup($query, $group);
        }

        $connection = $query->getConnection();
        if (!$connection instanceof \think\db\connector\KingbaseMysql
            || !empty($connection->getConfig('deploy'))
            || !$connection->canSupplementGroupByPrimaryKey($main[0], $on[2], $related[0], $on[4])) {
            return parent::parseGroup($query, $group);
        }
        $groups[] = $main[1] . '.' . $on[2];

        return parent::parseGroup($query, $groups);
    }

    /** 仅识别简单小写物理表名和普通别名，不猜测Raw、子查询及复杂引用。 */
    private function parseGroupTable($table, array $aliases = []): ?array
    {
        $alias = null;
        if (is_array($table)) {
            if (count($table) !== 1 || !is_string(key($table)) || !is_string(current($table))) {
                return null;
            }
            $alias = current($table);
            $table = key($table);
        }
        $identifier = '[A-Za-z_][A-Za-z0-9_]*';
        $tableName = '[a-z_][a-z0-9_]*';
        if (!is_string($table) || !preg_match('/\A' . $tableName . '(?:\.' . $tableName . ')?\z/', $table)) {
            return null;
        }
        $alias = $alias ?? ($aliases[$table] ?? (str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table));

        return is_string($alias) && preg_match('/\A' . $identifier . '\z/', $alias) ? [$table, $alias] : null;
    }

    /**
     * 生成写入参数占位符，适配整数列的布尔绑定。
     *
     * Model 的 bool 配置可能覆盖整数列的绑定类型；此时按实际字段绑定信息
     * 使用 1/0 整数参数，避免 PDO_KDB
     * 发送 t/f。真正的 BOOLEAN 列、NULL 和其他绑定类型仍沿用原处理。
     *
     * @param Query  $query 当前查询对象
     * @param string $key   字段名或 JSON 路径
     * @param mixed  $data  待绑定数据
     * @param array  $bind  字段绑定类型映射
     *
     * @return string SQL 参数占位表达式
     */
    protected function parseDataBind(Query $query, string $key, $data, array $bind = []): string
    {
        if (null !== $data && !($data instanceof Raw) && !str_contains($key, '->')
            && Connection::PARAM_BOOL === ($bind[$key] ?? null)) {
            // 读取连接器已有的字段缓存，不使用被 Model 类型配置覆盖的字段映射。
            $table = $query->getTable();
            $fieldBind = is_string($table) || (is_array($table) && 1 === count($table))
                ? $query->getConnection()->getTableInfo($table, 'bind') : [];
            if (Connection::PARAM_INT === ($fieldBind[$key] ?? null)) {
                $data = $data ? 1 : 0;
                $bind[$key] = Connection::PARAM_INT;
            }
        }

        return parent::parseDataBind($query, $key, $data, $bind);
    }
}

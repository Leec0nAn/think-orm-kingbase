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

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

/**
 * KingbaseES Oracle 兼容模式 SQL 构造器。
 */
class KingbaseOracle extends Oracle
{
    /**
     * 单表 Model 查询显式保留字段名，避免 enable_upper_colname 改变模型主键。
     *
     * 元数据中的字段名是目录值，不受 PDO 的列名大小写策略控制。为模型字段增加
     * 同名双引号别名，使返回数据仍使用元数据名称；普通 Query、关联查询、表达式
     * 和用户显式设置的别名继续使用原 Builder 行为，不统一转换结果键。
     */
    protected function parseField(Query $query, array $fields): string
    {
        $table = $query->getTable();
        if (!$query->getModel() || $query->getOption('join') || !is_string($table)
            || false !== strpbrk($table, ',()')) {
            return parent::parseField($query, $fields);
        }

        $names = $query->getTableFields();
        if (!$names) {
            return parent::parseField($query, $fields);
        }
        if (!$fields || ['*'] === $fields) {
            $fields = $names;
        }

        $parts = [];
        foreach ($fields as $key => $field) {
            if (is_int($key) && is_string($field) && in_array($field, $names, true)) {
                $parts[] = $this->parseKey($query, $field) . ' AS "' . str_replace('"', '""', $field) . '"';
            } else {
                $parts[] = parent::parseField($query, [$key => $field]);
            }
        }

        return implode(',', $parts);
    }

    /**
     * 使用 VALUES 语法构造多行 INSERT。
     *
     * PDO_KDB 无法在父 Oracle Builder 的 UNION ALL SELECT 批量写法中稳定推断
     * BLOB 等目标列的占位符类型，因此仅改写 SQL 结构；字段和值仍按原框架的
     * 数组迭代顺序处理。
     */
    public function insertAll(Query $query, array $dataSet): string
    {
        $options = $query->getOptions();
        $bind = $query->getFieldsBindType();

        if (empty($options['field']) || '*' == $options['field']) {
            $allowFields = array_keys($bind);
        } else {
            $allowFields = $options['field'];
        }

        $values = [];
        $insertFields = null;

        foreach ($dataSet as $data) {
            $data = $this->parseData($query, $data, $allowFields, $bind);

            if (null === $insertFields) {
                $insertFields = array_keys($data);
            }
            $values[] = '( ' . implode(',', array_values($data)) . ' )';
        }

        if (null === $insertFields) {
            return '';
        }

        $fields = [];
        foreach ($insertFields as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                'VALUES ' . implode(' , ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }

    /** 使用 VALUES 语法构造固定字段批量 INSERT。 */
    public function insertAllByKeys(Query $query, array $keys, array $datas): string
    {
        $options = $query->getOptions();
        $bind = $query->getFieldsBindType();
        $fields = [];
        $values = [];

        foreach ($keys as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        foreach ($datas as $data) {
            foreach ($data as $key => &$val) {
                $field = $keys[$key];

                if (!$query->isAutoBind()) {
                    $val = Connection::PARAM_STR == $bind[$field] ? '\'' . $val . '\'' : $val;
                } else {
                    $val = $this->parseDataBind($query, $field, $val, $bind);
                }
            }

            $values[] = '( ' . implode(',', $data) . ' )';
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                'VALUES ' . implode(' , ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }
}

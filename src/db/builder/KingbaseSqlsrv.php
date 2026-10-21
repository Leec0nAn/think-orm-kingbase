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
 * KingbaseES SQL Server 兼容模式 SQL 构造器。
 */
class KingbaseSqlsrv extends Sqlsrv
{
    /**
     * A scalar subquery must not expose SQL Server pagination's ROW_NUMBER.
     */
    protected string $plainSelectSql = 'SELECT%DISTINCT%%EXTRA% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%UNION%%ORDER%%LIMIT% %LOCK%%COMMENT%';

    /** 当前是否正在构造使用 KingbaseES 原生分页的 SELECT。 */
    protected bool $kingbaseSelect = false;

    /**
     * 使用 KingbaseES 原生分页构造 SELECT，保留行锁及单列子查询语义。
     *
     * 不添加 ROW_NUMBER 辅助列，避免关联聚合或 INSERT SELECT 列数变化；所有 SELECT
     * 均保留 LOCK、UNION 和 ORDER。finally 恢复状态，支持重入和构造异常后的复用。
     *
     * @param Query $query 当前查询对象
     * @param bool  $one   是否仅查询一条记录
     *
     * @return string 完整 SELECT SQL
     */
    public function select(Query $query, bool $one = false): string
    {
        $selectSql = $this->selectSql;
        $selectMode = $this->kingbaseSelect;
        $this->selectSql = $this->plainSelectSql;
        $this->kingbaseSelect = true;
        try {
            return parent::select($query, $one);
        } finally {
            $this->selectSql = $selectSql;
            $this->kingbaseSelect = $selectMode;
        }
    }

    /**
     * SELECT 使用 LIMIT/OFFSET，写语句继续沿用父方言的处理。
     *
     * @param Query $query 当前查询对象
     * @param string $limit 数量或“偏移量,数量”
     * @return string 分页子句
     * @throws \InvalidArgumentException 分页参数不是非负整数
     */
    protected function parseLimit(Query $query, string $limit): string
    {
        if (!$this->kingbaseSelect) {
            return parent::parseLimit($query, $limit);
        }
        if ('' === $limit) {
            return '';
        }
        if (!preg_match('/^\d+(?:,\d+)?$/D', $limit)) {
            throw new \InvalidArgumentException('Invalid KES pagination: ' . $limit);
        }
        $parts = explode(',', $limit);
        return count($parts) === 2
            ? ' LIMIT ' . $parts[1] . ' OFFSET ' . $parts[0]
            : ' LIMIT ' . $parts[0];
    }

    /**
     * 原生分页不需要默认随机排序，显式排序仍使用父方言。
     *
     * @param Query $query 当前查询对象
     * @param array $order 排序配置
     * @return string 排序子句
     */
    protected function parseOrder(Query $query, array $order): string
    {
        return $this->kingbaseSelect && empty($order) ? '' : parent::parseOrder($query, $order);
    }

    /**
     * 构造 INSERT SELECT，避免父实现永久修改共享查询模板。
     *
     * @param Query $query 源查询对象
     * @param array $fields 目标字段列表
     * @param string $table 目标表名
     * @return string INSERT SELECT SQL，支持源查询排序与分页
     */
    public function selectInsert(Query $query, array $fields, string $table): string
    {
        $fields = array_map(fn ($field) => $this->parseKey($query, $field, true), $fields);
        return 'INSERT INTO ' . $this->parseTable($query, $table)
            . ' (' . implode(',', $fields) . ') ' . $this->select($query);
    }

    /**
     * 使用 VALUES 语法构造多行 INSERT。
     *
     * 避免父 SQL Server 方言的 UNION SELECT 批量写法，使 KingbaseES 能依据目标列
     * 正确推断每个占位符的数据类型。
     *
     * @param Query $query   当前查询对象
     * @param array $dataSet 多行待插入数据
     *
     * @return string 完整批量 INSERT SQL
     * @throws \InvalidArgumentException 各行字段集合不一致或为空
     */
    public function insertAll(Query $query, array $dataSet): string
    {
        $options = $query->getOptions();
        $bind    = $query->getFieldsBindType();

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
            if (!$insertFields || count($data) !== count($insertFields)
                || array_diff($insertFields, array_keys($data))) {
                throw new \InvalidArgumentException('KES batch insert requires the same non-empty fields in every row');
            }
            $row = [];
            foreach ($insertFields as $field) {
                $row[] = $data[$field];
            }
            $values[] = '( ' . implode(',', $row) . ' )';
        }

        if (null === $insertFields) {
            return '';
        }
        $fields = [];
        foreach ($insertFields as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        return str_replace(
            ['%TABLE%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                $this->parseTable($query, $options['table']),
                implode(' , ', $fields),
                'VALUES ' . implode(' , ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }

    /**
     * 按固定字段集合使用 VALUES 语法构造多行 INSERT。
     *
     * 根据查询是否启用自动绑定生成字面量或参数占位符，并确保所有行复用同一字段顺序。
     *
     * @param Query $query 当前查询对象
     * @param array $keys  固定字段名列表
     * @param array $datas 按字段顺序组织的多行数据
     *
     * @return string 完整批量 INSERT SQL
     */
    public function insertAllByKeys(Query $query, array $keys, array $datas): string
    {
        $options = $query->getOptions();
        $bind    = $query->getFieldsBindType();
        $fields  = [];
        $values  = [];

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
            ['%TABLE%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                $this->parseTable($query, $options['table']),
                implode(' , ', $fields),
                'VALUES ' . implode(' , ', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }
}

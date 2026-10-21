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
use think\db\exception\DbException as Exception;
use think\db\Raw;

/**
 * KingbaseES Oracle 兼容模式 SQL 构造器。
 */
class KingbaseOracle extends Oracle
{
    /**
     * A scalar subquery must not expose Oracle's synthetic ROWNUM column.
     */
    protected string $plainSelectSql = 'SELECT%DISTINCT%%EXTRA% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%UNION%%ORDER%%LIMIT% %LOCK%%COMMENT%';

    /** 当前是否正在构造使用 KingbaseES 原生分页的 SELECT。 */
    protected bool $kingbaseSelect = false;

    /**
     * 使用 KingbaseES 原生分页构造 SELECT，保留行锁及单列子查询语义。
     *
     * 不添加 NUMROW 辅助列，避免关联聚合或 INSERT SELECT 列数变化；所有 SELECT
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
     * 构造 Oracle 兼容模式 INSERT，并将 REPLACE 请求降级为普通 INSERT。
     *
     * Oracle 兼容模式不支持 REPLACE。ThinkORM 写入多对多中间表时可能内部启用
     * replace 选项，因此生成 SQL 期间临时关闭该选项，随后恢复；重复数据仍由
     * 中间表的主键或唯一约束阻止。
     *
     * @param Query $query 当前查询对象
     *
     * @return string 完整 INSERT SQL
     */
    public function insert(Query $query): string
    {
        $replace = (bool) $query->getOption('replace');
        if (!$replace) {
            return parent::insert($query);
        }

        $query->setOption('replace', false);
        try {
            return parent::insert($query);
        } finally {
            $query->setOption('replace', true);
        }
    }

    /**
     * 生成 Oracle 兼容模式随机排序表达式。
     *
     * KingbaseES Oracle 兼容模式未暴露 DBMS_RANDOM 包，因此使用 random()。
     *
     * @param Query $query 当前查询对象，保留父方法接口一致性
     *
     * @return string random() 表达式
     */
    protected function parseRand(Query $query): string
    {
        return 'random()';
    }

    /**
     * 解析字段和表标识符，并适配 Oracle 未引用对象名的大写规则。
     *
     * 支持整数、Raw 表达式、JSON 路径、表别名及严格模式校验；普通字段和表名
     * 转换为大写后使用双引号引用。
     *
     * @param Query          $query  当前查询对象
     * @param string|int|Raw $key    字段名、数字或 Raw 表达式
     * @param bool           $strict 是否启用标识符严格校验
     *
     * @throws Exception 严格模式下标识符包含不支持的内容
     *
     * @return string 可安全拼入 SQL 的标识符或表达式
     */
    public function parseKey(Query $query, string|int|Raw $key, bool $strict = false): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        if ($key instanceof Raw) {
            return $this->parseRaw($query, $key);
        }

        $key = trim($key);

        if (str_contains($key, '->') && !str_contains($key, '(')) {
            [$field, $name] = explode('->', $key, 2);

            return $this->parseKey($query, $field, true) . '."' . strtoupper($name) . '"';
        }

        if (str_contains($key, '.') && !preg_match('/[,\'\"\(\)\[\s]/', $key)) {
            [$table, $key] = explode('.', $key, 2);

            $alias = $query->getOption('alias');

            if ('__TABLE__' == $table) {
                $table = $query->getOption('table');
                $table = is_array($table) ? array_shift($table) : $table;
            }

            if (isset($alias[$table])) {
                $table = $alias[$table];
            }
        }

        if ($strict && !preg_match('/^[\w\.\*]+$/', $key)) {
            throw new Exception('not support data:' . $key);
        }

        if ('*' != $key && !preg_match('/[,\'\"\*\(\)\[.\s]/', $key)) {
            $key = '"' . strtoupper($key) . '"';
        }

        if (isset($table)) {
            $key = '"' . strtoupper($table) . '".' . $key;
        }

        return $key;
    }
}

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

use PDO;
use think\db\BaseQuery;
use think\db\concern\KingbaseConnection;

/**
 * KingbaseES Oracle 兼容模式数据库连接器。
 */
class KingbaseOracle extends Oracle
{
    use KingbaseConnection;

    /**
     * 按实际 Schema 读取 Oracle 兼容模式的字段元数据。
     *
     * KES 的 USER_* 和 ALL_* 视图按对象所有者组织数据；同一所有者可以拥有多个
     * Schema，不能将 OWNER 当作 Schema，也不能仅按表名合并不同 Schema 的列。
     * 字段和主键使用同一物理 Schema/表范围。公开 autoinc 沿用原 Oracle
     * 组件的主键标记。
     *
     * 字段名保留目录中的原始大小写。目录值与查询结果列名不是同一层面的信息；
     * 单表 Model SELECT 由模式 Builder 使用显式同名别名保持两侧一致，避免
     * enable_upper_colname 改变返回键后丢失模型主键。普通 Query 保留服务端行为。
     *
     * @param string $tableName 表名，可包含 Schema 和别名
     *
     * @return array 以实际大小写字段名为键的 ThinkORM 字段元数据
     */
    public function getFields(string $tableName): array
    {
        [$tableName] = explode(' ', $tableName);
        [$schema, $tableName] = $this->parseOracleTable($tableName);
        $schema = $schema ?? $this->getKingbaseSchema();
        $schemaValue = $this->quoteString($schema);
        $tableValue = $this->quoteString($tableName);

        $sql = <<<SQL
SELECT
    c.column_name AS "field",
    c.data_type AS "type",
    c.is_nullable AS "is_nullable",
    c.column_default AS "default",
    CASE WHEN pk.column_name IS NOT NULL THEN 1 ELSE 0 END AS "primary"
FROM
    information_schema.columns c
    LEFT JOIN (
        SELECT
            kcu.table_catalog,
            kcu.table_schema,
            kcu.table_name,
            kcu.column_name
        FROM
            information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_catalog = kcu.constraint_catalog
             AND tc.constraint_schema = kcu.constraint_schema
             AND tc.constraint_name = kcu.constraint_name
             AND tc.table_catalog = kcu.table_catalog
             AND tc.table_schema = kcu.table_schema
             AND tc.table_name = kcu.table_name
        WHERE
            tc.constraint_type = 'PRIMARY KEY'
    ) pk
      ON c.table_catalog = pk.table_catalog
     AND c.table_schema = pk.table_schema
     AND c.table_name = pk.table_name
     AND c.column_name = pk.column_name
WHERE
    lower(c.table_schema) = lower({$schemaValue})
    AND lower(c.table_name) = lower({$tableValue})
ORDER BY
    c.ordinal_position
SQL;

        $pdo = $this->getPDOStatement($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info = [];

        foreach ($result as $val) {
            $val = array_change_key_case($val);
            $field = $val['field'];

            $info[$field] = [
                'name' => $field,
                'type' => $val['type'],
                'notnull' => 'NO' === strtoupper((string) $val['is_nullable']),
                'default' => $val['default'],
                'primary' => (bool) $val['primary'],
                'autoinc' => (bool) $val['primary'],
            ];
        }

        return $this->fieldCase($info);
    }

    /**
     * 沿用原 Oracle 序列读取流程，仅对齐 PDO_OCI 的字符串返回类型。
     *
     * 序列选项、查询语句及异常处理均交给父连接器；不额外推断或改写序列名。
     *
     * @param BaseQuery   $query    当前查询对象，保留 ThinkORM 接口一致性
     * @param string|null $sequence 显式指定的序列名，可包含 Owner
     *
     * @return string|false|null 序列当前值，空结果沿用父连接器返回值
     */
    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        $value = parent::getLastInsID($query, $sequence);

        return false === $value || null === $value ? $value : (string) $value;
    }

    /**
     * 拆分并规范化 Oracle 风格的 Schema 限定表名。
     *
     * @param string $tableName 表名，可使用 schema.table 形式
     *
     * @return array{0:string|null,1:string} 实际 Schema 与表名；未限定时首项为 null
     */
    protected function parseOracleTable(string $tableName): array
    {
        $tableName = trim($tableName, '`');

        if (str_contains($tableName, '.')) {
            [$owner, $tableName] = explode('.', $tableName, 2);

            return [
                $this->normalizeOracleName($owner),
                $this->normalizeOracleName($tableName),
            ];
        }

        return [null, $this->normalizeOracleName($tableName)];
    }

    /**
     * 规范化 Oracle 对象名的大小写。
     *
     * 双引号包围的名称保留原大小写并移除外层引号，未加引号的名称转换为大写。
     *
     * @param string $name 原始对象名
     *
     * @return string 规范化后的对象名
     */
    protected function normalizeOracleName(string $name): string
    {
        $name = trim($name);

        if (str_starts_with($name, '"') && str_ends_with($name, '"')) {
            return substr($name, 1, -1);
        }

        return strtoupper(trim($name, '"'));
    }

    /**
     * 使用当前 PDO_KDB 连接转义元数据查询中的字符串字面量。
     *
     * @param string $value 待转义字符串
     *
     * @return string 包含引号的安全 SQL 字面量
     */
    protected function quoteString(string $value): string
    {
        $this->initConnect($this->readMaster);

        return $this->linkID->quote($value);
    }

    /**
     * 返回本连接器要求的 KingbaseES 兼容模式。
     *
     * @return string 固定返回 oracle
     */
    protected function getExpectedKingbaseMode(): string
    {
        return 'oracle';
    }
}

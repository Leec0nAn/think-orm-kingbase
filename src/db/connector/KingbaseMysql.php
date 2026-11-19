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
 * KingbaseES MySQL 兼容模式数据库连接器，表名列表继承原 Mysql 查询。
 */
class KingbaseMysql extends Mysql
{
    use KingbaseConnection;

    /**
     * 证明等值内连接可以在原分组外键后补充主表主键。
     *
     * 仅接受无继承、无分区的普通表，以及真实有效、不可延迟的单列整数主键；
     * 关联列必须为相同物理整数类型且非空。表名按实际查询连接的搜索路径解析。
     * 不扩充公开字段结构，也不缓存证明结果，以免同名表重建后沿用旧属性。
     *
     * @internal 由金仓MySQL构造器在结构检查通过后调用
     */
    public function canSupplementGroupByPrimaryKey(string $table, string $primaryKey, string $joinedTable, string $foreignKey): bool
    {
        $sql = <<<'SQL'
SELECT 1
FROM pg_catalog.pg_class p
JOIN pg_catalog.pg_attribute a ON a.attrelid = p.oid AND a.attname = :pk
JOIN pg_catalog.pg_constraint c ON c.conrelid = p.oid AND c.contype = 'p'
JOIN pg_catalog.pg_index i ON i.indexrelid = c.conindid
JOIN pg_catalog.pg_class f ON f.oid = pg_catalog.to_regclass(:joined_table)
JOIN pg_catalog.pg_attribute b ON b.attrelid = f.oid AND b.attname = :fk
WHERE p.oid = pg_catalog.to_regclass(:main_table)
  AND p.relkind = 'r' AND f.relkind = 'r'
  AND NOT c.condeferrable AND c.convalidated
  AND pg_catalog.array_length(c.conkey, 1) = 1 AND c.conkey[1] = a.attnum
  AND i.indisvalid AND i.indisready AND i.indimmediate
  AND a.attnum > 0 AND b.attnum > 0 AND NOT a.attisdropped AND NOT b.attisdropped
  AND a.attnotnull AND b.attnotnull AND a.atttypid = b.atttypid
  AND a.atttypid IN ('pg_catalog.int2'::pg_catalog.regtype,
                    'pg_catalog.int4'::pg_catalog.regtype,
                    'pg_catalog.int8'::pg_catalog.regtype)
  AND NOT EXISTS (
      SELECT 1 FROM pg_catalog.pg_inherits h
      WHERE h.inhparent IN (p.oid, f.oid) OR h.inhrelid IN (p.oid, f.oid)
  )
SQL;
        $statement = $this->getPDOStatement($sql, [
            'main_table' => [$table, PDO::PARAM_STR], 'pk' => [$primaryKey, PDO::PARAM_STR],
            'joined_table' => [$joinedTable, PDO::PARAM_STR], 'fk' => [$foreignKey, PDO::PARAM_STR],
        ]);
        $result = false !== $statement->fetchColumn();
        $statement->closeCursor();

        return $result;
    }

    /**
     * 将绑定值渲染为 KingbaseES MySQL 模式可执行的 SQL 字面量。
     *
     * withCount 等流程会把 fetchSql 生成的子查询嵌入实际 SQL。原 Mysql 的
     * addslashes 转义依赖普通字符串的反斜杠规则，此处显式使用 E 字符串，
     * 使转义不受 standard_conforming_strings 开关影响。保留原框架的类型
     * 处理及占位符替换规则，不建立连接，避免改变离线生成和 SQL 日志流程。
     *
     * @param string $sql  带参数绑定的 SQL 语句
     * @param array  $bind 参数绑定列表
     *
     * @return string 渲染后的 SQL
     */
    public function getRealSql(string $sql, array $bind = []): string
    {
        foreach ($bind as $key => $val) {
            $value = strval(is_array($val) ? $val[0] : $val);
            $type = is_array($val) ? $val[1] : self::PARAM_STR;

            if (self::PARAM_FLOAT == $type || self::PARAM_STR == $type) {
                $value = "E'" . addslashes($value) . "'";
            } elseif (self::PARAM_INT == $type && '' === $value) {
                $value = '0';
            }

            $sql = is_numeric($key) ?
                substr_replace($sql, $value, strpos($sql, '?'), 1) :
                str_replace(
                    [':' . $key . ' ', ':' . $key . ',', ':' . $key . ')'],
                    [$value . ' ', $value . ',', $value . ')'],
                    $sql . ' '
                );
        }

        return rtrim($sql);
    }

    /**
     * 保留 cursor(true) 的逐行读取调用，不设置 PDO_MYSQL 专属缓冲属性。
     *
     * PDO_KDB 没有经验证的 MYSQL_ATTR_USE_BUFFERED_QUERY 对应能力；此处沿用
     * 现有 getCursor() 及结果转换流程，不依赖 PDO_MYSQL，也不承诺驱动级无缓冲。
     *
     * @return \Generator
     */
    protected function cursorUnbuffered(BaseQuery $query, string $sql)
    {
        yield from $this->getCursor($query, $sql, $query->getModel());
    }

    /**
     * 读取 MySQL 兼容模式下的数据表字段元数据。
     *
     * 从 information_schema 及系统目录中组合字段类型、可空性、默认值、主键、
     * 自增序列和注释，并转换成 ThinkORM 统一字段结构。
     *
     * @param string $tableName 表名，可包含 Schema 和别名
     *
     * @return array 以字段名为键的 ThinkORM 字段元数据
     */
    public function getFields(string $tableName): array
    {
        [$tableName] = explode(' ', $tableName);
        $tableName = trim($tableName, '`"');

        if (str_contains($tableName, '.')) {
            [$schema, $tableName] = explode('.', $tableName, 2);
            $schema = trim($schema, '`"');
            $tableName = trim($tableName, '`"');
        } else {
            $schema = $this->getKingbaseSchema();
        }

        $schemaValue = $this->quoteString($schema);
        $tableValue = $this->quoteString($tableName);

        $sql = <<<SQL
SELECT
    c.column_name AS "field",
    c.data_type AS "type",
    format_type(a.atttypid, a.atttypmod) AS "full_type",
    c.is_nullable AS "is_nullable",
    c.column_default AS "default",
    CASE WHEN pk.column_name IS NOT NULL THEN 1 ELSE 0 END AS "primary",
    a.attidentity AS "attidentity",
    pg_get_serial_sequence(format('%I.%I', n.nspname, cls.relname), a.attname) AS "serial_sequence",
    d.description AS "comment"
FROM
    information_schema.columns c
    LEFT JOIN (
        SELECT
            kcu.table_schema,
            kcu.table_name,
            kcu.column_name
        FROM
            information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
             AND tc.table_name = kcu.table_name
        WHERE
            tc.constraint_type = 'PRIMARY KEY'
    ) pk
      ON c.table_schema = pk.table_schema
     AND c.table_name = pk.table_name
     AND c.column_name = pk.column_name
    LEFT JOIN pg_namespace n
      ON n.nspname = c.table_schema
    LEFT JOIN pg_class cls
      ON cls.relnamespace = n.oid
     AND cls.relname = c.table_name
    LEFT JOIN pg_attribute a
      ON a.attrelid = cls.oid
     AND a.attname = c.column_name
    LEFT JOIN pg_description d
      ON d.objoid = cls.oid
     AND d.objsubid = a.attnum
WHERE
    c.table_schema = {$schemaValue}
    AND c.table_name = {$tableValue}
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
                'type' => $val['full_type'] ?: $val['type'],
                'notnull' => 'NO' === strtoupper((string) $val['is_nullable']),
                'default' => $this->normalizeDefaultValue($val['default']),
                'primary' => (bool) $val['primary'],
                'autoinc' => '' !== (string) $val['attidentity'] || !empty($val['serial_sequence']),
                'comment' => $val['comment'],
            ];
        }

        return $this->fieldCase($info);
    }

    /**
     * 将目录中的纯常量默认值转换为原 Mysql getFields() 的公开值。
     *
     * 只解析完整字符串、数字和 NULL；函数、运算、未知或可能改变值的类型转换
     * 一律保留表达式原文。此方法不执行默认 SQL，也不经过 PHP int/float，避免
     * 大整数及小数精度丢失。普通 SQL 字符串保留反斜杠，仅 E 字符串解码转义。
     *
     * @param mixed $value information_schema.columns.column_default
     * @return mixed 常量值或未改动的默认表达式
     */
    protected function normalizeDefaultValue($value)
    {
        if (null === $value) {
            return null;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        if (!is_string($value)) {
            return $value;
        }

        $sql = trim($value);
        $typeSchema = '(?:(?:(?i:pg_catalog)|"pg_catalog")\s*\.\s*)?';
        $textType = $typeSchema . '(?:(?i:text|varchar|character\s+varying)|"text"|"varchar")';
        $integerType = $typeSchema . '(?i:int2|int4|int8|smallint|integer|bigint|uint2|uint4|uint8)';
        $textCast = '(?:\s*::\s*' . $textType . ')?';

        if (preg_match("~\\A'((?:''|[^'])*)'" . $textCast . '\\z~s', $sql, $match)) {
            return str_replace("''", "'", $match[1]);
        }
        $escapePattern = <<<'REGEX'
~\A[Ee]'((?:''|\\.|[^'\\])*)'
REGEX;
        if (preg_match($escapePattern . $textCast . '\z~s', $sql, $match)) {
            $decoded = $this->decodeEscapeDefault(str_replace("''", "'", $match[1]));
            return null === $decoded ? $value : $decoded;
        }
        if (preg_match('~\A(?i:NULL)(?:\s*::\s*(?:' . $textType . '|' . $integerType . '))?\z~', $sql)) {
            return null;
        }
        if (preg_match('~\A([+-]?\d+)(?:\s*::\s*' . $integerType . ')?\z~', $sql, $match)
            || preg_match("~\\A'([+-]?\\d+)'\\s*::\\s*" . $integerType . '\z~', $sql, $match)) {
            $digits = ltrim($match[1], '+-0');
            return '' === $digits ? '0' : (str_starts_with($match[1], '-') ? '-' : '') . $digits;
        }
        if (preg_match('~\A[+-]?(?:\d+\.\d*|\.\d+|\d+)(?:[eE][+-]?\d+)?\z~', $sql)) {
            return $sql;
        }

        return $value;
    }

    /** 解码目录明确标为 E 字符串的转义；不认识的形式保留原表达式。 */
    private function decodeEscapeDefault(string $body): ?string
    {
        $value = '';
        $simple = ['b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t", "'" => "'", '"' => '"', '\\' => '\\'];
        for ($i = 0, $length = strlen($body); $i < $length; ++$i) {
            if ('\\' !== $body[$i]) {
                $value .= $body[$i];
                continue;
            }
            if (++$i >= $length) {
                return null;
            }
            $escape = $body[$i];
            if (isset($simple[$escape])) {
                $value .= $simple[$escape];
            } elseif (preg_match('/\A[0-7]{1,3}/', substr($body, $i), $match)) {
                $byte = octdec($match[0]);
                if ($byte > 255) {
                    return null;
                }
                $value .= chr($byte);
                $i += strlen($match[0]) - 1;
            } elseif ('x' === $escape && preg_match('/\A[0-9a-fA-F]{1,2}/', substr($body, $i + 1), $match)) {
                $value .= chr(hexdec($match[0]));
                $i += strlen($match[0]);
            } else {
                return null;
            }
        }
        return $value;
    }

    /**
     * 返回本连接器要求的 KingbaseES 兼容模式。
     *
     * @return string 固定返回 mysql
     */
    protected function getExpectedKingbaseMode(): string
    {
        return 'mysql';
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
}

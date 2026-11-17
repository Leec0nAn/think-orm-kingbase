<?php

// KingbaseES 查询结果类型转换；保留原查询和缓存控制流程。
declare(strict_types=1);

namespace think\db\concern;

use PDO;
use PDOStatement;
use think\db\BaseQuery;

trait KingbaseResult
{
    /**
     * 根据结果列而非字段值猜测类型；转换发生在缓存及模型字段转换之前。
     * 原始 PDOStatement 接口、Oracle LOB 及未知列类型不作额外处理。
     */
    protected function normalizeKingbaseRows(array $rows, ?PDOStatement $statement = null, ?array &$columns = null): array
    {
        if (empty($rows) || 'oracle' === $this->getExpectedKingbaseMode() || !$this->linkID) {
            return $rows;
        }
        $fetchType = null === $statement ? $this->fetchType : PDO::FETCH_ASSOC;
        if (!in_array($fetchType, [PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH], true)) {
            return $rows;
        }
        $streams = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $columns = $columns ?? $this->getKingbaseResultColumns($statement ?? $this->PDOStatement);
            foreach ($row as $key => $value) {
                $map = PDO::FETCH_NUM === $fetchType ? 'position' : (PDO::FETCH_BOTH === $fetchType ? 'both' : 'named');
                $rule = $columns[$map][$key] ?? '';
                $rows[$index][$key] = $this->normalizeKingbaseValue($value, $rule, $streams);
            }
        }

        return $rows;
    }

    /** 同名别名以最后一列为准，包括后列为未知类型时覆盖先前转换规则。 */
    protected function getKingbaseResultColumns(PDOStatement $statement): array
    {
        $columns = ['named' => [], 'position' => [], 'both' => []];
        if ('oracle' === $this->getExpectedKingbaseMode() || !$this->linkID) {
            return $columns;
        }
        $case = $this->linkID->getAttribute(PDO::ATTR_CASE);
        $unknownNames = false;
        for ($i = 0, $count = $statement->columnCount(); $i < $count; ++$i) {
            try {
                $meta = $statement->getColumnMeta($i);
            } catch (\PDOException $exception) {
                // 可选的列元信息不可用时，不猜测查询结果的类型。
                $meta = false;
            }
            $rule = $this->getKingbaseResultRule($meta);
            $columns['position'][$i] = $rule;
            // FETCH_BOTH按列依次写数字位置和别名，后列可以覆盖先列数字键。
            $columns['both'][$i] = $rule;
            if (is_array($meta) && isset($meta['name'])) {
                $name = $meta['name'];
                $name = PDO::CASE_LOWER === $case ? strtolower($name)
                    : (PDO::CASE_UPPER === $case ? strtoupper($name) : $name);
                $columns['named'][$name] = $rule;
                $columns['both'][$name] = $rule;
            } else {
                // 无名称的后列可能覆盖前列别名，不能继续套用旧别名规则。
                $unknownNames = true;
            }
        }
        if ($unknownNames) {
            $columns['named'] = [];
            $columns['both'] = [];
        }

        return $columns;
    }

    protected function getKingbaseResultRule($meta): string
    {
        if (!is_array($meta)) {
            return '';
        }
        $type = strtolower((string) ($meta['native_type'] ?? ''));
        $mode = $this->getExpectedKingbaseMode();
        if ('mysql' === $mode) {
            if ($this->isMysqlBlobColumn($meta)) {
                return 'blob';
            }
            return match ($type) {
                // FLOAT(M,D) 会返回非负的 precision，其固定小数位数规则
                // 不能被普通 FLOAT 的舍入规则替代。
                'float4' => -1 === (int) ($meta['precision'] ?? -1)
                    && isset($meta['kdb:table_oid']) && (int) $meta['kdb:table_oid'] > 0
                    ? 'mysqlFloat4' : 'mysqlFloat',
                'float8' => 'mysqlFloat',
                'bool' => 'mysqlBool',
                'uint8' => 'mysqlUnsigned',
                'bit' => 'mysqlBit',
                default => '',
            };
        }
        if ('sqlserver' === $mode && in_array($type,
            ['tinyint', 'smallint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'], true)) {
            $params = $this->config['params'] ?? [];
            if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')
                && !empty($params[constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')])) {
                return '';
            }
            return 'sqlsrvInteger';
        }

        return '';
    }

    /** NULL、文本、精确小数及未知值保持原样；不经浮点数中转大整数。 */
    protected function normalizeKingbaseValue($value, string $rule, array &$streams)
    {
        if (null === $value || '' === $rule) {
            return $value;
        }
        if ('blob' === $rule) {
            if (is_resource($value) && 'stream' === get_resource_type($value)) {
                $id = (int) $value;
                if (!array_key_exists($id, $streams)) {
                    $streams[$id] = $this->readMysqlBlobStream($value);
                }
                return $streams[$id];
            }
            return $value;
        }
        if ('sqlsrvInteger' === $rule) {
            return is_int($value) ? (string) $value : $value;
        }
        $stringify = $this->kingbaseStringifyFetches();
        if ('mysqlFloat4' === $rule) {
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                return $value;
            }
            // PDO_MYSQL/mysqlnd 将单精度值按六位有效数字返回。先还原 float32，
            // 再执行舍入，避免 PDO_KDB 返回的简短小数文本影响
            // 恰好位于舍入边界的数值判断。
            $single = unpack('fvalue', pack('f', (float) $value))['value'];
            if (!is_finite($single)) {
                return $value;
            }
            $number = (float) sprintf('%.6H', $single);
            return $stringify ? (string) $number : $number;
        }
        if ('mysqlFloat' === $rule) {
            return !$stringify && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
                ? (float) $value : $value;
        }
        if ('mysqlBool' === $rule) {
            if (is_bool($value) || in_array($value, ['t', 'f', '1', '0'], true)) {
                $integer = true === $value || 't' === $value || '1' === $value ? 1 : 0;
                return $stringify ? (string) $integer : $integer;
            }
            return $value;
        }
        if ('mysqlBit' === $rule && is_bool($value)) {
            return $stringify ? ($value ? '1' : '0') : ($value ? 1 : 0);
        }
        if ('mysqlBit' === $rule && is_string($value) && preg_match('/^0x([0-9a-f]{1,16})$/iD', $value, $matches)) {
            $value = $this->kingbaseHexToDecimal($matches[1]);
        }
        if (in_array($rule, ['mysqlBit', 'mysqlUnsigned'], true)) {
            if (is_int($value)) {
                return $stringify ? (string) $value : $value;
            }
            if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
                $decimal = ltrim($value, '0');
                $decimal = '' === $decimal ? '0' : $decimal;
                $maximum = (string) PHP_INT_MAX;
                if (!$stringify && (strlen($decimal) < strlen($maximum)
                    || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) <= 0))) {
                    return (int) $decimal;
                }
                return $decimal;
            }
        }

        return $value;
    }

    /** 尊重显式的 PDO 字符串化配置，不强行将其浮点数改回 float。 */
    protected function kingbaseStringifyFetches(): bool
    {
        if ($this->linkID instanceof \think\db\connector\KingbasePDO) {
            return $this->linkID->getKingbaseStringifyFetches();
        }
        if ($this->linkID) {
            try {
                $value = $this->linkID->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
                if (false !== $value || in_array($this->linkID->errorCode(), [null, '00000'], true)) {
                    return (bool) (int) $value;
                }
            } catch (\PDOException $exception) {
                // 自定义 PDO 不支持读取时，才回退到连接配置。
            }
        }
        $params = $this->config['params'] ?? [];
        return (bool) (int) ($params[PDO::ATTR_STRINGIFY_FETCHES] ?? false);
    }

    /** 逐位整数运算，将最多64位BIT的十六进制文本无损转为十进制字符串。 */
    protected function kingbaseHexToDecimal(string $hex): string
    {
        $decimal = '0';
        foreach (str_split($hex) as $digit) {
            $carry = (int) hexdec($digit);
            $next = '';
            for ($i = strlen($decimal) - 1; $i >= 0; --$i) {
                $number = ((int) $decimal[$i]) * 16 + $carry;
                $next = ($number % 10) . $next;
                $carry = intdiv($number, 10);
            }
            while ($carry > 0) {
                $next = ($carry % 10) . $next;
                $carry = intdiv($carry, 10);
            }
            $decimal = ltrim($next, '0');
            if ('' === $decimal) {
                $decimal = '0';
            }
        }
        return $decimal;
    }

    /**
     * 在模型组装和查询缓存之前，对齐已确认的原生驱动返回类型。
     * 存储过程的分组和切换仍由父类完成，每组通过 getResult(false) 转换。
     */
    protected function getResult(bool $procedure = false): array
    {
        $result = parent::getResult($procedure);

        return $procedure ? $result : $this->normalizeKingbaseRows($result);
    }

    /**
     * 游标逐行转换，不提前加载后续记录；模型取得的也是转换后的字段值。
     */
    public function getCursor(BaseQuery $query, string $sql, $model = null)
    {
        if ('oracle' === $this->getExpectedKingbaseMode()) {
            yield from parent::getCursor($query, $sql, $model);
            return;
        }
        $this->queryPDOStatement($query, $sql);
        $columns = null;

        while ($result = $this->PDOStatement->fetch($this->fetchType)) {
            $result = $this->normalizeKingbaseRows([$result], null, $columns)[0];
            if ($model) {
                yield $model->newInstance($result);
            } else {
                yield $result;
            }
        }
    }

    /**
     * 保留父类取值及缓存流程；先区分无行，再按结果列类型转换。
     */
    public function value(BaseQuery $query, string $field, $default = null, bool $one = true)
    {
        if ('oracle' === $this->getExpectedKingbaseMode()) {
            return parent::value($query, $field, $default, $one);
        }
        $options = $query->parseOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (isset($options['group'])) {
            $query->group('');
        }

        $query->setOption('field', (array) $field);

        if (!empty($options['cache'])) {
            $cacheItem = $this->parseCache($query, $options['cache'], 'value');
            if (empty($options['force_cache'])) {
                $key = $cacheItem->getKey();

                if ($this->cache->has($key)) {
                    $data = $this->cache->get($key);
                    if (null !== $data) {
                        return $data;
                    }
                }
            }
        }

        $sql = $this->builder->select($query, $one);

        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        if (isset($options['group'])) {
            $query->setOption('group', $options['group']);
        }

        $pdo = $this->getPDOStatement($sql, $query->getBind(), $options['master']);

        // fetchColumn() 的 false 同时表示无行及布尔值 false，必须先区分。
        $row = $pdo->fetch(PDO::FETCH_NUM);
        if (false === $row) {
            $result = $default;
        } else {
            $values = array_values($row);
            $columns = $this->getKingbaseResultColumns($pdo);
            $streams = [];
            $result = $this->normalizeKingbaseValue($values[0], $columns['position'][0] ?? '', $streams);
        }
        $requireCache = $query->getOption('cache_always') || !empty($result);

        if (isset($cacheItem) && $requireCache) {
            $cacheItem->set($result);
            $this->cacheData($cacheItem);
        }

        return $result;
    }

    /**
     * 保留父类列提取流程；在数组索引组装及缓存之前对齐结果类型。
     */
    public function column(BaseQuery $query, string | array $column, string $key = ''): array
    {
        if ('oracle' === $this->getExpectedKingbaseMode()) {
            return parent::column($query, $column, $key);
        }
        $options = $query->parseOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (empty($key) || trim($key) === '') {
            $key = null;
        }

        if (is_string($column)) {
            $column = trim($column);
            if ('*' !== $column) {
                $column = array_map('trim', explode(',', $column));
            }
        } elseif (in_array('*', $column)) {
            $column = '*';
        }

        $field = $column;
        if ('*' !== $column && $key && !in_array($key, $column)) {
            $field[] = $key;
        }

        $query->setOption('field', (array) $field);

        if (!empty($options['cache'])) {
            $cacheItem = $this->parseCache($query, $options['cache'], 'column');
            if (empty($options['force_cache'])) {
                $name = $cacheItem->getKey();

                if ($this->cache->has($name)) {
                    $data = $this->cache->get($name);
                    if (null !== $data) {
                        return $data;
                    }
                }
            }
        }

        $sql = $this->builder->select($query);

        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        $pdo       = $this->getPDOStatement($sql, $query->getBind(), $options['master']);
        $resultSet = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $resultSet = $this->normalizeKingbaseRows($resultSet, $pdo);

        if (is_string($key) && str_contains($key, '.')) {
            [$alias, $key] = explode('.', $key);
        }

        if (empty($resultSet)) {
            $result = [];
        } elseif ('*' !== $column && count($column) === 1) {
            $column = array_shift($column);
            if (str_contains($column, ' ')) {
                $column = substr(strrchr(trim($column), ' '), 1);
            }

            if (str_contains($column, '.')) {
                [$alias, $column] = explode('.', $column);
            }

            if (str_contains($column, '->')) {
                $column = $this->builder->parseKey($query, $column);
            }

            $result = array_column($resultSet, $column, $key);
        } elseif ($key) {
            $result = array_column($resultSet, null, $key);
        } else {
            $result = $resultSet;
        }

        $requireCache = $query->getOption('cache_always') || !empty($result);

        if (isset($cacheItem) && $requireCache) {
            $cacheItem->set($result);
            $this->cacheData($cacheItem);
        }

        return $result;
    }

    /** 仅识别 PDO_KDB 报告的 BLOB/bytea 列，不将其他资源强制转为字符串。 */
    protected function isMysqlBlobColumn($meta): bool
    {
        return is_array($meta) && in_array(strtolower((string) ($meta['native_type'] ?? '')),
            ['bytea', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true);
    }

    protected function readMysqlBlobStream($stream): string
    {
        $value = stream_get_contents($stream);
        if (false === $value) {
            throw new \RuntimeException('Unable to read KingbaseES MySQL BLOB result stream');
        }

        return $value;
    }

}


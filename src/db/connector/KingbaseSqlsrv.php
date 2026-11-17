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

use think\db\concern\KingbaseConnection;

/**
 * KingbaseES SQL Server 兼容模式数据库连接器。
 * 表名、字段读取及事务控制继承原 Sqlsrv/PDOConnection。
 */
class KingbaseSqlsrv extends Sqlsrv
{
    use KingbaseConnection;

    /**
     * 返回本连接器要求的 KingbaseES 兼容模式。
     *
     * @return string 固定返回 sqlserver
     */
    protected function getExpectedKingbaseMode(): string
    {
        return 'sqlserver';
    }
}

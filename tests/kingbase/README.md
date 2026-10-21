# KingbaseES适配测试

本目录保存ThinkORM 4 KingbaseES适配的单元测试、真实实例测试和独立诊断脚本。
测试口令只通过环境变量传入，不得写入代码或提交到仓库。

## 环境变量

MySQL兼容模式：

```bash
export KINGBASE_MYSQL_HOST=127.0.0.1
export KINGBASE_MYSQL_PORT=54321
export KINGBASE_MYSQL_DATABASE=test
export KINGBASE_MYSQL_USERNAME=system
export KINGBASE_MYSQL_SCHEMA=public
read -rsp "MySQL兼容模式密码: " KINGBASE_MYSQL_PASSWORD
export KINGBASE_MYSQL_PASSWORD
echo
```

Oracle兼容模式使用`KINGBASE_ORACLE_*`变量，SQL Server兼容模式使用
`KINGBASE_SQLSRV_*`变量。默认端口分别为`54322`和`54323`，默认Schema分别为
`system`和`dbo`。

## 不依赖数据库的测试

```bash
vendor/bin/phpunit \
  tests/kingbase/KingbaseConnectionUnitTest.php \
  --do-not-cache-result
```

## 自动模式识别

```bash
KINGBASE_TEST_VERBOSE=1 vendor/bin/phpunit \
  tests/kingbase/KingbaseAutoDetectTest.php \
  --testdox --colors=never --do-not-cache-result
```

统一`type=kingbase`入口读取`database_mode`，选择`KingbaseMysql`、
`KingbaseOracle`或`KingbaseSqlsrv`，并在正式连接和重连时再次校验模式。

## 分模式适配测试

```bash
vendor/bin/phpunit tests/kingbase/KingbaseMysqlTest.php --do-not-cache-result
vendor/bin/phpunit tests/kingbase/KingbaseOracleTest.php --do-not-cache-result
vendor/bin/phpunit tests/kingbase/KingbaseSqlsrvTest.php --do-not-cache-result
```

对应的高级ORM测试：

```bash
vendor/bin/phpunit tests/kingbase/KingbaseMysqlOrmAdvancedTest.php --do-not-cache-result
vendor/bin/phpunit tests/kingbase/KingbaseOracleOrmAdvancedTest.php --do-not-cache-result
vendor/bin/phpunit tests/kingbase/KingbaseSqlsrvOrmAdvancedTest.php --do-not-cache-result
```

## 数据类型与回归测试

```bash
vendor/bin/phpunit tests/kingbase/KingbaseDataTypeCoverageTest.php --do-not-cache-result
vendor/bin/phpunit tests/kingbase/KingbaseReviewRegressionTest.php --do-not-cache-result
```

## 独立诊断脚本

以下脚本用于区分ThinkORM、PDO_KDB和KingbaseES实例的能力边界：

```bash
php tests/kingbase/pdo_transaction_diagnostic.php --mode=all --allow-ddl
php tests/kingbase/complex_type_diagnostic.php --mode=all --allow-ddl
php tests/kingbase/failure_recovery_monitor.php
```

执行会创建对象的测试和诊断脚本时，只能使用隔离测试库。环境或权限不满足应记录为
`BLOCKED`，数据库实例不支持应记录为`UNSUPPORTED`，框架处理错误才记录为`FAIL`。

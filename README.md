# ThinkORM4.0

基于PHP8.0+ 和PDO实现的轻量级ORM，完全重构的Model层，支持实体模型和分层（[新特性盘点](https://doc.thinkphp.cn/@think-orm/v4_0/new-features.html)），并基本兼容3.0（参考[升级指导](https://doc.thinkphp.cn/@think-orm/v4_0/upgrade.html)）。

## 特性

* 基于PDO和PHP强类型实现
* 原生查询支持
* 灵活的查询构造器和链式查询
* 自动参数绑定和预查询
* 聚合查询
* 强大的模型及关联定义
* 模型获取器和修改器
* 虚拟模型支持
* 实体模型和视图模型支持
* 支持ActiveRecord模式和仓储模式
* 模型事件和类型自动转换
* 数据自动写入和延迟写入
* 搜索器和查询范围
* 预载入关联查询和延迟关联查询
* 数据写入自动验证
* 多数据库和`MongoDb`支持
* 分布式及事务、断点重连
* `JSON`及枚举类支持
* `PSR-16`缓存及`PSR-3`日志规范

## 安装
~~~
composer require topthink/think-orm
~~~

## 文档

详细参考 [ThinkORM开发指南](https://doc.thinkphp.cn/@think-orm)

基于官方手册的数据训练和提供精准解答服务
[官方专家智能体](https://chat.topthink.com/chat/e7m6qe)

你可以使用官方提供的[ThinkChat](https://chat.topthink.com/)，让你在学习 ThinkPHP 的旅途中享受私人 AI 助理服务！

[![](https://www.topthink.com/uploads/assistant/20230630/4d1a3f0ad2958b49bb8189b7ef824cb0.png)](https://chat.topthink.com/)

ThinkPHP 生态服务由[顶想云](https://www.topthink.com)（TOPThink Cloud）提供，为生态提供专业的开发者服务和价值之选。

## KingbaseES 使用注意事项

### Oracle 兼容模式的字段名称

在连接 DSN 的 `options` 中设置 `-c enable_upper_colname=off`，避免服务端自动将查询结果列名转为大写。业务输入、Model 主键及查询结果的字段键需要使用一致的大小写约定。

业务统一使用 `ID`、`NAME` 等大写字段键时，在连接配置的 `params` 中设置 `PDO::ATTR_CASE => PDO::CASE_UPPER`。原框架按该配置处理元数据字段键，PDO 按该配置处理查询结果列名，无需修改业务代码或自定义 Query 的父类。例如，以下为连接配置片段：

```php
'dsn' => "kdb:host=127.0.0.1;port=15422;dbname=oracle;options='-c enable_upper_colname=off'",
'params' => [
    PDO::ATTR_CASE => PDO::CASE_UPPER,
],
```

主机、端口和数据库名称按实际环境填写；已有 `options` 或其他 PDO 参数时，应合并配置，不要覆盖原有设置。

业务使用精确小写或混合大小写字段名时，保留 `PDO::CASE_NATURAL`，不要强制设置 `CASE_UPPER`，以免改变字段名及显式别名。适配层不自动改写业务字段键，也不保证同一连接能够任意混用多套大小写约定。

更改大小写策略后，应重建连接并清理相应字段缓存；采用不同策略的连接不要共用同一份字段缓存。

### MySQL 兼容模式的多态关联分组

对于部分`has()`、`hasWhere()`生成的等值内连接查询，金仓构造器会在原关联外键分组后补充主表主键。适配前先读取物理目录，确认普通表、有效单列整数主键及同类型非空关联列，保留原WHERE、HAVING、软删除及参数绑定规则。

该处理仅覆盖已验证的简单小写物理表名、主表字段投影及单个内连接结构。复杂连接、外连接、继承／分区表、其他主键类型和分布式连接继续沿用原流程，不自动猜测改写。`fetchSql()`等只生成SQL的调用也沿用原分组规则，不为此适配额外访问数据库。

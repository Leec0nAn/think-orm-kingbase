<?php

declare(strict_types=1);

namespace tests\kingbase;

use think\facade\Db;

/**
 * KingbaseES three-mode data-type round-trip coverage.
 *
 * These cases exercise the actual ThinkORM boundary rather than only asking
 * KingbaseES whether a type can be declared.  Every case creates a table,
 * writes values through Query parameter binding, reads them through Query,
 * and verifies that getFields() can still describe the columns.
 */
final class KingbaseDataTypeCoverageTest extends KingbaseTestCase
{
    /** @var list<string> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->dropIfExists('DROP TABLE ' . $table);
        }
        $this->tables = [];
    }

    public function testMysqlNumericTypesRoundTrip(): void
    {
        $table = 'kb_type_mysql_num';
        $this->configureMode('mysql');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_mysql_num (
    id INT PRIMARY KEY,
    bool_value BOOLEAN,
    bit_value BIT(8),
    tiny_value TINYINT,
    small_value SMALLINT,
    medium_value MEDIUMINT,
    int_value INT,
    unsigned_value INT UNSIGNED,
    bigint_value BIGINT,
    unsigned_bigint BIGINT UNSIGNED,
    decimal_value DECIMAL(30,10),
    float_value FLOAT,
    double_value DOUBLE,
    money_value MONEY
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'bool_value' => true,
            // PDO_KDB exposes multi-bit values in hexadecimal form.
            'bit_value' => '0xAA',
            'tiny_value' => -100,
            'small_value' => -30000,
            'medium_value' => 8000000,
            'int_value' => -2000000000,
            'unsigned_value' => '4000000000',
            'bigint_value' => '-9223372036854775807',
            'unsigned_bigint' => '18446744073709551615',
            'decimal_value' => '12345678901234567890.1234567890',
            'float_value' => '12.5',
            'double_value' => '123456.125',
            'money_value' => '1234.50',
        ], function (array $row): void {
            $this->assertTrue($this->boolValue($row['bool_value']));
            $this->assertSame('0xaa', strtolower((string) $row['bit_value']));
            $this->assertSame('-100', (string) $row['tiny_value']);
            $this->assertSame('-30000', (string) $row['small_value']);
            $this->assertSame('8000000', (string) $row['medium_value']);
            $this->assertSame('-2000000000', (string) $row['int_value']);
            $this->assertSame('4000000000', (string) $row['unsigned_value']);
            $this->assertSame('-9223372036854775807', (string) $row['bigint_value']);
            $this->assertSame('18446744073709551615', (string) $row['unsigned_bigint']);
            $this->assertSame('12345678901234567890.1234567890', (string) $row['decimal_value']);
            $this->assertEqualsWithDelta(12.5, (float) $row['float_value'], 0.001);
            $this->assertEqualsWithDelta(123456.125, (float) $row['double_value'], 0.000001);
        });
    }

    public function testMysqlCharacterAndStructuredTypesRoundTrip(): void
    {
        $table = 'kb_type_mysql_text';
        $this->configureMode('mysql');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_mysql_text (
    id INT PRIMARY KEY,
    char_value CHAR(8),
    varchar_value VARCHAR(100),
    byte_char BPCHARBYTE(8),
    byte_varchar VARCHARBYTE(100),
    tinytext_value TINYTEXT,
    text_value TEXT,
    mediumtext_value MEDIUMTEXT,
    longtext_value LONGTEXT,
    clob_value CLOB,
    nclob_value NCLOB,
    enum_value ENUM('red','green','blue'),
    set_value SET('a','b','c'),
    json_value JSON,
    xml_value XML
)
SQL);

        $json = '{"name":"金仓","enabled":true}';
        $xml = '<root><name>金仓</name></root>';
        $this->insertAndFind($table, [
            'id' => 1,
            'char_value' => '中文A',
            'varchar_value' => "前导 空格\t换行\n末尾 ",
            'byte_char' => 'ABC',
            'byte_varchar' => 'bytes',
            'tinytext_value' => 'tiny',
            'text_value' => 'text-中文',
            'mediumtext_value' => str_repeat('中', 1024),
            'longtext_value' => str_repeat('L', 8192),
            'clob_value' => str_repeat('CLOB中文', 1024),
            'nclob_value' => str_repeat('NCLOB中文', 1024),
            'enum_value' => 'green',
            'set_value' => 'a,c',
            'json_value' => $json,
            'xml_value' => $xml,
        ], function (array $row) use ($json, $xml): void {
            $this->assertSame('中文A', rtrim((string) $row['char_value']));
            $this->assertSame("前导 空格\t换行\n末尾 ", (string) $row['varchar_value']);
            $this->assertSame('green', (string) $row['enum_value']);
            $this->assertSame('a,c', (string) $row['set_value']);
            $this->assertSame(json_decode($json, true), json_decode((string) $row['json_value'], true));
            $this->assertSame($xml, (string) $row['xml_value']);
            $this->assertSame(str_repeat('L', 8192), (string) $row['longtext_value']);
        });
    }

    public function testMysqlBinaryAndLargeObjectTypesRoundTrip(): void
    {
        $table = 'kb_type_mysql_bin';
        $this->configureMode('mysql');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_mysql_bin (
    id INT PRIMARY KEY,
    binary_value BINARY(8),
    varbinary_value VARBINARY(32),
    bytea_value BYTEA,
    tinyblob_value TINYBLOB,
    blob_value BLOB,
    mediumblob_value MEDIUMBLOB,
    longblob_value LONGBLOB
)
SQL);

        $binary = "\x00\x01KDB\xff\x10";
        $large = str_repeat("\x00KDB\xff", 4096);
        $this->insertAndFind($table, [
            'id' => 1,
            'binary_value' => $binary,
            'varbinary_value' => $binary,
            'bytea_value' => $binary,
            'tinyblob_value' => $binary,
            'blob_value' => $large,
            'mediumblob_value' => $large,
            'longblob_value' => $large,
        ], function (array $row) use ($binary, $large): void {
            $this->assertStringStartsWith($binary, $this->binaryValue($row['binary_value']));
            $this->assertSame($binary, $this->binaryValue($row['varbinary_value']));
            $this->assertSame($binary, $this->binaryValue($row['bytea_value']));
            $this->assertSame($binary, $this->binaryValue($row['tinyblob_value']));
            $this->assertSame($large, $this->binaryValue($row['blob_value']));
            $this->assertSame($large, $this->binaryValue($row['mediumblob_value']));
            $this->assertSame($large, $this->binaryValue($row['longblob_value']));
        });
    }

    public function testMysqlTemporalTypesRoundTrip(): void
    {
        $table = 'kb_type_mysql_time';
        $this->configureMode('mysql');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_mysql_time (
    id INT PRIMARY KEY,
    date_value DATE,
    time_value TIME(6),
    datetime_value DATETIME(6),
    timestamp_value TIMESTAMP(6),
    year_value YEAR
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'date_value' => '2024-02-29',
            'time_value' => '23:59:58.123456',
            'datetime_value' => '2024-02-29 23:59:58.123456',
            'timestamp_value' => '2024-02-29 23:59:58.123456',
            'year_value' => '2024',
        ], function (array $row): void {
            $this->assertStringStartsWith('2024-02-29', (string) $row['date_value']);
            $this->assertStringContainsString('23:59:58', (string) $row['time_value']);
            $this->assertStringStartsWith('2024-02-29 23:59:58', (string) $row['datetime_value']);
            $this->assertStringStartsWith('2024-02-29 23:59:58', (string) $row['timestamp_value']);
            $this->assertSame('2024', (string) $row['year_value']);
        });
    }

    public function testOracleNumericTypesRoundTrip(): void
    {
        $table = 'kb_type_oracle_num';
        $this->configureMode('oracle');
        $this->createTable($table, <<<'SQL'
CREATE TABLE KB_TYPE_ORACLE_NUM (
    ID NUMBER(10) PRIMARY KEY,
    BOOL_VALUE BOOLEAN,
    BIT_VALUE BIT(8),
    SMALL_VALUE SMALLINT,
    INT_VALUE INTEGER,
    BIGINT_VALUE BIGINT,
    NUMBER_VALUE NUMBER(30,10),
    NUMERIC_VALUE NUMERIC(30,10),
    BINARY_FLOAT_VALUE BINARY_FLOAT,
    BINARY_DOUBLE_VALUE BINARY_DOUBLE,
    MONEY_VALUE MONEY
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'bool_value' => true,
            'bit_value' => '10101010',
            'small_value' => -30000,
            'int_value' => -2000000000,
            'bigint_value' => '-9223372036854775807',
            'number_value' => '12345678901234567890.1234567890',
            'numeric_value' => '-12345678901234567890.1234567890',
            'binary_float_value' => '12.5',
            'binary_double_value' => '123456.125',
            'money_value' => '1234.50',
        ], function (array $row): void {
            $this->assertTrue($this->boolValue($row['bool_value']));
            $this->assertSame('-9223372036854775807', (string) $row['bigint_value']);
            $this->assertSame('12345678901234567890.1234567890', (string) $row['number_value']);
            $this->assertSame('-12345678901234567890.1234567890', (string) $row['numeric_value']);
            $this->assertEqualsWithDelta(12.5, (float) $row['binary_float_value'], 0.001);
            $this->assertEqualsWithDelta(123456.125, (float) $row['binary_double_value'], 0.000001);
        });
    }

    public function testOracleCharacterAndStructuredTypesRoundTrip(): void
    {
        $table = 'kb_type_oracle_text';
        $this->configureMode('oracle');
        $this->createTable($table, <<<'SQL'
CREATE TABLE KB_TYPE_ORACLE_TEXT (
    ID NUMBER(10) PRIMARY KEY,
    CHAR_VALUE CHAR(8),
    NCHAR_VALUE NCHAR(8),
    VARCHAR2_VALUE VARCHAR2(100),
    NVARCHAR2_VALUE NVARCHAR2(100),
    TEXT_VALUE TEXT,
    CLOB_VALUE CLOB,
    NCLOB_VALUE NCLOB,
    JSON_VALUE JSON,
    UUID_VALUE UUID,
    XML_VALUE XML
)
SQL);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $json = '{"name":"金仓","enabled":true}';
        $xml = '<root><name>金仓</name></root>';
        $this->insertAndFind($table, [
            'id' => 1,
            'char_value' => 'ABC',
            'nchar_value' => '中文',
            'varchar2_value' => "Oracle 文本\n第二行",
            'nvarchar2_value' => 'Unicode 金仓',
            'text_value' => str_repeat('TEXT', 1024),
            'clob_value' => str_repeat('CLOB中文', 1024),
            'nclob_value' => str_repeat('NCLOB中文', 1024),
            'json_value' => $json,
            'uuid_value' => $uuid,
            'xml_value' => $xml,
        ], function (array $row) use ($uuid, $json, $xml): void {
            $this->assertSame('ABC', rtrim((string) $row['char_value']));
            $this->assertSame('中文', rtrim((string) $row['nchar_value']));
            $this->assertSame($uuid, strtolower((string) $row['uuid_value']));
            $this->assertSame(json_decode($json, true), json_decode((string) $row['json_value'], true));
            $this->assertSame($xml, (string) $row['xml_value']);
        });
    }

    public function testOracleBinaryAndLargeObjectTypesRoundTrip(): void
    {
        $table = 'kb_type_oracle_bin';
        $this->configureMode('oracle');
        $this->createTable($table, <<<'SQL'
CREATE TABLE KB_TYPE_ORACLE_BIN (
    ID NUMBER(10) PRIMARY KEY,
    BYTEA_VALUE BYTEA,
    BLOB_VALUE BLOB
)
SQL);

        $binary = "\x00\x01KDB\xff\x10";
        $large = str_repeat("\x00KDB\xff", 4096);
        $this->insertAndFind($table, [
            'id' => 1,
            'bytea_value' => $binary,
            'blob_value' => $large,
        ], function (array $row) use ($binary, $large): void {
            $this->assertSame($binary, $this->binaryValue($row['bytea_value']));
            $this->assertSame($large, $this->binaryValue($row['blob_value']));
        });
    }

    public function testOracleTemporalAndIntervalTypesRoundTrip(): void
    {
        $table = 'kb_type_oracle_time';
        $this->configureMode('oracle');
        $this->createTable($table, <<<'SQL'
CREATE TABLE KB_TYPE_ORACLE_TIME (
    ID NUMBER(10) PRIMARY KEY,
    DATE_VALUE DATE,
    TIME_VALUE TIME(6),
    TIMETZ_VALUE TIME(6) WITH TIME ZONE,
    TIMESTAMP_VALUE TIMESTAMP(6),
    TIMESTAMPTZ_VALUE TIMESTAMP(6) WITH TIME ZONE,
    TIMESTAMPLTZ_VALUE TIMESTAMP(6) WITH LOCAL TIME ZONE,
    INTERVALYM_VALUE INTERVAL YEAR TO MONTH,
    INTERVALDS_VALUE INTERVAL DAY TO SECOND
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'date_value' => '2024-02-29 23:59:58',
            'time_value' => '23:59:58.123456',
            'timetz_value' => '23:59:58.123456+08:00',
            'timestamp_value' => '2024-02-29 23:59:58.123456',
            'timestamptz_value' => '2024-02-29 23:59:58.123456+08:00',
            'timestampltz_value' => '2024-02-29 23:59:58.123456+08:00',
            'intervalym_value' => '2-3',
            'intervalds_value' => '4 05:06:07.123456',
        ], function (array $row): void {
            $this->assertStringStartsWith('2024-02-29', (string) $row['date_value']);
            $this->assertStringContainsString('23:59:58', (string) $row['time_value']);
            $this->assertStringContainsString('23:59:58', (string) $row['timetz_value']);
            $this->assertStringStartsWith('2024-02-29 23:59:58', (string) $row['timestamp_value']);
            $this->assertStringContainsString('2024-02-29 23:59:58', (string) $row['timestamptz_value']);
            $this->assertNotSame('', (string) $row['intervalym_value']);
            $this->assertNotSame('', (string) $row['intervalds_value']);
        });
    }

    public function testSqlsrvNumericTypesRoundTrip(): void
    {
        $table = 'kb_type_sqlsrv_num';
        $this->configureMode('sqlserver');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_sqlsrv_num (
    id INT PRIMARY KEY,
    bit_value BIT,
    tiny_value TINYINT,
    small_value SMALLINT,
    int_value INT,
    bigint_value BIGINT,
    decimal_value DECIMAL(30,10),
    numeric_value NUMERIC(30,10),
    money_value MONEY,
    real_value REAL,
    float_value FLOAT
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'bit_value' => true,
            'tiny_value' => 200,
            'small_value' => -30000,
            'int_value' => -2000000000,
            'bigint_value' => '-9223372036854775807',
            'decimal_value' => '12345678901234567890.1234567890',
            'numeric_value' => '-12345678901234567890.1234567890',
            'money_value' => '1234.50',
            'real_value' => '12.5',
            'float_value' => '123456.125',
        ], function (array $row): void {
            $this->assertTrue($this->boolValue($row['bit_value']));
            $this->assertSame('200', (string) $row['tiny_value']);
            $this->assertSame('-9223372036854775807', (string) $row['bigint_value']);
            $this->assertSame('12345678901234567890.1234567890', (string) $row['decimal_value']);
            $this->assertSame('-12345678901234567890.1234567890', (string) $row['numeric_value']);
            $this->assertEqualsWithDelta(12.5, (float) $row['real_value'], 0.001);
            $this->assertEqualsWithDelta(123456.125, (float) $row['float_value'], 0.000001);
        });
    }

    public function testSqlsrvCharacterAndStructuredTypesRoundTrip(): void
    {
        $table = 'kb_type_sqlsrv_text';
        $this->configureMode('sqlserver');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_sqlsrv_text (
    id INT PRIMARY KEY,
    char_value CHAR(8),
    varchar_value VARCHAR(100),
    nchar_value NCHAR(8),
    nvarchar_value NVARCHAR(100),
    text_value TEXT,
    uuid_value UNIQUEIDENTIFIER,
    xml_value XML
)
SQL);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $xml = '<root><name>金仓</name></root>';
        $this->insertAndFind($table, [
            'id' => 1,
            'char_value' => 'ABC',
            'varchar_value' => "SQL Server\n第二行",
            'nchar_value' => '中文',
            'nvarchar_value' => 'Unicode 金仓',
            'text_value' => str_repeat('TEXT', 1024),
            'uuid_value' => $uuid,
            'xml_value' => $xml,
        ], function (array $row) use ($uuid, $xml): void {
            $this->assertSame('ABC', rtrim((string) $row['char_value']));
            $this->assertSame('中文', rtrim((string) $row['nchar_value']));
            $this->assertSame($uuid, strtolower((string) $row['uuid_value']));
            $this->assertSame($xml, (string) $row['xml_value']);
        });
    }

    public function testSqlsrvBinaryAndRowversionTypesRoundTrip(): void
    {
        $table = 'kb_type_sqlsrv_bin';
        $this->configureMode('sqlserver');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_sqlsrv_bin (
    id INT PRIMARY KEY,
    binary_value BINARY(8),
    varbinary_value VARBINARY(32),
    rowversion_value ROWVERSION
)
SQL);

        $binary = "\x00\x01KDB\xff\x10";
        $row = $this->insertAndFind($table, [
            'id' => 1,
            'binary_value' => $binary,
            'varbinary_value' => $binary,
        ], function (array $row) use ($binary): void {
            $this->assertStringStartsWith($binary, $this->binaryValue($row['binary_value']));
            $this->assertSame($binary, $this->binaryValue($row['varbinary_value']));
            $this->assertNotSame('', $this->binaryValue($row['rowversion_value']));
        });

        $original = $this->binaryValue($row['rowversion_value']);
        $this->conn()->table($table)->where('id', 1)->update(['varbinary_value' => "\x02KDB"]);
        $updated = $this->conn()->table($table)->where('id', 1)->find();
        $this->assertNotSame($original, $this->binaryValue($updated['rowversion_value']));
    }

    public function testSqlsrvTemporalTypesRoundTrip(): void
    {
        $table = 'kb_type_sqlsrv_time';
        $this->configureMode('sqlserver');
        $this->createTable($table, <<<'SQL'
CREATE TABLE kb_type_sqlsrv_time (
    id INT PRIMARY KEY,
    date_value DATE,
    time_value TIME(6),
    datetime_value DATETIME,
    datetime2_value DATETIME2(6),
    smalldatetime_value SMALLDATETIME
)
SQL);

        $this->insertAndFind($table, [
            'id' => 1,
            'date_value' => '2024-02-29',
            'time_value' => '23:59:58.123456',
            'datetime_value' => '2024-02-29 23:59:58',
            'datetime2_value' => '2024-02-29 23:59:58.123456',
            'smalldatetime_value' => '2024-02-29 23:59:00',
        ], function (array $row): void {
            $this->assertStringStartsWith('2024-02-29', (string) $row['date_value']);
            $this->assertStringContainsString('23:59:58', (string) $row['time_value']);
            $this->assertStringStartsWith('2024-02-29 23:59:58', (string) $row['datetime_value']);
            $this->assertStringStartsWith('2024-02-29 23:59:58', (string) $row['datetime2_value']);
            $this->assertStringStartsWith('2024-02-29 23:59', (string) $row['smalldatetime_value']);
        });
    }

    /** @param array<string, mixed> $data */
    private function insertAndFind(string $table, array $data, callable $assertions): array
    {
        $this->assertSame(1, $this->conn()->table($table)->insert($data));
        $row = $this->conn()->table($table)->where('id', $data['id'])->find();

        $this->assertIsArray($row);
        $assertions($row);

        $fields = $this->conn()->getFields($table);
        foreach (array_keys($data) as $field) {
            $this->assertArrayHasKey(strtolower($field), array_change_key_case($fields));
        }

        return $row;
    }

    private function createTable(string $table, string $sql): void
    {
        $this->dropIfExists('DROP TABLE ' . $table);
        $this->conn()->execute($sql);
        $this->tables[] = $table;
    }

    private function configureMode(string $mode): void
    {
        $map = [
            'mysql' => [
                'name' => 'kingbase_type_mysql',
                'type' => 'kingbaseMysql',
                'prefix' => 'KINGBASE_MYSQL_',
                'port' => '54321',
                'schema' => 'public',
            ],
            'oracle' => [
                'name' => 'kingbase_type_oracle',
                'type' => 'kingbaseOracle',
                'prefix' => 'KINGBASE_ORACLE_',
                'port' => '54322',
                'schema' => '',
            ],
            'sqlserver' => [
                'name' => 'kingbase_type_sqlsrv',
                'type' => 'kingbaseSqlsrv',
                'prefix' => 'KINGBASE_SQLSRV_',
                'port' => '54323',
                'schema' => 'dbo',
            ],
        ];

        $item = $map[$mode];
        self::configureConnection($item['name'], [
            'type' => $item['type'],
            'hostname' => self::envValue($item['prefix'] . 'HOST', '127.0.0.1'),
            'hostport' => self::envValue($item['prefix'] . 'PORT', $item['port']),
            'database' => self::envValue($item['prefix'] . 'DATABASE', 'test'),
            'username' => self::envValue($item['prefix'] . 'USERNAME', 'system'),
            'password' => self::envValue($item['prefix'] . 'PASSWORD'),
            'charset' => self::envValue($item['prefix'] . 'CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => self::envValue($item['prefix'] . 'SCHEMA', $item['schema']),
            'fields_cache' => false,
            'trigger_sql' => true,
        ]);
    }

    private function boolValue($value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}

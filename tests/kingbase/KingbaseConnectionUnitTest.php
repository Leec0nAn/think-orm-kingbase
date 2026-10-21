<?php

declare(strict_types=1);

namespace tests\kingbase;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\db\Raw;
use think\db\connector\KingbaseOracle;
use think\db\connector\KingbaseSqlsrv;
use think\model\type\KingbaseDateTime;

/** 不依赖实库的 KingbaseES 连接边界回归测试。 */
final class KingbaseConnectionUnitTest extends TestCase
{
    private KingbaseOracle $connector;

    protected function setUp(): void
    {
        $this->connector = new KingbaseOracle(['type' => 'kingbaseOracle']);
    }

    /** @dataProvider sequenceDefaults */
    public function testSequenceExtractionRejectsStringLiteralsAndAcceptsRealDefaults(
        string $default,
        ?string $expected
    ): void {
        $this->assertSame($expected, $this->invoke('extractSequenceFromDefault', [$default]));
    }

    public static function sequenceDefaults(): array
    {
        return [
            ['APP.ORDER_SEQ.NEXTVAL', 'APP.ORDER_SEQ'],
            ['nextval(\'"ORDER_SEQ"\'::regclass)', 'ORDER_SEQ'],
            ['nextval("ORDER_SEQ")', 'ORDER_SEQ'],
            ['\'order.nextval\'', null],
            ['', null],
        ];
    }

    public function testExplicitSequenceMustActuallyAppearAsNextvalExpression(): void
    {
        $this->assertTrue($this->invoke('dataUsesKingbaseSequence', [
            ['id' => new Raw('APP.ORDER_SEQ.NEXTVAL')],
            'app.order_seq',
        ]));
        $this->assertFalse($this->invoke('dataUsesKingbaseSequence', [
            ['id' => new Raw('APP.OTHER_SEQ.NEXTVAL')],
            'app.order_seq',
        ]));
        $this->assertFalse($this->invoke('dataUsesKingbaseSequence', [
            ['id' => 10],
            'app.order_seq',
        ]));
    }

    /** @dataProvider kingbaseFieldTypes */
    public function testKingbaseSpecificFieldBinding(string $databaseType, string $fieldType, int $pdoType): void
    {
        $resolved = $this->invoke('getFieldType', [$databaseType]);

        $this->assertSame($fieldType, $resolved);
        $this->assertSame($pdoType, $this->connector->getFieldBindType($resolved));
    }

    public static function kingbaseFieldTypes(): array
    {
        return [
            'single bit remains integer-compatible' => ['bit(1)', 'int', PDO::PARAM_INT],
            'fixed multi-bit value remains a bit string' => ['bit(8)', 'kingbase_bit_string', PDO::PARAM_STR],
            'varying multi-bit value remains a bit string' => ['bit varying(64)', 'kingbase_bit_string', PDO::PARAM_STR],
            'unsigned bigint remains a numeric string' => ['uint8', 'bigint', PDO::PARAM_STR],
            'binary value uses LOB binding' => ['varbinary(128)', 'kingbase_lob', PDO::PARAM_LOB],
            'integer array remains a textual literal' => ['integer[]', 'string', PDO::PARAM_STR],
            'integer range remains a textual literal' => ['int4range', 'string', PDO::PARAM_STR],
            'point remains a textual literal' => ['point', 'string', PDO::PARAM_STR],
            'interval remains a textual literal' => ['interval day to second', 'string', PDO::PARAM_STR],
        ];
    }

    /** @dataProvider ddlTables */
    public function testDdlTableExtraction(string $sql, ?string $expected): void
    {
        $this->assertSame($expected, $this->invoke('extractKingbaseDdlTable', [$sql]));
    }

    public function testSqlsrvDatetime2SevenDigitFractionIsNormalized(): void
    {
        $connector = new KingbaseSqlsrv(['type' => 'kingbaseSqlsrv']);
        $reflection = new ReflectionMethod($connector, 'getFieldType');
        $reflection->setAccessible(true);

        $this->assertSame(KingbaseDateTime::class, $reflection->invoke($connector, 'datetime2'));

        $value = new KingbaseDateTime();
        $value->data('2026-10-07 08:30:45.1234560', 'Y-m-d H:i:s.u');
        $this->assertSame('2026-10-07 08:30:45.123456', $value->value());
    }

    public static function ddlTables(): array
    {
        return [
            ['ALTER TABLE "APP"."USER" ADD "AGE" INT', 'APP.USER'],
            ['DROP TABLE IF EXISTS [dbo].[user]', 'dbo.user'],
            ['CREATE UNIQUE INDEX idx_name ON public.users(name)', 'public.users'],
            ["COMMENT ON COLUMN public.users.name IS 'name'", 'public.users'],
            ['SELECT 1', null],
        ];
    }

    private function invoke(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod($this->connector, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->connector, $arguments);
    }
}

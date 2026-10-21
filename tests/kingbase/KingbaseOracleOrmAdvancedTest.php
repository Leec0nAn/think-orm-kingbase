<?php

declare(strict_types=1);

namespace tests\kingbase;

final class KingbaseOracleOrmAdvancedTest extends KingbaseOrmAdvancedTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::configureAdvancedConnection('kingbase_oracle_orm_advanced', [
            'type' => 'kingbaseOracle',
            'hostname' => self::envValue('KINGBASE_ORACLE_HOST', '127.0.0.1'),
            'hostport' => self::envValue('KINGBASE_ORACLE_PORT', '54322'),
            'database' => self::envValue('KINGBASE_ORACLE_DATABASE', 'test'),
            'username' => self::envValue('KINGBASE_ORACLE_USERNAME', 'system'),
            'password' => self::envValue('KINGBASE_ORACLE_PASSWORD'),
            'charset' => self::envValue('KINGBASE_ORACLE_CHARSET', 'utf8'),
            'prefix' => '',
            'schema' => self::envValue('KINGBASE_ORACLE_SCHEMA', ''),
            'fields_cache' => false,
            'trigger_sql' => true,
        ], 'kb_adv_oracle');
    }

    protected static function createTableSql(): array
    {
        return [
            'CREATE TABLE KB_ADV_ORACLE_AUTHOR (ID NUMBER(10) PRIMARY KEY, NAME VARCHAR2(50) NOT NULL)',
            'CREATE TABLE KB_ADV_ORACLE_PROFILE (ID NUMBER(10) PRIMARY KEY, AUTHOR_ID NUMBER(10) NOT NULL, NICKNAME VARCHAR2(50) NOT NULL)',
            'CREATE TABLE KB_ADV_ORACLE_POST (ID NUMBER(10) PRIMARY KEY, AUTHOR_ID NUMBER(10) NOT NULL, TITLE VARCHAR2(100) NOT NULL, STATUS NUMBER(10) DEFAULT 0)',
            'CREATE TABLE KB_ADV_ORACLE_TAG (ID NUMBER(10) PRIMARY KEY, NAME VARCHAR2(50) NOT NULL)',
            'CREATE TABLE KB_ADV_ORACLE_POST_TAG (POST_ID NUMBER(10) NOT NULL, TAG_ID NUMBER(10) NOT NULL, PRIMARY KEY (POST_ID, TAG_ID))',
            'CREATE TABLE KB_ADV_ORACLE_SOFT_ITEM (ID NUMBER(10) PRIMARY KEY, NAME VARCHAR2(50) NOT NULL, STATUS NUMBER(10) DEFAULT 0, DELETE_TIME TIMESTAMP NULL)',
            'CREATE TABLE KB_ADV_ORACLE_ITEM (ID NUMBER(10) PRIMARY KEY, NAME VARCHAR2(50) NOT NULL, STATUS NUMBER(10) DEFAULT 0, CREATE_TIME TIMESTAMP NULL, UPDATE_TIME TIMESTAMP NULL)',
            'CREATE TABLE KB_ADV_ORACLE_COUNTER (ID NUMBER(10) PRIMARY KEY, COUNTER_VALUE NUMBER(10) NOT NULL)',
        ];
    }
}

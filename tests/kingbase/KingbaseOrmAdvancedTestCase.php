<?php

declare(strict_types=1);

namespace tests\kingbase;

use RuntimeException;
use think\Paginator;
use think\facade\Db;
use think\Model;
use think\model\concern\SoftDelete;

/**
 * Shared model-level integration coverage for all KingbaseES compatibility modes.
 */
abstract class KingbaseOrmAdvancedTestCase extends KingbaseTestCase
{
    private const CONCURRENT_WORKERS = 2;

    private const UPDATES_PER_WORKER = 15;

    /** @return list<string> */
    abstract protected static function createTableSql(): array;

    protected static function configureAdvancedConnection(string $connectionName, array $connectionConfig, string $tablePrefix): void
    {
        self::configureConnection($connectionName, $connectionConfig);
        KingbaseOrmAdvancedContext::$connection = $connectionName;
        KingbaseOrmAdvancedContext::$tablePrefix = $tablePrefix;
    }

    protected function setUp(): void
    {
        if ('testConnectionFailureDiagnosticRedactsPassword' === $this->getName(false)) {
            return;
        }

        $this->dropAdvancedTables();

        foreach (static::createTableSql() as $sql) {
            $this->conn()->execute($sql);
        }
    }

    protected function tearDown(): void
    {
        if ('testConnectionFailureDiagnosticRedactsPassword' === $this->getName(false)) {
            return;
        }

        $this->dropAdvancedTables();
    }

    public function testModelRelationsAndEagerLoading(): void
    {
        $author = KingbaseAdvancedAuthor::create([
            'id' => 1,
            'name' => 'author-1',
        ]);
        KingbaseAdvancedAuthor::create([
            'id' => 2,
            'name' => 'author-2',
        ]);

        $savedPosts = $author->posts()->saveAll([
            ['id' => 10, 'title' => 'post-1', 'status' => 1],
            ['id' => 11, 'title' => 'post-2', 'status' => 0],
        ], false);
        KingbaseAdvancedPost::create([
            'id' => 12,
            'author_id' => 2,
            'title' => 'post-3',
            'status' => 1,
        ]);
        KingbaseAdvancedProfile::create([
            'id' => 100,
            'author_id' => 1,
            'nickname' => 'profile-1',
        ]);
        KingbaseAdvancedTag::saveAll([
            ['id' => 20, 'name' => 'tag-a'],
            ['id' => 21, 'name' => 'tag-b'],
        ], false);
        $savedPosts[0]->tags()->attach([20, 21]);

        $this->assertCount(2, $savedPosts);
        $this->assertSame(1, (int) $savedPosts[0]->author_id);

        $loadedAuthor = KingbaseAdvancedAuthor::with(['posts' => function ($query): void {
            $query->order('id', 'asc');
        }])->find(1);
        $this->assertNotNull($loadedAuthor);
        $this->assertCount(2, $loadedAuthor->posts);
        $this->assertSame('post-1', (string) $loadedAuthor->posts[0]->title);

        $authorWithProfile = KingbaseAdvancedAuthor::with('profile')->find(1);
        $this->assertNotNull($authorWithProfile);
        $this->assertSame('profile-1', (string) $authorWithProfile->profile->nickname);

        $profileWithAuthor = KingbaseAdvancedProfile::with('author')->find(100);
        $this->assertNotNull($profileWithAuthor);
        $this->assertSame('author-1', (string) $profileWithAuthor->author->name);

        $loadedPost = KingbaseAdvancedPost::with('author')->find(12);
        $this->assertNotNull($loadedPost);
        $this->assertSame('author-2', (string) $loadedPost->author->name);

        $postWithTags = KingbaseAdvancedPost::with(['tags' => function ($query): void {
            $query->order('id', 'asc');
        }])->find(10);
        $this->assertNotNull($postWithTags);
        $this->assertCount(2, $postWithTags->tags);
        $this->assertSame(['tag-a', 'tag-b'], $postWithTags->tags->column('name'));

        $tagWithPosts = KingbaseAdvancedTag::with('posts')->find(20);
        $this->assertNotNull($tagWithPosts);
        $this->assertCount(1, $tagWithPosts->posts);
        $this->assertSame('post-1', (string) $tagWithPosts->posts[0]->title);

        $authorWithCount = KingbaseAdvancedAuthor::withCount('posts')->find(1);
        $this->assertNotNull($authorWithCount);
        $this->assertSame(2, (int) $authorWithCount->posts_count);

        $postWithCount = KingbaseAdvancedPost::withCount('tags')->find(10);
        $this->assertNotNull($postWithCount);
        $this->assertSame(2, (int) $postWithCount->tags_count);
    }

    public function testSoftDeleteQueryRestoreAndForceDelete(): void
    {
        KingbaseAdvancedSoftItem::saveAll([
            ['id' => 1, 'name' => 'soft-1', 'status' => 1],
            ['id' => 2, 'name' => 'soft-2', 'status' => 0],
            ['id' => 3, 'name' => 'soft-3', 'status' => 1],
        ], false);

        $item = KingbaseAdvancedSoftItem::find(1);
        $this->assertNotNull($item);
        $this->assertTrue($item->delete());

        $this->assertNull(KingbaseAdvancedSoftItem::find(1));
        $this->assertSame(2, KingbaseAdvancedSoftItem::count());
        $this->assertSame(3, KingbaseAdvancedSoftItem::withTrashed()->count());
        $this->assertSame(1, KingbaseAdvancedSoftItem::onlyTrashed()->count());

        $trashed = KingbaseAdvancedSoftItem::onlyTrashed()->find(1);
        $this->assertNotNull($trashed);
        $this->assertTrue($trashed->restore());
        $this->assertNotNull(KingbaseAdvancedSoftItem::find(1));

        $this->assertTrue(KingbaseAdvancedSoftItem::destroy(2, true));
        $this->assertSame(2, KingbaseAdvancedSoftItem::withTrashed()->count());
        $this->assertNull(KingbaseAdvancedSoftItem::withTrashed()->find(2));
    }

    public function testModelPagination(): void
    {
        $this->seedItems(7);

        $paginator = KingbaseAdvancedItem::order('id', 'asc')->paginate([
            'list_rows' => 3,
            'page' => 2,
        ]);

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertSame(7, $paginator->total());
        $this->assertSame(3, $paginator->listRows());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame(3, $paginator->lastPage());
        $this->assertCount(3, $paginator);
        $this->assertTrue(isset($paginator[0]));
        $this->assertSame(4, (int) $paginator[0]->id);
        $this->assertSame([4, 5, 6], array_map(
            static fn ($item): int => (int) $item->id,
            $paginator->getCollection()->all()
        ));

        $iteratedIds = [];
        foreach ($paginator as $item) {
            $iteratedIds[] = (int) $item->id;
        }
        $this->assertSame([4, 5, 6], $iteratedIds);

        $serialized = json_decode((string) json_encode($paginator), true);
        $this->assertIsArray($serialized);
        $this->assertSame(2, (int) $serialized['current_page']);
        $this->assertSame(3, (int) $serialized['per_page']);
        $this->assertSame(7, (int) $serialized['total']);
        $this->assertCount(3, $serialized['data']);
    }

    /**
     * Replays the original ThinkORM accessor, mutator, searcher and local-scope
     * behavior against each KingbaseES compatibility mode.
     */
    public function testAccessorMutatorSearcherAndLocalScope(): void
    {
        KingbaseAdvancedFeatureItem::create(['id' => 1, 'name' => '  Alpha  ', 'status' => 1]);
        KingbaseAdvancedFeatureItem::create(['id' => 2, 'name' => 'Beta', 'status' => 0]);
        KingbaseAdvancedFeatureItem::create(['id' => 3, 'name' => 'Gamma', 'status' => 1]);

        $raw = $this->conn()->table(KingbaseOrmAdvancedContext::table('item'))->where('id', 1)->find();
        $this->assertSame('Alpha', (string) $raw['name']);

        $alpha = KingbaseAdvancedFeatureItem::find(1);
        $this->assertNotNull($alpha);
        $this->assertSame('ALPHA', (string) $alpha->name);
        $this->assertSame('enabled', (string) $alpha->status_text);

        $searched = KingbaseAdvancedFeatureItem::withSearch(['name'], ['name' => 'Beta'])->select();
        $this->assertCount(1, $searched);
        $this->assertSame(2, (int) $searched[0]->id);

        $activeIds = KingbaseAdvancedFeatureItem::active()->order('id', 'asc')->column('id');
        $this->assertSame([1, 3], array_map('intval', array_values($activeIds)));
    }

    /**
     * Replays the original model insert/update/delete events and automatic
     * timestamp fields without relying on database-generated primary keys.
     */
    public function testModelLifecycleEventsAndAutomaticTimestamps(): void
    {
        KingbaseAdvancedEventItem::resetEventFlags();
        $item = KingbaseAdvancedEventItem::create([
            'id' => 20,
            'name' => 'event-item',
            'status' => 1,
        ]);

        $flags = KingbaseAdvancedEventItem::eventFlags();
        $this->assertTrue($flags['before_insert']);
        $this->assertTrue($flags['after_insert']);
        $this->assertSame('insert:event-item', (string) $item->name);
        $this->assertNotEmpty($item->getData('create_time'));

        KingbaseAdvancedEventItem::resetEventFlags();
        $item->name = 'event-updated';
        $this->assertTrue($item->save());
        $flags = KingbaseAdvancedEventItem::eventFlags();
        $this->assertTrue($flags['before_update']);
        $this->assertTrue($flags['after_update']);
        $this->assertSame('update:event-updated', (string) $item->name);
        $this->assertNotEmpty($item->getData('update_time'));

        KingbaseAdvancedEventItem::resetEventFlags();
        $this->assertTrue($item->delete());
        $flags = KingbaseAdvancedEventItem::eventFlags();
        $this->assertTrue($flags['before_delete']);
        $this->assertTrue($flags['after_delete']);
        $this->assertNull(KingbaseAdvancedEventItem::find(20));
    }

    /**
     * Replays the original global-scope, change-detection and increment /
     * decrement behavior against the shared three-mode table definition.
     */
    public function testGlobalScopeChangeDetectionAndFieldHelpers(): void
    {
        KingbaseAdvancedItem::saveAll([
            ['id' => 30, 'name' => 'scope-a', 'status' => 1],
            ['id' => 31, 'name' => 'scope-b', 'status' => 0],
            ['id' => 32, 'name' => 'scope-c', 'status' => 1],
        ], false);

        $this->assertSame(2, KingbaseAdvancedScopedItem::count());
        $this->assertSame(3, KingbaseAdvancedScopedItem::withoutGlobalScope()->count());

        $item = KingbaseAdvancedItem::find(30);
        $this->assertNotNull($item);
        $item->name = 'scope-a-updated';
        $this->assertTrue($item->isChange('name'));
        $this->assertTrue($item->save());
        $this->assertFalse($item->isChange('name'));

        $item->inc('status', 2)->save();
        $this->assertSame(3, (int) KingbaseAdvancedItem::where('id', 30)->value('status'));
        $item->dec('status')->save();
        $this->assertSame(2, (int) KingbaseAdvancedItem::where('id', 30)->value('status'));
    }

    public function testSaveAllChunkCursorAndBatchUpdate(): void
    {
        $saved = $this->seedItems(9);
        $this->assertCount(9, $saved);

        $chunkSizes = [];
        $chunkIds = [];
        $completed = KingbaseAdvancedItem::chunk(4, function ($items) use (&$chunkSizes, &$chunkIds): bool {
            $chunkSizes[] = count($items);
            foreach ($items as $item) {
                $chunkIds[] = (int) $item->id;
            }

            return true;
        }, 'id', 'asc');

        $this->assertTrue($completed);
        $this->assertSame([4, 4, 1], $chunkSizes);
        $this->assertSame(range(1, 9), $chunkIds);

        $cursorIds = [];
        foreach (KingbaseAdvancedItem::order('id', 'asc')->cursor() as $item) {
            $cursorIds[] = (int) $item->id;
        }
        $this->assertSame(range(1, 9), $cursorIds);

        $affected = KingbaseAdvancedItem::whereIn('id', [2, 4, 6, 8])->update(['status' => 1]);
        $this->assertSame(4, $affected);
        $this->assertSame(4, KingbaseAdvancedItem::where('status', 1)->count());
    }

    public function testSqlLookingBoundValueRemainsData(): void
    {
        $table = KingbaseOrmAdvancedContext::table('item');
        $sqlLookingValue = "x'); DROP TABLE " . $table . "; --\t\r\n";

        KingbaseAdvancedItem::create([
            'id' => 1,
            'name' => $sqlLookingValue,
            'status' => 1,
        ]);

        $loaded = KingbaseAdvancedItem::where('name', $sqlLookingValue)->find();
        $this->assertNotNull($loaded);
        $this->assertSame($sqlLookingValue, (string) $loaded->name);
        $this->assertSame(1, $this->conn()->table($table)->count());

        $this->conn()->table($table)->insert([
            'id' => 2,
            'name' => 'table-still-available',
            'status' => 0,
        ]);
        $this->assertSame(2, $this->conn()->table($table)->count());
    }

    public function testConnectionFailureDiagnosticRedactsPassword(): void
    {
        $config = static::$connectionConfig;
        $syntheticPassword = 'kdb-diagnostic-secret-must-not-leak';
        $config['hostport'] = '1';
        $config['password'] = $syntheticPassword;

        $exception = null;
        try {
            Db::connect($config, true)->query('SELECT 1 AS diagnostic_probe');
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'An unreachable KingbaseES endpoint did not fail');
        $message = (string) $exception->getMessage();
        $this->assertStringContainsString('host=127.0.0.1', $message);
        $this->assertStringContainsString('port=1', $message);
        $this->assertStringContainsString('database=', $message);
        $this->assertStringNotContainsString($syntheticPassword, $message);
        $this->assertStringNotContainsString("\n", $message);
    }

    public function testConcurrentAtomicUpdatesAcrossIndependentConnections(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl extension is required for the real concurrency test');
        }

        $table = KingbaseOrmAdvancedContext::table('counter');
        $this->conn()->table($table)->insert([
            'id' => 1,
            'counter_value' => 0,
        ]);

        // Never fork with a live PDO handle. pdo_kdb closes the underlying
        // server session when a copied child handle is closed, which also
        // invalidates the parent's inherited connection.
        $this->conn()->close();

        $errorFiles = [];
        $children = [];
        for ($worker = 0; $worker < self::CONCURRENT_WORKERS; $worker++) {
            $errorFile = tempnam(sys_get_temp_dir(), 'kb_orm_concurrency_');
            if (false === $errorFile) {
                $this->fail('Unable to allocate concurrency error file');
            }
            $errorFiles[] = $errorFile;

            $pid = pcntl_fork();
            if (-1 === $pid) {
                $this->fail('Unable to fork concurrency worker');
            }

            if (0 === $pid) {
                $this->runConcurrentWorker($table, $errorFile);
            }

            $children[$pid] = $errorFile;
        }

        $errors = $this->waitForWorkers($children, 30.0);
        foreach ($errorFiles as $errorFile) {
            @unlink($errorFile);
        }

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));
        $value = $this->conn()->table($table)->where('id', 1)->value('counter_value');
        $this->assertSame(self::CONCURRENT_WORKERS * self::UPDATES_PER_WORKER, (int) $value);
    }

    private function seedItems(int $count)
    {
        $data = [];
        for ($id = 1; $id <= $count; $id++) {
            $data[] = [
                'id' => $id,
                'name' => 'item-' . $id,
                'status' => 0,
            ];
        }

        return KingbaseAdvancedItem::saveAll($data, false);
    }

    private function dropAdvancedTables(): void
    {
        foreach (['post_tag', 'profile', 'post', 'author', 'tag', 'soft_item', 'item', 'counter'] as $suffix) {
            $this->dropIfExists('DROP TABLE ' . KingbaseOrmAdvancedContext::table($suffix));
        }
    }

    private function runConcurrentWorker(string $table, string $errorFile): void
    {
        try {
            $connection = Db::connect(static::$connectionName);

            for ($iteration = 0; $iteration < self::UPDATES_PER_WORKER; $iteration++) {
                $affected = $connection->table($table)
                    ->where('id', 1)
                    ->update(['counter_value' => Db::raw('counter_value + 1')]);
                if (1 !== $affected) {
                    throw new RuntimeException('Atomic update affected ' . $affected . ' rows');
                }
                usleep(2000);
            }

            exit(0);
        } catch (\Throwable $e) {
            file_put_contents($errorFile, get_class($e) . ': ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * @param array<int, string> $children
     * @return list<string>
     */
    private function waitForWorkers(array $children, float $timeoutSeconds): array
    {
        $errors = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while ([] !== $children && microtime(true) < $deadline) {
            foreach ($children as $pid => $errorFile) {
                $status = 0;
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result <= 0) {
                    continue;
                }

                $message = trim((string) @file_get_contents($errorFile));
                if (!pcntl_wifexited($status) || 0 !== pcntl_wexitstatus($status)) {
                    $errors[] = 'worker ' . $pid . ' failed' . ('' === $message ? '' : ': ' . $message);
                }
                unset($children[$pid]);
            }

            if ([] !== $children) {
                usleep(10000);
            }
        }

        foreach ($children as $pid => $errorFile) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
            pcntl_waitpid($pid, $status);
            $message = trim((string) @file_get_contents($errorFile));
            $errors[] = 'worker ' . $pid . ' timed out' . ('' === $message ? '' : ': ' . $message);
        }

        return $errors;
    }
}

final class KingbaseOrmAdvancedContext
{
    public static string $connection = '';

    public static string $tablePrefix = '';

    public static function table(string $suffix): string
    {
        return self::$tablePrefix . '_' . $suffix;
    }
}

abstract class KingbaseAdvancedModel extends Model
{
    protected static string $tableSuffix = '';

    protected function getBaseOptions(): array
    {
        return [
            'connection' => KingbaseOrmAdvancedContext::$connection,
            'table' => KingbaseOrmAdvancedContext::table(static::$tableSuffix),
            'pk' => 'id',
        ];
    }
}

final class KingbaseAdvancedAuthor extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'author';

    public function posts()
    {
        return $this->hasMany(KingbaseAdvancedPost::class, 'author_id', 'id');
    }

    public function profile()
    {
        return $this->hasOne(KingbaseAdvancedProfile::class, 'author_id', 'id');
    }
}

final class KingbaseAdvancedPost extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'post';

    public function author()
    {
        return $this->belongsTo(KingbaseAdvancedAuthor::class, 'author_id', 'id');
    }

    public function tags()
    {
        return $this->belongsToMany(
            KingbaseAdvancedTag::class,
            KingbaseOrmAdvancedContext::table('post_tag'),
            'tag_id',
            'post_id'
        );
    }
}

final class KingbaseAdvancedProfile extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'profile';

    public function author()
    {
        return $this->belongsTo(KingbaseAdvancedAuthor::class, 'author_id', 'id');
    }
}

final class KingbaseAdvancedTag extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'tag';

    public function posts()
    {
        return $this->belongsToMany(
            KingbaseAdvancedPost::class,
            KingbaseOrmAdvancedContext::table('post_tag'),
            'post_id',
            'tag_id'
        );
    }
}

final class KingbaseAdvancedSoftItem extends KingbaseAdvancedModel
{
    use SoftDelete;

    protected static string $tableSuffix = 'soft_item';

    protected $deleteTime = 'delete_time';
}

final class KingbaseAdvancedItem extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'item';
}

final class KingbaseAdvancedFeatureItem extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'item';

    public function getNameAttr($value): string
    {
        return strtoupper((string) $value);
    }

    public function setNameAttr($value): string
    {
        return trim((string) $value);
    }

    public function getStatusTextAttr($value, array $data): string
    {
        return 1 === (int) ($data['status'] ?? 0) ? 'enabled' : 'disabled';
    }

    public function searchNameAttr($query, $value): void
    {
        $query->where('name', 'like', '%' . $value . '%');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}

final class KingbaseAdvancedScopedItem extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'item';

    protected $globalScope = ['Active'];

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}

final class KingbaseAdvancedEventItem extends KingbaseAdvancedModel
{
    protected static string $tableSuffix = 'item';

    protected $autoWriteTimestamp = 'datetime';

    private static bool $beforeInsert = false;

    private static bool $afterInsert = false;

    private static bool $beforeUpdate = false;

    private static bool $afterUpdate = false;

    private static bool $beforeDelete = false;

    private static bool $afterDelete = false;

    public static function resetEventFlags(): void
    {
        self::$beforeInsert = false;
        self::$afterInsert = false;
        self::$beforeUpdate = false;
        self::$afterUpdate = false;
        self::$beforeDelete = false;
        self::$afterDelete = false;
    }

    /** @return array<string, bool> */
    public static function eventFlags(): array
    {
        return [
            'before_insert' => self::$beforeInsert,
            'after_insert' => self::$afterInsert,
            'before_update' => self::$beforeUpdate,
            'after_update' => self::$afterUpdate,
            'before_delete' => self::$beforeDelete,
            'after_delete' => self::$afterDelete,
        ];
    }

    public function onBeforeInsert($model): void
    {
        self::$beforeInsert = true;
        $model->name = 'insert:' . $model->name;
    }

    public function onAfterInsert(): void
    {
        self::$afterInsert = true;
    }

    public function onBeforeUpdate($model): void
    {
        self::$beforeUpdate = true;
        $model->name = 'update:' . $model->name;
    }

    public function onAfterUpdate(): void
    {
        self::$afterUpdate = true;
    }

    public function onBeforeDelete(): void
    {
        self::$beforeDelete = true;
    }

    public function onAfterDelete(): void
    {
        self::$afterDelete = true;
    }
}

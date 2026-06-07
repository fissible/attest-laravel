<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Import;

use Fissible\AttestLaravel\Import\EloquentImportMarkerTrait;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

final class EloquentImportMarkerTraitTest extends TestCase
{
    use DatabaseMigrations;

    public function test_has_imported_is_false_before_marker_and_true_after_marker(): void
    {
        $marker = new MarkerFixture(DB::connection(), 'station.updater.audit.global.v1');
        $hash = str_repeat('a', 64);

        self::assertFalse($marker->has($hash));
        self::assertTrue($marker->mark($hash, '01J00000000000000000000000'));
        self::assertTrue($marker->has($hash));
    }

    public function test_mark_imported_returns_false_for_duplicate_marker(): void
    {
        $marker = new MarkerFixture(DB::connection(), 'station.updater.audit.global.v1');
        $hash = str_repeat('b', 64);

        self::assertTrue($marker->mark($hash, '01J00000000000000000000001'));
        self::assertFalse($marker->mark($hash, '01J00000000000000000000002'));
        self::assertSame(1, DB::table('attest_import_markers')->count());
    }

    public function test_same_content_hash_can_be_marked_under_two_importers(): void
    {
        $hash = str_repeat('c', 64);
        $first = new MarkerFixture(DB::connection(), 'station.updater.audit.global.v1');
        $second = new MarkerFixture(DB::connection(), 'station.cms.publish.global.v1');

        self::assertTrue($first->mark($hash, '01J00000000000000000000003'));
        self::assertTrue($second->mark($hash, '01J00000000000000000000004'));

        self::assertSame(2, DB::table('attest_import_markers')->count());
    }

    public function test_invalid_importer_namespace_throws(): void
    {
        $marker = new MarkerFixture(DB::connection(), '');

        $this->expectException(\InvalidArgumentException::class);

        $marker->has(str_repeat('d', 64));
    }

    public function test_too_long_importer_namespace_throws(): void
    {
        $marker = new MarkerFixture(DB::connection(), str_repeat('x', 192));

        $this->expectException(\InvalidArgumentException::class);

        $marker->has(str_repeat('e', 64));
    }

    public function test_invalid_content_hash_throws(): void
    {
        $marker = new MarkerFixture(DB::connection(), 'station.updater.audit.global.v1');

        $this->expectException(\InvalidArgumentException::class);

        $marker->has(str_repeat('F', 64));
    }

    public function test_imported_at_is_stored(): void
    {
        $marker = new MarkerFixture(DB::connection(), 'station.updater.audit.global.v1');
        $hash = str_repeat('f', 64);

        $marker->mark($hash, '01J00000000000000000000005');

        $value = DB::table('attest_import_markers')->where('content_hash', $hash)->value('imported_at');
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value);
    }
}

final class MarkerFixture
{
    use EloquentImportMarkerTrait {
        hasImported as private traitHasImported;
        markImported as private traitMarkImported;
    }

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $importer,
    ) {
    }

    public function has(string $contentHash): bool
    {
        return $this->traitHasImported($contentHash);
    }

    public function mark(string $contentHash, string $envelopeId): bool
    {
        return $this->traitMarkImported($contentHash, $envelopeId);
    }

    protected function importMarkerConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    protected function importer(): string
    {
        return $this->importer;
    }
}

<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Import;

use Fissible\Attest\Canonical\JcsEncoder;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Import\EloquentImportMarkerTrait;
use Fissible\AttestLaravel\Import\GenericJsonlImporter;
use Fissible\AttestLaravel\Import\JsonlImportContext;
use Fissible\AttestLaravel\Import\JsonlImportException;
use Fissible\AttestLaravel\Import\JsonlImportOptions;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class GenericJsonlImporterTest extends TestCase
{
    use DatabaseMigrations;

    public function test_imports_valid_jsonl_into_evidence_chain(): void
    {
        $importer = $this->fixtureImporter();

        $result = $importer->importLines([
            $this->jsonLine(['id' => 1, 'message' => 'installed']),
        ]);

        self::assertSame(1, $result->linesRead);
        self::assertSame(1, $result->parsed);
        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->alreadyImported);
        self::assertCount(1, $result->envelopeIds);
        self::assertSame(1, DB::table('attest_envelopes')->count());
        self::assertSame(1, DB::table('attest_import_markers')->count());

        $signed = EnvelopeCodec::decodeSigned((string) DB::table('attest_envelopes')->value('raw_envelope'));
        self::assertSame('import:test', $signed->envelope->chain);
        self::assertSame('attest.import.jsonl.recorded.v1', $signed->envelope->type);
        self::assertSame('fixture.feed.v1', $signed->envelope->payload['source']['importer']);
        self::assertSame(1, $signed->envelope->payload['source']['line_number']);
        self::assertSame(['id' => 1, 'message' => 'installed'], $signed->envelope->payload['record']);
    }

    public function test_rerunning_same_jsonl_writes_no_duplicate_envelopes(): void
    {
        $importer = $this->fixtureImporter();
        $line = $this->jsonLine(['id' => 1, 'message' => 'installed']);

        $first = $importer->importLines([$line]);
        $second = $importer->importLines([$line]);

        self::assertSame(1, $first->imported);
        self::assertSame(0, $second->imported);
        self::assertSame(1, $second->alreadyImported);
        self::assertSame(1, DB::table('attest_envelopes')->count());
        self::assertSame(1, DB::table('attest_import_markers')->count());
    }

    public function test_duplicate_lines_in_one_file_produce_one_envelope(): void
    {
        $importer = $this->fixtureImporter();
        $line = $this->jsonLine(['id' => 2, 'message' => 'same']);

        $result = $importer->importLines([$line, $line]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadyImported);
        self::assertSame(1, DB::table('attest_envelopes')->count());
    }

    public function test_parse_line_null_increments_skipped_count(): void
    {
        $importer = $this->fixtureImporter();

        $result = $importer->importLines([
            '',
            $this->jsonLine(['_skip' => true]),
            $this->jsonLine(['id' => 3]),
        ]);

        self::assertSame(3, $result->linesRead);
        self::assertSame(1, $result->parsed);
        self::assertSame(2, $result->skipped);
        self::assertSame(1, $result->imported);
    }

    public function test_invalid_json_fails_fast_and_does_not_mark_failed_line(): void
    {
        $importer = $this->fixtureImporter();
        $badHash = str_repeat('d', 64);

        try {
            $importer->importLines([
                $this->jsonLine(['id' => 4]),
                '{"content_hash":"' . $badHash . '",',
                $this->jsonLine(['id' => 5]),
            ]);
            self::fail('Expected fail-fast import exception.');
        } catch (JsonlImportException $e) {
            self::assertSame(2, $e->lineNumber);
        }

        self::assertSame(1, DB::table('attest_envelopes')->count());
        self::assertSame(1, DB::table('attest_import_markers')->count());
        self::assertFalse(DB::table('attest_import_markers')->where('content_hash', $badHash)->exists());
    }

    public function test_continue_on_error_imports_later_valid_lines(): void
    {
        $importer = $this->fixtureImporter();

        $result = $importer->importLines([
            $this->jsonLine(['id' => 6]),
            '{"bad"',
            $this->jsonLine(['id' => 7]),
        ], new JsonlImportOptions(continueOnError: true));

        self::assertSame(3, $result->linesRead);
        self::assertSame(2, $result->imported);
        self::assertSame(1, $result->failed);
        self::assertCount(1, $result->failures);
        self::assertSame(2, $result->failures[0]->lineNumber);
        self::assertSame(2, DB::table('attest_envelopes')->count());
    }

    public function test_same_hash_under_two_importers_can_produce_two_envelopes(): void
    {
        $hash = str_repeat('e', 64);
        $line = $this->jsonLine(['id' => 8, 'content_hash' => $hash]);

        $first = $this->fixtureImporter('fixture.feed.v1');
        $second = $this->fixtureImporter('fixture.feed.v2');

        self::assertSame(1, $first->importLines([$line])->imported);
        self::assertSame(1, $second->importLines([$line])->imported);
        self::assertSame(2, DB::table('attest_envelopes')->count());
        self::assertSame(2, DB::table('attest_import_markers')->count());
    }

    public function test_same_hash_same_importer_different_target_chain_is_skipped(): void
    {
        $hash = str_repeat('f', 64);
        $importer = $this->fixtureImporter();

        $result = $importer->importLines([
            $this->jsonLine(['id' => 9, 'chain' => 'import:first', 'content_hash' => $hash]),
            $this->jsonLine(['id' => 9, 'chain' => 'import:second', 'content_hash' => $hash]),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->alreadyImported);
        self::assertSame(1, DB::table('attest_envelopes')->where('chain_id', 'import:first')->count());
        self::assertSame(0, DB::table('attest_envelopes')->where('chain_id', 'import:second')->count());
    }

    public function test_duplicate_marker_race_aborts_append_without_extra_envelope(): void
    {
        $importer = $this->fixtureImporter();
        $importer->forceDuplicateMarker = true;

        $result = $importer->importLines([
            $this->jsonLine(['id' => 10]),
        ]);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadyImported);
        self::assertSame(0, DB::table('attest_envelopes')->count());
        self::assertSame(0, DB::table('attest_import_markers')->count());
    }

    public function test_envelope_insert_failure_after_marker_insertion_rolls_back_marker(): void
    {
        $firstHash = str_repeat('1', 64);
        $secondHash = str_repeat('2', 64);
        $dbPath = tempnam(sys_get_temp_dir(), 'attest-import-rollback-');
        self::assertIsString($dbPath);
        $connectionName = 'import_rollback_' . str_replace('.', '_', uniqid('', true));

        config()->set("database.connections.$connectionName", [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'foreign_key_constraints' => true,
        ]);
        $this->artisan('migrate', ['--database' => $connectionName])->run();

        $connection = DB::connection($connectionName);
        $store = new EloquentChainStore(
            $connection,
            new SqliteChainLocker($connection, 5),
            Event::getFacadeRoot(),
        );

        $importer = new FixtureJsonlImporter(
            store: $store,
            signer: new SodiumSigner(KeyPair::generate(), 'fixture-key'),
            connection: $connection,
            importer: 'fixture.feed.v1',
        );
        $importer->envelopeIds = [
            '01J00000000000000000000006',
            '01J00000000000000000000006',
        ];

        try {
            $importer->importLines([$this->jsonLine(['id' => 11, 'content_hash' => $firstHash])]);

            try {
                $importer->importLines([$this->jsonLine(['id' => 12, 'content_hash' => $secondHash])]);
                self::fail('Expected duplicate envelope ID to fail append.');
            } catch (JsonlImportException $e) {
                self::assertSame(1, $e->lineNumber);
            }

            self::assertSame(1, $connection->table('attest_envelopes')->count());
            self::assertTrue($connection->table('attest_import_markers')->where('content_hash', $firstHash)->exists());
            self::assertFalse($connection->table('attest_import_markers')->where('content_hash', $secondHash)->exists());
        } finally {
            DB::purge($connectionName);
            @unlink($dbPath);
        }
    }

    public function test_import_file_reads_jsonl_from_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attest-jsonl-');
        self::assertIsString($path);
        file_put_contents($path, $this->jsonLine(['id' => 13]) . "\n");

        try {
            $result = $this->fixtureImporter()->importFile($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result->imported);
        self::assertSame(1, DB::table('attest_envelopes')->count());
    }

    public function test_station_shaped_audit_rows_import_into_updater_global_chain(): void
    {
        $importer = $this->stationImporter();

        $result = $importer->importLines([
            $this->jsonLine(['ts' => '2026-06-07T10:00:00+00:00', 'event' => 'run.started', 'run_id' => 'RUN-1']),
            $this->jsonLine(['ts' => '2026-06-07T10:01:00+00:00', 'event' => 'step.completed', 'run_id' => 'RUN-1', 'step_index' => 1]),
        ]);

        self::assertSame(2, $result->imported);
        self::assertSame(2, DB::table('attest_envelopes')->where('chain_id', 'updater:global')->count());

        $signed = EnvelopeCodec::decodeSigned((string) DB::table('attest_envelopes')->orderBy('sequence')->value('raw_envelope'));
        self::assertSame('station.updater.audit.v1', $signed->envelope->type);
        self::assertSame('RUN-1', $signed->envelope->correlation);
        self::assertSame('station.updater.audit.global.v1', $signed->envelope->payload['source']['importer']);
    }

    public function test_station_shaped_audit_replay_and_reorder_are_no_ops(): void
    {
        $importer = $this->stationImporter();
        $first = $this->jsonLine(['ts' => '2026-06-07T10:00:00+00:00', 'event' => 'run.started', 'run_id' => 'RUN-2']);
        $second = $this->jsonLine(['ts' => '2026-06-07T10:01:00+00:00', 'event' => 'step.completed', 'run_id' => 'RUN-2']);

        $importer->importLines([$first, $second]);
        $result = $importer->importLines([$second, $first]);

        self::assertSame(0, $result->imported);
        self::assertSame(2, $result->alreadyImported);
        self::assertSame(2, DB::table('attest_envelopes')->count());
    }

    public function test_station_shaped_malformed_row_fails_without_marker(): void
    {
        $importer = $this->stationImporter();

        try {
            $importer->importLines([$this->jsonLine(['ts' => '2026-06-07T10:00:00+00:00'])]);
            self::fail('Expected malformed Station row to fail.');
        } catch (JsonlImportException $e) {
            self::assertSame(1, $e->lineNumber);
        }

        self::assertSame(0, DB::table('attest_envelopes')->count());
        self::assertSame(0, DB::table('attest_import_markers')->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonLine(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        return $json;
    }

    private function fixtureImporter(string $importer = 'fixture.feed.v1'): FixtureJsonlImporter
    {
        return new FixtureJsonlImporter(
            store: $this->app->make(ChainStore::class),
            signer: new SodiumSigner(KeyPair::generate(), 'fixture-key'),
            connection: DB::connection(),
            importer: $importer,
        );
    }

    private function stationImporter(): StationAuditJsonlImporter
    {
        return new StationAuditJsonlImporter(
            store: $this->app->make(ChainStore::class),
            signer: new SodiumSigner(KeyPair::generate(), 'station-key'),
            connection: DB::connection(),
        );
    }
}

final class FixtureJsonlImporter extends GenericJsonlImporter
{
    use EloquentImportMarkerTrait {
        hasImported as private markerHasImported;
        markImported as private markerMarkImported;
    }

    public bool $forceDuplicateMarker = false;

    /** @var list<string> */
    public array $envelopeIds = [];

    public function __construct(
        ChainStore $store,
        SodiumSigner $signer,
        private readonly ConnectionInterface $connection,
        private readonly string $importer,
    ) {
        parent::__construct($store, $signer);
    }

    protected function importer(): string
    {
        return $this->importer;
    }

    protected function importMarkerConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected function parseLine(string $line, int $lineNumber): ?array
    {
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            throw new JsonlImportException('Line is not a JSON object', $lineNumber);
        }
        if (($decoded['_skip'] ?? false) === true) {
            return null;
        }
        return $decoded;
    }

    protected function buildPayload(array $parsed, JsonlImportContext $context): array
    {
        unset($parsed['chain'], $parsed['type'], $parsed['content_hash']);
        return $parsed;
    }

    protected function chainIdFor(array $parsed, JsonlImportContext $context): string
    {
        return is_string($parsed['chain'] ?? null) ? $parsed['chain'] : 'import:test';
    }

    protected function contentHashFor(array $parsed, JsonlImportContext $context): string
    {
        if (is_string($parsed['content_hash'] ?? null)) {
            return $parsed['content_hash'];
        }
        unset($parsed['chain'], $parsed['type']);
        return hash('sha256', JcsEncoder::encode($parsed));
    }

    protected function typeFor(array $parsed, JsonlImportContext $context): string
    {
        return is_string($parsed['type'] ?? null) ? $parsed['type'] : parent::typeFor($parsed, $context);
    }

    protected function hasImported(string $contentHash): bool
    {
        return $this->markerHasImported($contentHash);
    }

    protected function markImported(string $contentHash, string $envelopeId): bool
    {
        if ($this->forceDuplicateMarker) {
            $this->forceDuplicateMarker = false;
            return false;
        }
        return $this->markerMarkImported($contentHash, $envelopeId);
    }

    protected function newEnvelopeId(): string
    {
        return array_shift($this->envelopeIds) ?? parent::newEnvelopeId();
    }
}

final class StationAuditJsonlImporter extends GenericJsonlImporter
{
    use EloquentImportMarkerTrait {
        hasImported as private markerHasImported;
        markImported as private markerMarkImported;
    }

    public function __construct(
        ChainStore $store,
        SodiumSigner $signer,
        private readonly ConnectionInterface $connection,
    ) {
        parent::__construct($store, $signer);
    }

    protected function importer(): string
    {
        return 'station.updater.audit.global.v1';
    }

    protected function importMarkerConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    protected function parseLine(string $line, int $lineNumber): ?array
    {
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            throw new JsonlImportException('Station audit row is not a JSON object', $lineNumber);
        }
        if (! is_string($decoded['ts'] ?? null) || ! is_string($decoded['event'] ?? null)) {
            throw new JsonlImportException('Station audit row requires ts and event strings', $lineNumber);
        }
        return $decoded;
    }

    protected function buildPayload(array $parsed, JsonlImportContext $context): array
    {
        $ts = $parsed['ts'];
        $event = $parsed['event'];
        unset($parsed['ts'], $parsed['event']);

        return [
            'ts' => $ts,
            'event' => $event,
            'context' => $parsed,
        ];
    }

    protected function chainIdFor(array $parsed, JsonlImportContext $context): string
    {
        return 'updater:global';
    }

    protected function contentHashFor(array $parsed, JsonlImportContext $context): string
    {
        $event = (string) $parsed['event'];
        $ts = (string) $parsed['ts'];
        unset($parsed['ts'], $parsed['event']);

        return hash('sha256', $ts . "\n" . $event . "\n" . JcsEncoder::encode($parsed));
    }

    protected function typeFor(array $parsed, JsonlImportContext $context): string
    {
        return 'station.updater.audit.v1';
    }

    protected function correlationFor(array $parsed, JsonlImportContext $context): ?string
    {
        return is_string($parsed['run_id'] ?? null) ? $parsed['run_id'] : null;
    }

    protected function hasImported(string $contentHash): bool
    {
        return $this->markerHasImported($contentHash);
    }

    protected function markImported(string $contentHash, string $envelopeId): bool
    {
        return $this->markerMarkImported($contentHash, $envelopeId);
    }
}

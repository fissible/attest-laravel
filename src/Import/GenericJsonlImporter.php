<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Envelope\EvidenceEnvelope;
use Fissible\Attest\Envelope\PayloadValidator;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\Signer;
use Symfony\Component\Uid\Ulid;

abstract class GenericJsonlImporter
{
    public function __construct(
        protected readonly ChainStore $store,
        protected readonly Signer $signer,
    ) {
    }

    abstract protected function importer(): string;

    /**
     * @return array<array-key, mixed>|null
     */
    abstract protected function parseLine(string $line, int $lineNumber): ?array;

    /**
     * @param array<array-key, mixed> $parsed
     * @return array<array-key, mixed>
     */
    abstract protected function buildPayload(array $parsed, JsonlImportContext $context): array;

    /**
     * @param array<array-key, mixed> $parsed
     */
    abstract protected function chainIdFor(array $parsed, JsonlImportContext $context): string;

    /**
     * @param array<array-key, mixed> $parsed
     */
    abstract protected function contentHashFor(array $parsed, JsonlImportContext $context): string;

    abstract protected function hasImported(string $contentHash): bool;

    abstract protected function markImported(string $contentHash, string $envelopeId): bool;

    /**
     * @param array<array-key, mixed> $parsed
     */
    protected function typeFor(array $parsed, JsonlImportContext $context): string
    {
        return 'attest.import.jsonl.recorded.v1';
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    protected function subjectFor(array $parsed, JsonlImportContext $context): ?string
    {
        return null;
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    protected function correlationFor(array $parsed, JsonlImportContext $context): ?string
    {
        return null;
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    protected function tenantFor(array $parsed, JsonlImportContext $context): ?string
    {
        return null;
    }

    protected function newEnvelopeId(): string
    {
        return (string) Ulid::generate();
    }

    public function importFile(string $path, ?JsonlImportOptions $options = null): JsonlImportResult
    {
        return $this->importLines($this->readFileLines($path), $options);
    }

    /**
     * @param iterable<string> $lines
     */
    public function importLines(iterable $lines, ?JsonlImportOptions $options = null): JsonlImportResult
    {
        $options ??= new JsonlImportOptions();
        $importer = $this->validatedImporter();

        $linesRead = 0;
        $parsedCount = 0;
        $imported = 0;
        $skipped = 0;
        $alreadyImported = 0;
        $failed = 0;
        $lastLineNumber = 0;
        $envelopeIds = [];
        $failures = [];

        foreach ($lines as $line) {
            $linesRead++;
            $lineNumber = $linesRead;
            $lastLineNumber = $lineNumber;

            try {
                if (! is_string($line)) {
                    throw new JsonlImportException('JSONL import line must be a string', $lineNumber);
                }

                $rawLine = rtrim($line, "\r\n");
                if ($options->skipBlankLines && trim($rawLine) === '') {
                    $skipped++;
                    continue;
                }

                $parsed = $this->parseLine($rawLine, $lineNumber);
                if ($parsed === null) {
                    $skipped++;
                    continue;
                }
                $parsedCount++;

                $pendingContext = new JsonlImportContext($importer, $lineNumber, $rawLine, '');
                $contentHash = $this->validatedContentHash($this->contentHashFor($parsed, $pendingContext));
                $context = new JsonlImportContext($importer, $lineNumber, $rawLine, $contentHash);

                if ($this->hasImported($contentHash)) {
                    $alreadyImported++;
                    continue;
                }

                $chainId = $this->validatedChainId($this->chainIdFor($parsed, $context));
                $type = $this->validatedType($this->typeFor($parsed, $context));
                $payload = $this->wrappedPayload($parsed, $context);
                $subject = $this->subjectFor($parsed, $context);
                $correlation = $this->correlationFor($parsed, $context);
                $tenant = $this->tenantFor($parsed, $context);

                $signed = $this->store->append(
                    $chainId,
                    function (AppendContext $appendContext) use (
                        $type,
                        $payload,
                        $subject,
                        $correlation,
                        $tenant,
                        $contentHash
                    ): SignedEnvelope {
                        $envelope = new EvidenceEnvelope(
                            id: $this->newEnvelopeId(),
                            chain: $appendContext->chainId,
                            seq: $appendContext->sequence,
                            ts: $appendContext->timestampIso8601,
                            type: $type,
                            payload: $payload,
                            prevHash: $appendContext->prevHash,
                            keyId: $this->signer->keyId(),
                            sigAlg: 'ed25519',
                            subject: $subject,
                            correlation: $correlation,
                            tenant: $tenant,
                        );

                        $signed = SignedEnvelope::sign($envelope, $this->signer);
                        if (! $this->markImported($contentHash, $signed->envelope->id)) {
                            throw new AlreadyImported('Import marker already exists');
                        }

                        return $signed;
                    },
                );

                $imported++;
                $envelopeIds[] = $signed->envelope->id;
            } catch (AlreadyImported) {
                $alreadyImported++;
            } catch (\Throwable $e) {
                $failure = JsonlImportFailure::fromThrowable($lineNumber, $e);
                if (! $options->continueOnError) {
                    throw $this->toImportException($e, $lineNumber);
                }
                $failed++;
                $failures[] = $failure;
            }
        }

        return new JsonlImportResult(
            linesRead: $linesRead,
            parsed: $parsedCount,
            imported: $imported,
            skipped: $skipped,
            alreadyImported: $alreadyImported,
            failed: $failed,
            lastLineNumber: $lastLineNumber,
            envelopeIds: $envelopeIds,
            failures: $failures,
        );
    }

    /**
     * @param array<array-key, mixed> $parsed
     * @return array<string, mixed>
     */
    private function wrappedPayload(array $parsed, JsonlImportContext $context): array
    {
        $mappedPayload = PayloadValidator::ensure($this->buildPayload($parsed, $context));

        return PayloadValidator::ensure([
            'source' => [
                'importer' => $context->importer,
                'content_hash' => $context->contentHash,
                'line_number' => $context->lineNumber,
            ],
            'record' => $mappedPayload,
        ]);
    }

    private function validatedImporter(): string
    {
        $importer = $this->importer();
        if ($importer === '') {
            throw new \InvalidArgumentException('importer must not be empty');
        }
        if (strlen($importer) > 191) {
            throw new \InvalidArgumentException('importer must be <= 191 bytes');
        }
        return $importer;
    }

    private function validatedContentHash(string $contentHash): string
    {
        if (! preg_match('/^[a-f0-9]{64}\z/', $contentHash)) {
            throw new \InvalidArgumentException('contentHash must be a lower-case 64-character SHA-256 hex digest');
        }
        return $contentHash;
    }

    private function validatedChainId(string $chainId): string
    {
        if ($chainId === '') {
            throw new \InvalidArgumentException('chainId must not be empty');
        }
        return $chainId;
    }

    private function validatedType(string $type): string
    {
        if ($type === '') {
            throw new \InvalidArgumentException('type must not be empty');
        }
        if (strlen($type) > 191) {
            throw new \InvalidArgumentException('type must be <= 191 bytes');
        }
        return $type;
    }

    private function toImportException(\Throwable $throwable, int $lineNumber): JsonlImportException
    {
        if ($throwable instanceof JsonlImportException && $throwable->lineNumber === $lineNumber) {
            return $throwable;
        }

        return new JsonlImportException(
            sprintf('JSONL import failed on line %d: %s', $lineNumber, $throwable->getMessage()),
            lineNumber: $lineNumber,
            previous: $throwable,
        );
    }

    /**
     * @return \Generator<int, string>
     */
    private function readFileLines(string $path): \Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new JsonlImportException("Unable to open JSONL file for reading: $path");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
            if (! feof($handle)) {
                throw new JsonlImportException("Failed while reading JSONL file: $path");
            }
        } finally {
            fclose($handle);
        }
    }
}

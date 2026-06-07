<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Support;

use Fissible\Attest\Verification\ChainStats;
use Fissible\Attest\Verification\KeyMatch;
use Fissible\Attest\Verification\SignatureVerificationResult;
use Fissible\Attest\Verification\TrustedKey;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\Attest\Verification\VerificationResult;
use Fissible\Attest\Verification\Warning;
use Fissible\AttestLaravel\Support\CommandJson;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class CommandJsonTest extends TestCase
{
    public function test_verification_payload_matches_core_cli_result_schema(): void
    {
        $key = new TrustedKey(str_repeat('a', 32), keyId: 'issuer');
        $result = new VerificationResult(
            outcome: VerificationOutcome::VERIFIED,
            chainStats: new ChainStats('tenant:5', 1, 3, 3, 3, 0, 1),
            warnings: [
                new Warning('sample_warning', 'sample message', ['seq' => 2]),
            ],
            signatureResults: [
                SignatureVerificationResult::trusted([
                    new KeyMatch($key, KeyMatch::MATCHED_BY_KEY_ID),
                ]),
            ],
        );

        $payload = CommandJson::verification('attest:verify', $result, 0);

        self::assertSame('attest.cli.result.v1', $payload['format_version']);
        self::assertSame('attest:verify', $payload['command']);
        self::assertSame('verified', $payload['outcome']);
        self::assertTrue($payload['verified']);
        self::assertSame(0, $payload['exit_code']);
        self::assertSame('tenant:5', $payload['chain_stats']['chain_id']);
        self::assertSame(3, $payload['chain_stats']['envelope_count']);
        self::assertSame(['issuer' => 1], $payload['signature_summary']['trusted_keys_matched']);
        self::assertSame([
            [
                'code' => 'sample_warning',
                'message' => 'sample message',
                'context' => ['seq' => 2],
            ],
        ], $payload['warnings']);
    }

    public function test_warning_list_returns_stable_warning_shape(): void
    {
        $warnings = CommandJson::warningList([
            new Warning('first', 'First warning', ['a' => 1]),
            new Warning('second', 'Second warning'),
        ]);

        self::assertSame([
            ['code' => 'first', 'message' => 'First warning', 'context' => ['a' => 1]],
            ['code' => 'second', 'message' => 'Second warning', 'context' => []],
        ], $warnings);
    }

    public function test_print_writes_pretty_json(): void
    {
        $buffer = new BufferedOutput();
        $output = new OutputStyle(new ArrayInput([]), $buffer);

        CommandJson::print($output, ['format_version' => 'example.v1', 'url' => 'https://example.test/a/b']);

        self::assertSame(
            "{\n    \"format_version\": \"example.v1\",\n    \"url\": \"https://example.test/a/b\"\n}\n",
            $buffer->fetch(),
        );
    }
}

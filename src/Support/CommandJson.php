<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Cli\Output\JsonResultSchema;
use Fissible\Attest\Verification\VerificationResult;
use Fissible\Attest\Verification\Warning;
use Illuminate\Console\OutputStyle;

final class CommandJson
{
    /** @return array<string, mixed> */
    public static function verification(string $command, VerificationResult $result, int $exitCode): array
    {
        return JsonResultSchema::fromVerification($command, $result, $exitCode);
    }

    /**
     * @param list<Warning> $warnings
     * @return list<array{code:string,message:string,context:array<string, mixed>}>
     */
    public static function warningList(array $warnings): array
    {
        return array_map(
            static fn (Warning $warning): array => [
                'code' => $warning->code,
                'message' => $warning->message,
                'context' => $warning->context,
            ],
            $warnings,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function print(OutputStyle $output, array $payload): void
    {
        $output->writeln(json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));
    }
}

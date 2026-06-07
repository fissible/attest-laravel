<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

final readonly class UpgradeRunResult
{
    /**
     * @param list<UpgradedAnchor> $upgraded
     * @param list<UnchangedAnchor> $unchanged
     * @param list<FailedAnchor> $failed
     * @param list<string> $warnings
     */
    public function __construct(
        public array $upgraded = [],
        public array $unchanged = [],
        public array $failed = [],
        public array $warnings = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\DomainRegistrationResult;
use App\Domain\Websites\TlsInspectionResult;
use App\Domain\Websites\WebsiteCheckResult;

interface WebsiteProbeExecutor
{
    /** @param list<array<string, mixed>> $jobs
     * @return list<array{job_id:int,result:WebsiteCheckResult|TlsInspectionResult|DomainRegistrationResult}>
     */
    public function execute(array $jobs, int $concurrency): array;
}

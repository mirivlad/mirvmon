<?php

declare(strict_types=1);

namespace App\Workers;

use App\Domain\Websites\DomainRegistrationResult;
use App\Domain\Websites\TlsInspectionResult;
use App\Domain\Websites\WebsiteCheckResult;
use App\Repositories\DomainRegistrationRepository;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteTlsRepository;
use App\Services\WebsiteIncidentService;
use App\Services\WebsiteProbeExecutor;
use DateTimeImmutable;
use Throwable;

final class WebsiteCheckWorker
{
    public function __construct(
        private readonly WebsiteCheckQueueRepository $queue,
        private readonly WebsiteProbeExecutor $probes,
        private readonly WebsiteIncidentService $incidents,
        private readonly WebsiteTlsRepository $tls,
        private readonly DomainRegistrationRepository $domains,
        private readonly string $leaseOwner,
        private readonly int $concurrency = 10,
    ) {
    }

    public function runOnce(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $this->queue->scheduleDue($now, $this->concurrency * 10);
        $jobs = $this->queue->claim($this->leaseOwner, $now, $this->concurrency);
        $completed = $this->probes->execute($jobs, $this->concurrency);
        $completedIds = [];
        foreach ($completed as $item) {
            $job = $this->jobById($jobs, $item['job_id']);
            if ($job === []) {
                throw new \RuntimeException('Website probe returned an unknown job.');
            }
            $completedIds[(int) $item['job_id']] = true;
            try {
                $result = $item['result'];
                if ($result instanceof WebsiteCheckResult) {
                    $this->incidents->recordHttp($result);
                } elseif ($result instanceof TlsInspectionResult) {
                    $this->tls->record($result);
                    $this->incidents->recordTls($result);
                } elseif ($result instanceof DomainRegistrationResult) {
                    $this->domains->record((int) $job['website_id'], $result);
                    $this->incidents->recordDomain((int) $job['website_id'], $result);
                }
                $this->queue->complete((int) $item['job_id'], $this->leaseOwner);
            } catch (Throwable $exception) {
                $this->queue->release(
                    (int) $item['job_id'],
                    $this->leaseOwner,
                    $now->modify('+60 seconds'),
                    'internal_checker',
                );
                throw $exception;
            }
        }
        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            if (!isset($completedIds[$jobId])) {
                $this->queue->release(
                    $jobId,
                    $this->leaseOwner,
                    $now->modify('+60 seconds'),
                    'internal_checker',
                );
            }
        }

        return count($jobs);
    }

    /** @param list<array<string, mixed>> $jobs
     * @return array<string, mixed>
     */
    private function jobById(array $jobs, int $jobId): array
    {
        foreach ($jobs as $job) {
            if ((int) ($job['id'] ?? 0) === $jobId) {
                return $job;
            }
        }

        return [];
    }
}

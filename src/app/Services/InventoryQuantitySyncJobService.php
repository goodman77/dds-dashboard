<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryQuantitySyncJobModel;

class InventoryQuantitySyncJobService
{
    private const STALE_RUNNING_MINUTES = 15;

    private const CANCEL_STALE_SECONDS = 60;

    public function __construct(
        private readonly InventoryQuantitySyncJobModel $jobs,
        private readonly InventoryQuantitySyncService $sync,
    ) {
    }

    /**
     * Run a quantity sync immediately (CLI or direct invocation).
     *
     * @return array{
     *     job_id: int,
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>
     * }
     */
    public function runForSheet(string $sheetName, ?float $delaySeconds = null, bool $verbose = true, ?int $userId = null): array
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            throw new \InvalidArgumentException('Sheet name is required.');
        }

        $this->assertNoActiveJob();

        $jobId = (int) $this->jobs->insert([
            'user_id'          => $userId,
            'status'           => 'running',
            'sheet_name'       => $sheetName,
            'started_at'       => date('Y-m-d H:i:s'),
            'progress_message' => sprintf('Checking Net32 quantities for sheet "%s"...', $sheetName),
        ]);

        $logId = service('activityLog')->logInventoryQtySyncStarted($jobId, $sheetName, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        try {
            $result = $this->sync->syncSheetFromNet32(
                $sheetName,
                $delaySeconds,
                $verbose,
                $jobId,
                $logId,
            );

            $this->completeSyncRun($jobId, $logId, $sheetName, $result);

            return array_merge($result, ['job_id' => $jobId]);
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf('Sheet "%s": Net32 quantity sync failed: %s', $sheetName, $exception->getMessage()),
                [$exception->getMessage()],
                $sheetName,
            );

            throw $exception;
        }
    }

    /**
     * @return array{
     *     job_id: int,
     *     sheet_name: string,
     *     total: int,
     *     processed: int,
     *     updated: int,
     *     unchanged: int,
     *     missing: int,
     *     errors: list<string>
     * }
     */
    public function runForAll(?float $delaySeconds = null, bool $verbose = true, ?int $userId = null): array
    {
        $scope = InventoryQuantitySyncService::ALL_SHEETS;

        $this->assertNoActiveJob();

        $jobId = (int) $this->jobs->insert([
            'user_id'          => $userId,
            'status'           => 'running',
            'sheet_name'       => $scope,
            'started_at'       => date('Y-m-d H:i:s'),
            'progress_message' => 'Checking Net32 quantities for all sheets...',
        ]);

        $logId = service('activityLog')->logInventoryQtySyncStarted($jobId, $scope, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        try {
            $result = $this->sync->syncAllFromNet32(
                $delaySeconds,
                $verbose,
                $jobId,
                $logId,
            );

            $this->completeSyncRun($jobId, $logId, $scope, $result);

            return array_merge($result, ['job_id' => $jobId]);
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf('All sheets: Net32 quantity sync failed: %s', $exception->getMessage()),
                [$exception->getMessage()],
                $scope,
            );

            throw $exception;
        }
    }

    /**
     * Queue a full-inventory Net32 quantity sync for cron (shared hosting).
     *
     * @return array{queued: bool, spawned: bool, message: string, job_id?: int}
     */
    public function enqueueForAll(?int $userId = null): array
    {
        return $this->enqueueScope(InventoryQuantitySyncService::ALL_SHEETS, $userId);
    }

    /**
     * Queue a single-sheet Net32 quantity sync for cron (shared hosting).
     *
     * @return array{queued: bool, spawned: bool, message: string, job_id?: int}
     */
    public function enqueueForSheet(string $sheetName, ?int $userId = null): array
    {
        $sheetName = trim($sheetName);

        if ($sheetName === '') {
            throw new \InvalidArgumentException('Sheet name is required.');
        }

        return $this->enqueueScope($sheetName, $userId);
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException(sprintf('Quantity sync job %d not found.', $jobId));
        }

        if (in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $sheetName = trim((string) ($job['sheet_name'] ?? ''));

        if ($sheetName === '') {
            $this->failJob($jobId, $logId, 'Quantity sync job is missing a sheet name.', ['Missing sheet name.']);

            throw new \RuntimeException('Quantity sync job is missing a sheet name.');
        }

        $isAllSheets = $sheetName === InventoryQuantitySyncService::ALL_SHEETS;
        $startMessage = $isAllSheets
            ? 'Checking Net32 quantities for all sheets...'
            : sprintf('Checking Net32 quantities for sheet "%s"...', $sheetName);

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'started_at'       => date('Y-m-d H:i:s'),
            'progress_message' => $startMessage,
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                'running',
                $startMessage,
                [
                    'job_id'     => $jobId,
                    'sheet_name' => $sheetName,
                ],
            );
        }

        try {
            $result = $isAllSheets
                ? $this->sync->syncAllFromNet32(null, false, $jobId, $logId)
                : $this->sync->syncSheetFromNet32($sheetName, null, false, $jobId, $logId);

            $this->completeSyncRun($jobId, $logId, $sheetName, $result);
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf('Sheet "%s": Net32 quantity sync failed: %s', $sheetName, $exception->getMessage()),
                [$exception->getMessage()],
                $sheetName,
            );

            throw $exception;
        }
    }

    /**
     * @return array{ok: bool, message: string, status?: string, cancel_requested?: bool, job_id?: int}
     */
    public function requestCancel(?int $jobId = null): array
    {
        if ($jobId === null) {
            $active = $this->jobs->getActive();

            if ($active === null) {
                return ['ok' => false, 'message' => 'No active Net32 quantity sync job.'];
            }

            $jobId = (int) $active['id'];
        }

        $job = $this->jobs->find($jobId);

        if ($job === null) {
            return ['ok' => false, 'message' => sprintf('Quantity sync job %d not found.', $jobId)];
        }

        $status    = (string) $job['status'];
        $sheetName = trim((string) ($job['sheet_name'] ?? ''));

        if ($status === 'cancelled') {
            return [
                'ok'      => true,
                'message' => 'Net32 quantity sync already cancelled.',
                'status'  => 'cancelled',
                'job_id'  => $jobId,
            ];
        }

        if (! in_array($status, ['queued', 'running'], true)) {
            return ['ok' => false, 'message' => 'This quantity sync is not active.'];
        }

        $logId      = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $scopeLabel = $this->formatScopeLabel($sheetName);

        if ($status === 'queued') {
            $this->cancelJob(
                $jobId,
                $logId,
                sprintf('%s: Net32 quantity sync cancelled before it started.', $scopeLabel),
                $sheetName,
            );

            return [
                'ok'      => true,
                'message' => 'Net32 quantity sync cancelled.',
                'status'  => 'cancelled',
                'job_id'  => $jobId,
            ];
        }

        if ($this->isCancelRequested($jobId)) {
            return $this->forceCancelRunningJob($jobId, $job, $logId, $sheetName);
        }

        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $progress['cancel_requested'] = true;

        $this->jobs->update($jobId, [
            'progress_message' => sprintf('%s: Cancellation requested...', $scopeLabel),
            'result'           => $progress,
        ]);

        $cancelMessage = sprintf('%s: Cancellation requested...', $scopeLabel);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                'running',
                $cancelMessage,
                [
                    'job_id'           => $jobId,
                    'sheet_name'       => $sheetName,
                    'cancel_requested' => true,
                ],
            );
        }

        return [
            'ok'               => true,
            'message'          => 'Stopping Net32 quantity sync...',
            'status'           => 'running',
            'cancel_requested' => true,
            'job_id'           => $jobId,
        ];
    }

    public function isCancelRequested(int $jobId): bool
    {
        $job = $this->jobs->find($jobId);

        if ($job === null || (string) $job['status'] !== 'running') {
            return false;
        }

        $progress = $this->decodeJsonField($job['result'] ?? null);

        return ! empty($progress['cancel_requested']);
    }

    public function reconcileStuckJobs(): void
    {
        $this->recoverStaleRunningJobs();
        $this->recoverStuckCancellations();
    }

    /**
     * @return array{ok: bool, message: string, status?: string, cancel_requested?: bool, job_id?: int}
     */
    private function forceCancelRunningJob(int $jobId, array $job, ?int $logId, string $sheetName): array
    {
        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $processed = (int) ($progress['processed'] ?? 0);
        $total     = (int) ($progress['total'] ?? 0);
        $result    = [
            'sheet_name' => $sheetName,
            'total'      => $total,
            'processed'  => $processed,
            'updated'    => (int) ($progress['updated'] ?? 0),
            'unchanged'  => (int) ($progress['unchanged'] ?? 0),
            'missing'    => (int) ($progress['missing'] ?? 0),
            'errors'     => is_array($progress['errors'] ?? null) ? array_values($progress['errors']) : [],
            'cancelled'  => true,
        ];

        $this->cancelJob(
            $jobId,
            $logId,
            sprintf(
                '%s: Net32 quantity sync cancelled after checking %d of %d SKU(s).',
                $this->formatScopeLabel($sheetName),
                $processed,
                max($total, $processed),
            ),
            $sheetName,
            $result,
        );

        return [
            'ok'      => true,
            'message' => 'Net32 quantity sync cancelled.',
            'status'  => 'cancelled',
            'job_id'  => $jobId,
        ];
    }

    /**
     * @return array{
     *     processed: bool,
     *     action: 'idle'|'busy'|'started'|'completed'|'failed',
     *     message: string,
     *     job_id?: int
     * }
     */
    public function processQueue(): array
    {
        $this->reconcileStuckJobs();

        $running = $this->jobs->getRunning();

        if ($running !== null) {
            return [
                'processed' => false,
                'action'    => 'busy',
                'job_id'    => (int) $running['id'],
                'message'   => sprintf('Quantity sync job %d is already running.', $running['id']),
            ];
        }

        $queued = $this->jobs->getOldestQueued();

        if ($queued === null) {
            return [
                'processed' => false,
                'action'    => 'idle',
                'message'   => 'No queued Net32 quantity sync jobs.',
            ];
        }

        $jobId = (int) $queued['id'];

        if ($this->spawnBackgroundJob($jobId)) {
            return [
                'processed' => true,
                'action'    => 'started',
                'job_id'    => $jobId,
                'message'   => sprintf('Quantity sync job %d started in the background.', $jobId),
            ];
        }

        try {
            $this->run($jobId);
        } catch (\Throwable $exception) {
            return [
                'processed' => true,
                'action'    => 'failed',
                'job_id'    => $jobId,
                'message'   => $exception->getMessage(),
            ];
        }

        return [
            'processed' => true,
            'action'    => 'completed',
            'job_id'    => $jobId,
            'message'   => sprintf('Quantity sync job %d finished.', $jobId),
        ];
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function updateProgress(
        int $jobId,
        int $processed,
        int $total,
        string $sheetName,
        ?string $message = null,
        ?int $logId = null,
        array $progress = [],
    ): void {
        $progressMessage = $message ?? sprintf('Checked %d of %d SKU(s)...', $processed, $total);
        $job             = $this->jobs->find($jobId);
        $existing        = $this->decodeJsonField($job['result'] ?? null) ?? [];

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'progress_message' => mb_substr($progressMessage, 0, 255),
            'result'           => array_merge($existing, [
                'processed'   => $processed,
                'total'       => $total,
                'sheet_name'  => $sheetName,
                'current_sku' => $progress['current_sku'] ?? null,
                'updated'     => $progress['updated'] ?? ($existing['updated'] ?? 0),
                'unchanged'   => $progress['unchanged'] ?? ($existing['unchanged'] ?? 0),
                'missing'     => $progress['missing'] ?? ($existing['missing'] ?? 0),
                'errors'      => $progress['errors'] ?? ($existing['errors'] ?? []),
            ]),
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                'running',
                $progressMessage,
                array_merge([
                    'job_id'     => $jobId,
                    'sheet_name' => $sheetName,
                    'processed'  => $processed,
                    'total'      => $total,
                ], $progress),
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(?int $jobId = null): ?array
    {
        if ($jobId !== null) {
            $job = $this->jobs->find($jobId);
        } else {
            $job = $this->jobs->orderBy('id', 'DESC')->first();
        }

        if ($job === null) {
            return null;
        }

        $progress = $this->decodeJsonField($job['result'] ?? null);
        $errors   = $this->decodeJsonField($job['errors'] ?? null);
        $processed = (int) ($progress['processed'] ?? 0);
        $total     = (int) ($progress['total'] ?? 0);
        $isActive        = in_array($job['status'], ['queued', 'running'], true);
        $percent         = $this->calculateProgressPercent($processed, $total);
        $cancelRequested = ! empty($progress['cancel_requested']);

        return [
            'job_id'           => (int) $job['id'],
            'status'           => (string) $job['status'],
            'is_active'        => $isActive,
            'cancel_requested' => $cancelRequested,
            'can_cancel'       => $isActive && ! $cancelRequested,
            'sheet_name'       => (string) ($job['sheet_name'] ?? ''),
            'progress_message' => (string) ($job['progress_message'] ?? ''),
            'processed'        => $processed,
            'total'            => $total,
            'updated'          => (int) ($progress['updated'] ?? 0),
            'unchanged'        => (int) ($progress['unchanged'] ?? 0),
            'missing'          => (int) ($progress['missing'] ?? 0),
            'percent'          => $percent,
            'started_at'       => $job['started_at'] ?? null,
            'finished_at'      => $job['finished_at'] ?? null,
            'errors'           => is_array($errors) ? array_values($errors) : [],
        ];
    }

    public function calculateProgressPercent(int $processed, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        if ($processed >= $total) {
            return 100;
        }

        if ($processed <= 0) {
            return 0;
        }

        $percent = (int) floor(($processed / $total) * 100);

        return max(1, min(99, $percent));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finishJob(int $jobId, ?int $logId, string $status, array $result): void
    {
        $existing = $this->jobs->find($jobId);

        if ($existing !== null && (string) ($existing['status'] ?? '') === 'cancelled') {
            return;
        }

        $sheetName = (string) ($result['sheet_name'] ?? '');
        $message   = service('activityLog')->buildInventoryQtySyncMessage($result);

        $this->jobs->update($jobId, [
            'status'           => $status,
            'progress_message' => $message,
            'result'           => array_merge($result, [
                'processed' => (int) ($result['processed'] ?? 0),
                'total'     => (int) ($result['total'] ?? 0),
            ]),
            'errors'           => ($result['errors'] ?? []) !== [] ? array_values($result['errors']) : null,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                $status,
                $message,
                array_merge($result, ['job_id' => $jobId]),
            );
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function completeSyncRun(int $jobId, ?int $logId, string $sheetName, array $result): void
    {
        if (! empty($result['cancelled'])) {
            $this->cancelJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: Net32 quantity sync cancelled after checking %d of %d SKU(s).',
                    $this->formatScopeLabel($sheetName),
                    (int) ($result['processed'] ?? 0),
                    (int) ($result['total'] ?? 0),
                ),
                $sheetName,
                $result,
            );

            return;
        }

        $status = ($result['total'] ?? 0) === 0 && ($result['errors'] ?? []) !== []
            ? 'failed'
            : 'completed';

        $this->finishJob($jobId, $logId, $status, $result);
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function cancelJob(
        int $jobId,
        ?int $logId,
        string $message,
        ?string $sheetName = null,
        ?array $result = null,
    ): void {
        $update = [
            'status'           => 'cancelled',
            'progress_message' => $message,
            'finished_at'      => date('Y-m-d H:i:s'),
        ];

        if ($result !== null) {
            $update['result'] = array_merge($result, [
                'processed' => (int) ($result['processed'] ?? 0),
                'total'     => (int) ($result['total'] ?? 0),
            ]);
        }

        $this->jobs->update($jobId, $update);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                'cancelled',
                $message,
                array_merge($result ?? [], [
                    'job_id'     => $jobId,
                    'sheet_name' => $sheetName,
                ]),
            );
        }
    }

    private function formatScopeLabel(string $sheetName): string
    {
        return $sheetName === InventoryQuantitySyncService::ALL_SHEETS
            ? 'All sheets'
            : 'Sheet "' . $sheetName . '"';
    }

    /**
     * @param list<string> $errors
     */
    private function failJob(int $jobId, ?int $logId, string $message, array $errors = [], ?string $sheetName = null): void
    {
        $this->jobs->update($jobId, [
            'status'           => 'failed',
            'progress_message' => $message,
            'errors'           => $errors !== [] ? array_values($errors) : null,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryQtySyncLog(
                $logId,
                'failed',
                $message,
                [
                    'job_id'     => $jobId,
                    'sheet_name' => $sheetName,
                    'errors'     => $errors,
                ],
            );
        }
    }

    /**
     * @return array{queued: bool, spawned: bool, message: string, job_id?: int}
     */
    private function enqueueScope(string $sheetName, ?int $userId): array
    {
        $active = $this->jobs->getActive();

        if ($active !== null) {
            return [
                'queued'  => false,
                'spawned' => false,
                'message' => sprintf(
                    'A Net32 quantity sync is already active (job %d).',
                    (int) $active['id'],
                ),
                'job_id'  => (int) $active['id'],
            ];
        }

        $isAllSheets = $sheetName === InventoryQuantitySyncService::ALL_SHEETS;
        $progressMessage = $isAllSheets
            ? 'Net32 quantity sync queued for all sheets.'
            : sprintf('Net32 quantity sync queued for sheet "%s".', $sheetName);

        $jobId = (int) $this->jobs->insert([
            'user_id'          => $userId,
            'status'           => 'queued',
            'sheet_name'       => $sheetName,
            'progress_message' => $progressMessage,
        ]);

        $logId = service('activityLog')->logInventoryQtySyncQueued($jobId, $sheetName, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        $spawned = $this->spawnBackgroundJob($jobId);

        return [
            'queued'  => true,
            'spawned' => $spawned,
            'message' => $spawned
                ? ($isAllSheets
                    ? 'Net32 quantity sync started in the background for all sheets.'
                    : sprintf('Net32 quantity sync started in the background for sheet "%s".', $sheetName))
                : ($isAllSheets
                    ? 'Net32 quantity sync queued for all sheets. It will start within about a minute.'
                    : sprintf('Net32 quantity sync queued for sheet "%s". It will start within about a minute.', $sheetName)),
            'job_id'  => $jobId,
        ];
    }

    private function assertNoActiveJob(): void
    {
        $active = $this->jobs->getActive();

        if ($active !== null) {
            throw new \RuntimeException(sprintf(
                'A Net32 quantity sync is already active (job %d).',
                (int) $active['id'],
            ));
        }
    }

    private function spawnBackgroundJob(int $jobId): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $php     = PHP_BINARY ?: 'php';
        $spark   = ROOTPATH . 'spark';
        $logFile = WRITEPATH . 'logs/inventory-qty-sync.log';
        $cmd     = sprintf(
            'cd %s && nohup %s %s inventory:sync-qty-from-net32 --job-id %d >> %s 2>&1 &',
            escapeshellarg(ROOTPATH),
            escapeshellarg($php),
            escapeshellarg($spark),
            $jobId,
            escapeshellarg($logFile),
        );

        exec($cmd, $output, $code);

        return $code === 0;
    }

    private function recoverStaleRunningJobs(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - (self::STALE_RUNNING_MINUTES * 60));

        foreach ($this->jobs->findStaleRunning($cutoff) as $job) {
            $jobId     = (int) $job['id'];
            $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
            $sheetName = trim((string) ($job['sheet_name'] ?? ''));

            $this->failJob(
                $jobId,
                $logId,
                sprintf(
                    'Sheet "%s": Net32 quantity sync stopped or timed out.',
                    $sheetName !== '' ? $sheetName : '?',
                ),
                ['Quantity sync process stopped or timed out.'],
                $sheetName !== '' ? $sheetName : null,
            );
        }
    }

    private function recoverStuckCancellations(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::CANCEL_STALE_SECONDS);

        foreach ($this->jobs->findStaleRunning($cutoff) as $job) {
            $jobId = (int) $job['id'];

            if (! $this->isCancelRequested($jobId)) {
                continue;
            }

            $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
            $sheetName = trim((string) ($job['sheet_name'] ?? ''));

            $this->forceCancelRunningJob($jobId, $job, $logId, $sheetName);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonField(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}

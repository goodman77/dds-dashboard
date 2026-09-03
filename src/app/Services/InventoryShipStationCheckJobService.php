<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryShipStationCheckJobModel;

class InventoryShipStationCheckJobService
{
    private const STALE_RUNNING_MINUTES = 15;

    private const CANCEL_STALE_SECONDS = 60;

    public function __construct(
        private readonly InventoryShipStationCheckJobModel $jobs,
        private readonly InventoryShipStationCheckService $check,
    ) {
    }

    /**
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

    /**
     * @return array{queued: bool, spawned: bool, message: string, job_id?: int}
     */
    public function enqueueForAll(?int $userId = null): array
    {
        return $this->enqueueScope(InventoryShipStationCheckService::ALL_SHEETS, $userId);
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException(sprintf('ShipStation check job %d not found.', $jobId));
        }

        if (in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $sheetName = trim((string) ($job['sheet_name'] ?? ''));

        if ($sheetName === '') {
            $this->failJob($jobId, null, 'ShipStation check job is missing a sheet name.', ['Missing sheet name.']);

            throw new \RuntimeException('ShipStation check job is missing a sheet name.');
        }

        $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $isAllSheets = $sheetName === InventoryShipStationCheckService::ALL_SHEETS;
        $startMessage = $isAllSheets
            ? 'Syncing ShipStation locations for all sheets...'
            : sprintf('Syncing ShipStation locations for sheet "%s"...', $sheetName);

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'started_at'       => date('Y-m-d H:i:s'),
            'progress_message' => $startMessage,
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryShipStationCheckLog(
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
                ? $this->check->checkAll(null, false, $jobId)
                : $this->check->checkSheet($sheetName, null, false, $jobId);

            $this->completeRun($jobId, $logId, $sheetName, $result);
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf('%s: ShipStation location check failed: %s', $this->formatScopeLabel($sheetName), $exception->getMessage()),
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
                return ['ok' => false, 'message' => 'No active ShipStation location check job.'];
            }

            $jobId = (int) $active['id'];
        }

        $job = $this->jobs->find($jobId);

        if ($job === null) {
            return ['ok' => false, 'message' => sprintf('ShipStation check job %d not found.', $jobId)];
        }

        $status    = (string) $job['status'];
        $sheetName = trim((string) ($job['sheet_name'] ?? ''));

        if ($status === 'cancelled') {
            return [
                'ok'      => true,
                'message' => 'ShipStation location check already cancelled.',
                'status'  => 'cancelled',
                'job_id'  => $jobId,
            ];
        }

        if (! in_array($status, ['queued', 'running'], true)) {
            return ['ok' => false, 'message' => 'This ShipStation location check is not active.'];
        }

        if ($status === 'queued') {
            $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;

            $this->cancelJob(
                $jobId,
                $logId,
                sprintf('%s: ShipStation location check cancelled before it started.', $this->formatScopeLabel($sheetName)),
                null,
                $sheetName,
            );

            return [
                'ok'      => true,
                'message' => 'ShipStation location check cancelled.',
                'status'  => 'cancelled',
                'job_id'  => $jobId,
            ];
        }

        if ($this->isCancelRequested($jobId)) {
            $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;

            return $this->forceCancelRunningJob($jobId, $job, $logId, $sheetName);
        }

        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $progress['cancel_requested'] = true;

        $this->jobs->update($jobId, [
            'progress_message' => sprintf('%s: Cancellation requested...', $this->formatScopeLabel($sheetName)),
            'result'           => $progress,
        ]);

        $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $cancelMessage = sprintf('%s: Cancellation requested...', $this->formatScopeLabel($sheetName));

        if ($logId !== null) {
            service('activityLog')->updateInventoryShipStationCheckLog(
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
            'message'          => 'Stopping ShipStation location check...',
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

    /**
     * @param array<string, mixed> $progress
     */
    public function updateProgress(
        int $jobId,
        int $processed,
        int $total,
        string $sheetName,
        ?string $message = null,
        array $progress = [],
    ): void {
        $progressMessage = $message ?? sprintf('Checked %d of %d SKU(s)...', $processed, $total);
        $job             = $this->jobs->find($jobId);
        $existing        = $this->decodeJsonField($job['result'] ?? null) ?? [];

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'progress_message' => mb_substr($progressMessage, 0, 255),
            'result'           => array_merge($existing, [
                'processed'      => $processed,
                'total'          => $total,
                'sheet_name'     => $sheetName,
                'synced'         => $progress['synced'] ?? ($existing['synced'] ?? 0),
                'matched'        => $progress['matched'] ?? ($existing['matched'] ?? 0),
                'mismatched'     => $progress['mismatched'] ?? ($existing['mismatched'] ?? 0),
                'missing'        => $progress['missing'] ?? ($existing['missing'] ?? 0),
                'empty_location' => $progress['empty_location'] ?? ($existing['empty_location'] ?? 0),
                'errors'         => $progress['errors'] ?? ($existing['errors'] ?? []),
            ]),
        ]);

        $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;

        if ($logId !== null) {
            service('activityLog')->updateInventoryShipStationCheckLog(
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
        $job = $jobId !== null
            ? $this->jobs->find($jobId)
            : $this->jobs->orderBy('id', 'DESC')->first();

        if ($job === null) {
            return null;
        }

        $progress        = $this->decodeJsonField($job['result'] ?? null);
        $errors          = $this->decodeJsonField($job['errors'] ?? null);
        $processed       = (int) ($progress['processed'] ?? 0);
        $total           = (int) ($progress['total'] ?? 0);
        $isActive        = in_array($job['status'], ['queued', 'running'], true);
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
            'matched'          => (int) ($progress['matched'] ?? 0),
            'synced'           => (int) ($progress['synced'] ?? 0),
            'mismatched'       => (int) ($progress['mismatched'] ?? 0),
            'missing'          => (int) ($progress['missing'] ?? 0),
            'empty_location'   => (int) ($progress['empty_location'] ?? 0),
            'percent'          => $this->calculateProgressPercent($processed, $total),
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

        return max(1, min(99, (int) floor(($processed / $total) * 100)));
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
        $this->reconcileStuckJobsInternal();

        if ($this->jobs->getRunning() !== null) {
            $running = $this->jobs->getRunning();

            return [
                'processed' => false,
                'action'    => 'busy',
                'job_id'    => (int) $running['id'],
                'message'   => sprintf('ShipStation check job %d is already running.', (int) $running['id']),
            ];
        }

        $queued = $this->jobs->getOldestQueued();

        if ($queued === null) {
            return [
                'processed' => false,
                'action'    => 'idle',
                'message'   => 'No queued ShipStation location check jobs.',
            ];
        }

        $jobId = (int) $queued['id'];

        if ($this->spawnBackgroundJob($jobId)) {
            return [
                'processed' => true,
                'action'    => 'started',
                'job_id'    => $jobId,
                'message'   => sprintf('ShipStation check job %d started in the background.', $jobId),
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
            'message'   => sprintf('ShipStation check job %d finished.', $jobId),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function completeRun(int $jobId, ?int $logId, string $sheetName, array $result): void
    {
        if (! empty($result['cancelled'])) {
            $this->cancelJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: ShipStation location check cancelled after checking %d of %d SKU(s).',
                    $this->formatScopeLabel($sheetName),
                    (int) ($result['processed'] ?? 0),
                    (int) ($result['total'] ?? 0),
                ),
                $result,
                $sheetName,
            );

            return;
        }

        $status = ($result['total'] ?? 0) === 0 && ($result['errors'] ?? []) !== [] ? 'failed' : 'completed';

        $this->finishJob($jobId, $logId, $status, $result);
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

        $message = service('activityLog')->buildInventoryShipStationCheckMessage($result);

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
            service('activityLog')->updateInventoryShipStationCheckLog(
                $logId,
                $status,
                $message,
                array_merge($result, ['job_id' => $jobId]),
            );
        }
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private function cancelJob(
        int $jobId,
        ?int $logId,
        string $message,
        ?array $result = null,
        ?string $sheetName = null,
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
            service('activityLog')->updateInventoryShipStationCheckLog(
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

    /**
     * @return array{ok: bool, message: string, status?: string, job_id?: int}
     */
    private function forceCancelRunningJob(int $jobId, array $job, ?int $logId, string $sheetName): array
    {
        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $result   = [
            'sheet_name'     => $sheetName,
            'total'          => (int) ($progress['total'] ?? 0),
            'processed'      => (int) ($progress['processed'] ?? 0),
            'matched'        => (int) ($progress['matched'] ?? 0),
            'mismatched'     => (int) ($progress['mismatched'] ?? 0),
            'missing'        => (int) ($progress['missing'] ?? 0),
            'empty_location' => (int) ($progress['empty_location'] ?? 0),
            'errors'         => is_array($progress['errors'] ?? null) ? array_values($progress['errors']) : [],
            'cancelled'      => true,
        ];

        $this->cancelJob(
            $jobId,
            $logId,
            sprintf(
                '%s: ShipStation location check cancelled after checking %d of %d SKU(s).',
                $this->formatScopeLabel($sheetName),
                $result['processed'],
                max($result['total'], $result['processed']),
            ),
            $result,
            $sheetName,
        );

        return [
            'ok'      => true,
            'message' => 'ShipStation location check cancelled.',
            'status'  => 'cancelled',
            'job_id'  => $jobId,
        ];
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
            service('activityLog')->updateInventoryShipStationCheckLog(
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
                    'A ShipStation location check is already active (job %d).',
                    (int) $active['id'],
                ),
                'job_id'  => (int) $active['id'],
            ];
        }

        $isAllSheets = $sheetName === InventoryShipStationCheckService::ALL_SHEETS;
        $progressMessage = $isAllSheets
            ? 'ShipStation location sync queued for all sheets.'
            : sprintf('ShipStation location sync queued for sheet "%s".', $sheetName);

        $jobId = (int) $this->jobs->insert([
            'user_id'          => $userId,
            'status'           => 'queued',
            'sheet_name'       => $sheetName,
            'progress_message' => $progressMessage,
        ]);

        $logId = service('activityLog')->logInventoryShipStationCheckQueued($jobId, $sheetName, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        $spawned = $this->spawnBackgroundJob($jobId);

        return [
            'queued'  => true,
            'spawned' => $spawned,
            'message' => $spawned
                ? ($isAllSheets
                    ? 'ShipStation location sync started in the background for all sheets.'
                    : sprintf('ShipStation location sync started in the background for sheet "%s".', $sheetName))
                : ($isAllSheets
                    ? 'ShipStation location sync queued for all sheets. It will start within about a minute.'
                    : sprintf('ShipStation location sync queued for sheet "%s". It will start within about a minute.', $sheetName)),
            'job_id'  => $jobId,
        ];
    }

    private function spawnBackgroundJob(int $jobId): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $php     = PHP_BINARY ?: 'php';
        $spark   = ROOTPATH . 'spark';
        $logFile = WRITEPATH . 'logs/inventory-shipstation-check.log';
        $cmd     = sprintf(
            'cd %s && nohup %s %s inventory:check-shipstation-locations --job-id %d >> %s 2>&1 &',
            escapeshellarg(ROOTPATH),
            escapeshellarg($php),
            escapeshellarg($spark),
            $jobId,
            escapeshellarg($logFile),
        );

        exec($cmd, $output, $code);

        return $code === 0;
    }

    public function reconcileStuckJobs(): void
    {
        $this->reconcileStuckJobsInternal();
    }

    private function reconcileStuckJobsInternal(): void
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
                    'Sheet "%s": ShipStation location check stopped or timed out.',
                    $sheetName !== '' ? $sheetName : '?',
                ),
                ['ShipStation location check process stopped or timed out.'],
                $sheetName !== '' ? $sheetName : null,
            );
        }

        $cancelCutoff = date('Y-m-d H:i:s', time() - self::CANCEL_STALE_SECONDS);

        foreach ($this->jobs->findStaleRunning($cancelCutoff) as $job) {
            $jobId = (int) $job['id'];

            if ($this->isCancelRequested($jobId)) {
                $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
                $sheetName = trim((string) ($job['sheet_name'] ?? ''));

                $this->forceCancelRunningJob($jobId, $job, $logId, $sheetName);
            }
        }
    }

    private function formatScopeLabel(string $sheetName): string
    {
        return $sheetName === InventoryShipStationCheckService::ALL_SHEETS
            ? 'All sheets'
            : 'Sheet "' . $sheetName . '"';
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

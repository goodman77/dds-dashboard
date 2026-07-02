<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryImportJobModel;

class InventoryImportJobService
{
    private const STALE_RUNNING_MINUTES = 15;

    public function __construct(
        private readonly InventoryImportJobModel $jobs,
        private readonly InventoryImportService $import,
    ) {
    }

    /**
     * @return array{started: bool, message: string, job_id?: int}
     */
    public function dispatch(?string $sheetName, ?int $userId = null): array
    {
        $sheetName = $this->normalizeSheetName($sheetName);

        $active = $this->jobs->getActive();

        if ($active !== null) {
            return [
                'started' => false,
                'message' => 'An inventory import is already running.',
                'job_id'  => (int) $active['id'],
            ];
        }

        $jobId = (int) $this->jobs->insert([
            'user_id'          => $userId,
            'status'           => 'queued',
            'sheet_name'       => $sheetName,
            'progress_message' => $this->buildStartMessage($sheetName),
        ]);

        $logId = service('activityLog')->logInventoryImportStarted($jobId, $sheetName, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        $spawned = $this->spawnBackgroundJob($jobId);

        return [
            'started' => true,
            'message' => $spawned
                ? ($sheetName !== null
                    ? sprintf('Import started in the background for sheet "%s".', $sheetName)
                    : 'Import started in the background for all sheets.')
                : ($sheetName !== null
                    ? sprintf('Import queued for sheet "%s". It will start within about a minute.', $sheetName)
                    : 'Import queued for all sheets. It will start within about a minute.'),
            'job_id'  => $jobId,
        ];
    }

    /**
     * Pick up the oldest queued import job. Intended to be called from cron
     * (e.g. every minute on shared hosting where exec/nohup is unavailable).
     *
     * @return array{
     *     processed: bool,
     *     action: 'idle'|'busy'|'completed'|'failed',
     *     message: string,
     *     job_id?: int
     * }
     */
    public function processQueue(): array
    {
        $this->recoverStaleRunningJobs();

        $running = $this->jobs->getRunning();

        if ($running !== null) {
            return [
                'processed' => false,
                'action'    => 'busy',
                'job_id'    => (int) $running['id'],
                'message'   => sprintf('Import job %d is already running.', $running['id']),
            ];
        }

        $queued = $this->jobs->getOldestQueued();

        if ($queued === null) {
            return [
                'processed' => false,
                'action'    => 'idle',
                'message'   => 'No queued import jobs.',
            ];
        }

        $jobId = (int) $queued['id'];

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
            'message'   => sprintf('Import job %d finished.', $jobId),
        ];
    }

    private function recoverStaleRunningJobs(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - (self::STALE_RUNNING_MINUTES * 60));

        foreach ($this->jobs->findStaleRunning($cutoff) as $job) {
            $jobId     = (int) $job['id'];
            $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
            $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);

            $this->failJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: Import stopped or timed out. The server may have ended the process before it finished.',
                    service('activityLog')->formatImportSheetLabel($sheetName),
                ),
                ['Import process stopped or timed out.'],
                $sheetName,
            );
        }
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException(sprintf('Import job %d not found.', $jobId));
        }

        if (in_array($job['status'], ['completed', 'failed'], true)) {
            return;
        }

        $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'started_at'       => date('Y-m-d H:i:s'),
            'progress_message' => $this->buildRunningMessage($sheetName),
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryImportLog(
                $logId,
                'running',
                $this->buildRunningMessage($sheetName),
                [
                    'job_id'     => $jobId,
                    'sheet_name' => $sheetName,
                ],
            );
        }

        try {
            $result = $this->import->importFromGoogleSheets(
                $sheetName,
                null,
                false,
                false,
                false,
                $jobId,
            );

            $status = ($result['scanned'] ?? 0) === 0 && ($result['sheets'] ?? 0) === 0 && ($result['errors'] ?? []) !== []
                ? 'failed'
                : 'completed';

            $finalTotal = (int) ($result['scanned'] ?? 0);

            if ($finalTotal > 0) {
                $this->updateProgress(
                    $jobId,
                    $finalTotal,
                    $finalTotal,
                    $sheetName,
                    'Import finished.',
                );
            }

            $this->jobs->update($jobId, [
                'status'           => $status,
                'progress_message' => service('activityLog')->buildInventoryImportMessage(
                    array_merge($result, ['sheet_name' => $sheetName]),
                    $sheetName,
                ),
                'result'           => array_merge($result, [
                    'total'   => $finalTotal,
                    'scanned' => $finalTotal,
                ]),
                'errors'           => ($result['errors'] ?? []) !== [] ? array_values($result['errors']) : null,
                'finished_at'      => date('Y-m-d H:i:s'),
            ]);

            if ($logId !== null) {
                service('activityLog')->updateInventoryImportLog(
                    $logId,
                    $status,
                    service('activityLog')->buildInventoryImportMessage(
                        array_merge($result, ['sheet_name' => $sheetName]),
                        $sheetName,
                    ),
                    array_merge($result, [
                        'job_id'     => $jobId,
                        'sheet_name' => $sheetName,
                    ]),
                );
            }

            service('activityLog')->reconcileStaleImportLogs();
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: Inventory import failed: %s',
                    service('activityLog')->formatImportSheetLabel($sheetName),
                    $exception->getMessage(),
                ),
                [$exception->getMessage()],
                $sheetName,
            );

            throw $exception;
        }
    }

    private function failJob(int $jobId, ?int $logId, string $message, array $errors = [], ?string $sheetName = null): void
    {
        $this->jobs->update($jobId, [
            'status'           => 'failed',
            'progress_message' => $message,
            'errors'           => $errors !== [] ? array_values($errors) : null,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($logId !== null) {
            service('activityLog')->updateInventoryImportLog(
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

        service('activityLog')->reconcileStaleImportLogs();
    }

    private function spawnBackgroundJob(int $jobId): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $php     = PHP_BINARY ?: 'php';
        $spark   = ROOTPATH . 'spark';
        $logFile = WRITEPATH . 'logs/inventory-import.log';
        $cmd     = sprintf(
            'cd %s && nohup %s %s inventory:import-from-sheets --job-id %d >> %s 2>&1 &',
            escapeshellarg(ROOTPATH),
            escapeshellarg($php),
            escapeshellarg($spark),
            $jobId,
            escapeshellarg($logFile),
        );

        exec($cmd, $output, $code);

        return $code === 0;
    }

    private function normalizeSheetName(?string $sheetName): ?string
    {
        $sheetName = trim((string) $sheetName);

        return $sheetName === '' ? null : $sheetName;
    }

    private function buildStartMessage(?string $sheetName): string
    {
        return sprintf(
            'Inventory import queued (%s).',
            service('activityLog')->formatImportSheetLabel($sheetName),
        );
    }

    private function buildRunningMessage(?string $sheetName): string
    {
        return sprintf(
            'Importing inventory (%s)...',
            service('activityLog')->formatImportSheetLabel($sheetName),
        );
    }

    public function updateProgress(
        int $jobId,
        int $scanned,
        int $total,
        ?string $currentSheet = null,
        ?string $message = null,
    ): void {
        $progressMessage = $message ?? sprintf('Processing SKU %d of %d...', $scanned, $total);

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'progress_message' => mb_substr($progressMessage, 0, 255),
            'result'           => [
                'scanned'       => $scanned,
                'total'         => $total,
                'current_sheet' => $currentSheet,
            ],
        ]);
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
        [$scanned, $total] = $this->resolveProgressCounters($progress, (string) $job['status']);
        $isActive = in_array($job['status'], ['queued', 'running'], true);
        $percent  = $this->calculateProgressPercent($scanned, $total);

        return [
            'job_id'             => (int) $job['id'],
            'status'             => (string) $job['status'],
            'is_active'          => $isActive,
            'sheet_name'         => $job['sheet_name'] ?? null,
            'progress_message'   => (string) ($job['progress_message'] ?? ''),
            'scanned'            => $scanned,
            'total'              => $total,
            'imported'           => (int) ($progress['imported'] ?? 0),
            'percent'            => $percent,
            'remaining_percent'  => $total > 0 ? max(0, 100 - $percent) : null,
            'started_at'         => $job['started_at'] ?? null,
            'finished_at'        => $job['finished_at'] ?? null,
            'errors'             => is_array($errors) ? array_values($errors) : [],
            'discovered_sheets'  => is_array($progress['discovered_sheets'] ?? null)
                ? array_values($progress['discovered_sheets'])
                : [],
        ];
    }

    public function calculateProgressPercent(int $scanned, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        if ($scanned >= $total) {
            return 100;
        }

        if ($scanned <= 0) {
            return 0;
        }

        $percent = (int) floor(($scanned / $total) * 100);

        return max(1, min(99, $percent));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveProgressCounters(?array $progress, string $jobStatus): array
    {
        if ($progress === null) {
            return [0, 0];
        }

        $scanned = (int) ($progress['scanned'] ?? 0);
        $total   = (int) ($progress['total'] ?? 0);

        if ($total <= 0 && $scanned > 0 && ! array_key_exists('current_sheet', $progress)) {
            $total = $scanned;
        }

        if ($jobStatus === 'completed' && $total > 0 && ! array_key_exists('current_sheet', $progress)) {
            $scanned = $total;
        }

        return [$scanned, $total];
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

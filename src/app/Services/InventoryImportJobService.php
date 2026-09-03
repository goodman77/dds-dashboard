<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryImportJobModel;

class InventoryImportJobService
{
    private const STALE_RUNNING_MINUTES = 15;

    private const STALE_PIPELINE_RUNNING_MINUTES = 120;

    private const ACTIVE_WORKER_SECONDS = 180;

    private const CANCEL_STALE_SECONDS = 60;

    public const MODE_IMPORT = 'import';

    public const MODE_RECONCILE = 'reconcile';

    public function __construct(
        private readonly InventoryImportJobModel $jobs,
        private readonly InventoryImportService $import,
    ) {
    }

    /**
     * @return array{started: bool, message: string, job_id?: int}
     */
    public function dispatch(
        ?string $sheetName,
        ?int $userId = null,
        string $mode = self::MODE_RECONCILE,
        bool $withNet32 = false,
        bool $withShipStation = false,
    ): array {
        $sheetName = $this->normalizeSheetName($sheetName);
        $mode      = $this->normalizeMode($mode);
        $withNet32 = $mode === self::MODE_RECONCILE && $withNet32;
        $withShipStation = $mode === self::MODE_RECONCILE && $withShipStation;

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
            'import_mode'      => $mode,
            'progress_message' => $this->buildStartMessage($sheetName, $mode, $withNet32, $withShipStation),
            'result'           => [
                'with_net32'        => $withNet32,
                'with_shipstation'  => $withShipStation,
                'pipeline_phase'    => 'queued',
            ],
        ]);

        $logId = $mode === self::MODE_RECONCILE
            ? service('activityLog')->logInventoryReconcileStarted($jobId, $sheetName, $userId)
            : service('activityLog')->logInventoryImportStarted($jobId, $sheetName, $userId);

        $this->jobs->update($jobId, [
            'activity_log_id' => $logId,
        ]);

        $spawned = $this->spawnBackgroundJob($jobId);

        return [
            'started' => true,
            'message' => $spawned
                ? $this->buildDispatchMessage($sheetName, $mode, true, $withNet32, $withShipStation)
                : $this->buildDispatchMessage($sheetName, $mode, false, $withNet32, $withShipStation),
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
        $this->reconcileStuckJobs();

        $running = $this->jobs->getRunning();

        if ($running !== null) {
            if ($this->isActiveWorker($running)) {
                return [
                    'processed' => false,
                    'action'    => 'busy',
                    'job_id'    => (int) $running['id'],
                    'message'   => sprintf('Import job %d is already running.', $running['id']),
                ];
            }

            $jobId = (int) $running['id'];

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
                'message'   => sprintf('Import job %d resumed and finished.', $jobId),
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

    public function reconcileStuckJobs(): void
    {
        $this->recoverStaleRunningJobs();
        $this->recoverStuckCancellations();
    }

    private function recoverStaleRunningJobs(): void
    {
        foreach ($this->jobs->where('status', 'running')->findAll() as $job) {
            if ($this->isActiveWorker($job)) {
                continue;
            }

            $jobMeta = $this->decodeJsonField($job['result'] ?? null) ?? [];
            $staleMinutes = $this->resolveStaleRunningMinutes($jobMeta);

            if (! $this->isUpdatedBeforeMinutes($job, $staleMinutes)) {
                continue;
            }

            $jobId     = (int) $job['id'];
            $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
            $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);
            $mode      = $this->normalizeMode((string) ($job['import_mode'] ?? self::MODE_RECONCILE));

            if (
                $mode === self::MODE_RECONCILE
                && (
                    ! empty($jobMeta['reconcile_completed'])
                    || ! empty($jobMeta['with_net32'])
                    || ! empty($jobMeta['with_shipstation'])
                )
            ) {
                continue;
            }

            $this->failJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: Import stopped or timed out. The server may have ended the process before it finished.',
                    service('activityLog')->formatImportSheetLabel($sheetName),
                ),
                ['Import process stopped or timed out.'],
                $sheetName,
                $mode,
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
            $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);

            $this->forceCancelRunningJob(
                $jobId,
                $job,
                $logId,
                $sheetName,
                $this->normalizeMode((string) ($job['import_mode'] ?? self::MODE_RECONCILE)),
            );
        }
    }

    public function run(int $jobId): void
    {
        @set_time_limit(0);

        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new \RuntimeException(sprintf('Import job %d not found.', $jobId));
        }

        if (in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        if ((string) ($job['status'] ?? '') === 'running' && $this->isActiveWorker($job)) {
            return;
        }

        $logId = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);
        $mode      = $this->normalizeMode((string) ($job['import_mode'] ?? self::MODE_RECONCILE));
        $jobMeta   = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $withNet32 = ! empty($jobMeta['with_net32']);
        $withShipStation = ! empty($jobMeta['with_shipstation']);
        $isResume  = (string) ($job['status'] ?? '') === 'running';

        $statusUpdate = [
            'status'           => 'running',
            'progress_message' => $isResume
                ? $this->buildResumeMessage($sheetName, $mode, $jobMeta)
                : $this->buildRunningMessage($sheetName, $mode),
        ];

        if (! $isResume || empty($job['started_at'])) {
            $statusUpdate['started_at'] = date('Y-m-d H:i:s');
        }

        $this->jobs->update($jobId, $statusUpdate);

        if ($logId !== null) {
            if ($mode === self::MODE_RECONCILE) {
                service('activityLog')->updateInventoryReconcileLog(
                    $logId,
                    'running',
                    $this->buildRunningMessage($sheetName, $mode),
                    [
                        'job_id'      => $jobId,
                        'sheet_name'  => $sheetName,
                        'import_mode' => $mode,
                    ],
                );
            } else {
                service('activityLog')->updateInventoryImportLog(
                    $logId,
                    'running',
                    $this->buildRunningMessage($sheetName, $mode),
                    [
                        'job_id'      => $jobId,
                        'sheet_name'  => $sheetName,
                        'import_mode' => $mode,
                    ],
                );
            }
        }

        try {
            if ($mode === self::MODE_RECONCILE && ! empty($jobMeta['reconcile_completed'])) {
                $result = $this->extractStoredReconcileResult($jobMeta);
            } elseif ($mode === self::MODE_RECONCILE) {
                $result = service('inventoryReconcile')->reconcileFromGoogleSheets(
                    $sheetName,
                    null,
                    false,
                    false,
                    false,
                    $jobId,
                );
            } else {
                $result = $this->import->importFromGoogleSheets(
                    $sheetName,
                    null,
                    false,
                    false,
                    false,
                    $jobId,
                );
            }

            if (! empty($result['cancelled'])) {
                $this->cancelJob(
                    $jobId,
                    $logId,
                    sprintf(
                        '%s: %s cancelled by user after processing %d of %d SKU(s).',
                        service('activityLog')->formatImportSheetLabel($sheetName),
                        $mode === self::MODE_RECONCILE ? 'Reconcile' : 'Import',
                        (int) ($result['scanned'] ?? 0),
                        (int) ($result['total'] ?? $result['scanned'] ?? 0),
                    ),
                    $sheetName,
                    array_merge($result, [
                        'total'       => (int) ($result['scanned'] ?? 0),
                        'scanned'     => (int) ($result['scanned'] ?? 0),
                        'import_mode' => $mode,
                    ]),
                    $mode,
                );

                return;
            }

            $existing = $this->jobs->find($jobId);

            if ($existing !== null && (string) ($existing['status'] ?? '') === 'cancelled') {
                return;
            }

            $status = ($result['scanned'] ?? 0) === 0 && ($result['sheets'] ?? 0) === 0 && ($result['errors'] ?? []) !== []
                ? 'failed'
                : 'completed';

            $finalTotal = (int) ($result['scanned'] ?? 0);
            $combinedResult = array_merge($result, [
                'total'            => $finalTotal,
                'scanned'          => $finalTotal,
                'import_mode'      => $mode,
                'with_net32'       => $withNet32,
                'with_shipstation' => $withShipStation,
                'pipeline_phase'   => $mode === self::MODE_RECONCILE ? 'reconcile' : 'import',
                'reconcile_completed' => true,
            ]);

            $this->jobs->update($jobId, [
                'result' => array_merge(
                    $this->decodeJsonField($this->jobs->find($jobId)['result'] ?? null) ?? [],
                    $combinedResult,
                ),
            ]);

            if ($mode === self::MODE_RECONCILE && $status !== 'failed') {
                if ($withNet32 && ! $this->isCancelRequested($jobId) && empty($jobMeta['net32_completed'])) {
                    $combinedResult['net32'] = $this->runNet32PipelinePhase($jobId, $sheetName);
                    $combinedResult['net32_completed'] = empty($combinedResult['net32']['cancelled']);
                } elseif (! empty($jobMeta['net32'])) {
                    $combinedResult['net32'] = is_array($jobMeta['net32']) ? $jobMeta['net32'] : (array) $jobMeta['net32'];
                    $combinedResult['net32_completed'] = ! empty($jobMeta['net32_completed']);
                }

                if (! empty($combinedResult['net32']['cancelled'])) {
                    $this->cancelJob(
                        $jobId,
                        $logId,
                        sprintf(
                            '%s: Pipeline cancelled during Net32 quantity sync.',
                            service('activityLog')->formatImportSheetLabel($sheetName),
                        ),
                        $sheetName,
                        $combinedResult,
                        $mode,
                    );

                    return;
                }

                if (! empty($combinedResult['net32_completed'])) {
                    $this->jobs->update($jobId, [
                        'result' => array_merge(
                            $this->decodeJsonField($this->jobs->find($jobId)['result'] ?? null) ?? [],
                            $combinedResult,
                        ),
                    ]);
                }

                if (
                    $withShipStation
                    && ! $this->isCancelRequested($jobId)
                    && empty($jobMeta['shipstation_completed'])
                    && (! $withNet32 || ! empty($combinedResult['net32_completed']))
                ) {
                    $combinedResult['shipstation'] = $this->runShipStationPipelinePhase($jobId, $sheetName);
                    $combinedResult['shipstation_completed'] = empty($combinedResult['shipstation']['cancelled']);

                    if (! empty($combinedResult['shipstation']['cancelled'])) {
                        $this->cancelJob(
                            $jobId,
                            $logId,
                            sprintf(
                                '%s: Pipeline cancelled during ShipStation location sync.',
                                service('activityLog')->formatImportSheetLabel($sheetName),
                            ),
                            $sheetName,
                            $combinedResult,
                            $mode,
                        );

                        return;
                    }
                } elseif (! empty($jobMeta['shipstation'])) {
                    $combinedResult['shipstation'] = is_array($jobMeta['shipstation'])
                        ? $jobMeta['shipstation']
                        : (array) $jobMeta['shipstation'];
                    $combinedResult['shipstation_completed'] = ! empty($jobMeta['shipstation_completed']);
                }
            }

            $allErrors = array_values($result['errors'] ?? []);

            if (! empty($combinedResult['net32']['errors'])) {
                $allErrors = array_merge($allErrors, $combinedResult['net32']['errors']);
            }

            if (! empty($combinedResult['shipstation']['errors'])) {
                $allErrors = array_merge($allErrors, $combinedResult['shipstation']['errors']);
            }

            $combinedResult['errors'] = $allErrors;

            if (
                $status !== 'failed'
                && (
                    (! empty($combinedResult['net32']['errors']))
                    || (! empty($combinedResult['shipstation']['errors']))
                )
                && ($combinedResult['scanned'] ?? 0) === 0
            ) {
                $status = 'failed';
            }

            $finishMessage = $this->buildFinishMessage($combinedResult, $sheetName, $mode);
            $combinedResult['pipeline_phase'] = 'completed';

            if ($finalTotal > 0 || $withNet32 || $withShipStation) {
                $this->updateProgress(
                    $jobId,
                    $finalTotal,
                    max($finalTotal, 1),
                    $sheetName,
                    $finishMessage,
                );
            }

            $this->jobs->update($jobId, [
                'status'           => $status,
                'progress_message' => $finishMessage,
                'result'           => $combinedResult,
                'errors'           => ($combinedResult['errors'] ?? []) !== [] ? array_values($combinedResult['errors']) : null,
                'finished_at'      => date('Y-m-d H:i:s'),
            ]);

            if ($logId !== null) {
                $this->updateJobLog(
                    $logId,
                    $status,
                    $finishMessage,
                    array_merge($combinedResult, [
                        'job_id'      => $jobId,
                        'sheet_name'  => $sheetName,
                        'import_mode' => $mode,
                    ]),
                    $mode,
                );
            }

            service('activityLog')->reconcileStaleImportLogs();
        } catch (\Throwable $exception) {
            $this->failJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: %s failed: %s',
                    service('activityLog')->formatImportSheetLabel($sheetName),
                    $mode === self::MODE_RECONCILE ? 'Sheet reconcile' : 'Inventory import',
                    $exception->getMessage(),
                ),
                [$exception->getMessage()],
                $sheetName,
                $mode,
            );

            throw $exception;
        }
    }

    private function failJob(
        int $jobId,
        ?int $logId,
        string $message,
        array $errors = [],
        ?string $sheetName = null,
        string $mode = self::MODE_IMPORT,
    ): void {
        $this->jobs->update($jobId, [
            'status'           => 'failed',
            'progress_message' => $message,
            'errors'           => $errors !== [] ? array_values($errors) : null,
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($logId !== null) {
            $this->updateJobLog(
                $logId,
                'failed',
                $message,
                [
                    'job_id'      => $jobId,
                    'sheet_name'  => $sheetName,
                    'errors'      => $errors,
                    'import_mode' => $mode,
                ],
                $mode,
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

        if ($sheetName === '' || $sheetName === '*') {
            return null;
        }

        return $sheetName;
    }

    private function buildStartMessage(
        ?string $sheetName,
        string $mode = self::MODE_IMPORT,
        bool $withNet32 = false,
        bool $withShipStation = false,
    ): string {
        $label = $mode === self::MODE_RECONCILE ? 'Sheet reconcile' : 'Inventory import';
        $scope = service('activityLog')->formatImportSheetLabel($sheetName);
        $extras = $this->formatPipelineExtras($withNet32, $withShipStation);

        return sprintf(
            '%s queued (%s)%s.',
            $label,
            $scope,
            $extras,
        );
    }

    private function buildRunningMessage(?string $sheetName, string $mode = self::MODE_IMPORT): string
    {
        $label = $mode === self::MODE_RECONCILE ? 'Reconciling inventory' : 'Importing inventory';

        return sprintf(
            '%s (%s)...',
            $label,
            service('activityLog')->formatImportSheetLabel($sheetName),
        );
    }

    private function buildDispatchMessage(
        ?string $sheetName,
        string $mode,
        bool $spawned,
        bool $withNet32 = false,
        bool $withShipStation = false,
    ): string {
        $scope = service('activityLog')->formatImportSheetLabel($sheetName);
        $verb  = $mode === self::MODE_RECONCILE ? 'Reconcile' : 'Import';
        $extras = $this->formatPipelineExtras($withNet32, $withShipStation);

        if ($spawned) {
            return sprintf('%s started in the background for %s%s.', $verb, lcfirst($scope), $extras);
        }

        return sprintf('%s queued for %s%s. It will start within about a minute.', $verb, lcfirst($scope), $extras);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function buildFinishMessage(array $result, ?string $sheetName, string $mode): string
    {
        $payload = array_merge($result, ['sheet_name' => $sheetName]);

        $message = $mode === self::MODE_RECONCILE
            ? service('activityLog')->buildInventoryReconcileMessage($payload)
            : service('activityLog')->buildInventoryImportMessage($payload, $sheetName);

        if (! empty($result['net32'])) {
            $message .= ' ' . service('activityLog')->buildInventoryQtySyncMessage($result['net32']);
        }

        if (! empty($result['shipstation'])) {
            $message .= ' ' . service('activityLog')->buildInventoryShipStationCheckMessage($result['shipstation']);
        }

        return $message;
    }

    private function formatPipelineExtras(bool $withNet32, bool $withShipStation): string
    {
        if (! $withNet32 && ! $withShipStation) {
            return '';
        }

        $parts = [];

        if ($withNet32) {
            $parts[] = 'Net32 quantities';
        }

        if ($withShipStation) {
            $parts[] = 'ShipStation locations';
        }

        return ' + ' . implode(' + ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function runNet32PipelinePhase(int $jobId, ?string $sheetName): array
    {
        $scopeLabel = service('activityLog')->formatImportSheetLabel($sheetName);
        $this->updatePipelineProgress(
            $jobId,
            'net32',
            0,
            0,
            sprintf('%s: Syncing Net32 quantities...', $scopeLabel),
        );

        $result = $sheetName === null
            ? service('inventoryQtySync')->syncAllFromNet32(null, false, null, null, $jobId)
            : service('inventoryQtySync')->syncSheetFromNet32($sheetName, null, false, null, null, $jobId);

        $status = ($result['errors'] ?? []) !== [] && ($result['total'] ?? 0) === 0 ? 'failed' : 'completed';
        service('activityLog')->logInventoryQtySyncResult($result, $status);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runShipStationPipelinePhase(int $jobId, ?string $sheetName): array
    {
        $scopeLabel = service('activityLog')->formatImportSheetLabel($sheetName);
        $this->updatePipelineProgress(
            $jobId,
            'shipstation',
            0,
            0,
            sprintf('%s: Syncing ShipStation locations...', $scopeLabel),
        );

        $result = $sheetName === null
            ? service('inventoryShipStationCheck')->checkAll(null, false, null, $jobId)
            : service('inventoryShipStationCheck')->checkSheet($sheetName, null, false, null, $jobId);

        $status = ($result['errors'] ?? []) !== [] && ($result['total'] ?? 0) === 0 ? 'failed' : 'completed';
        service('activityLog')->logInventoryShipStationCheckResult($result, $status);

        return $result;
    }

    public function updatePipelineProgress(
        int $jobId,
        string $phase,
        int $processed,
        int $total,
        string $message,
        array $stats = [],
    ): void {
        $job = $this->jobs->find($jobId);
        $existing = $this->decodeJsonField($job['result'] ?? null) ?? [];

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'progress_message' => mb_substr($message, 0, 255),
            'result'           => array_merge($existing, [
                'pipeline_phase'     => $phase,
                'pipeline_processed' => $processed,
                'pipeline_total'     => $total,
            ], $stats),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function updateJobLog(int $logId, string $status, string $message, array $details, string $mode): void
    {
        if ($mode === self::MODE_RECONCILE) {
            service('activityLog')->updateInventoryReconcileLog($logId, $status, $message, $details);

            return;
        }

        service('activityLog')->updateInventoryImportLog($logId, $status, $message, $details);
    }

    public function updateProgress(
        int $jobId,
        int $scanned,
        int $total,
        ?string $currentSheet = null,
        ?string $message = null,
        array $stats = [],
    ): void {
        $progressMessage = $message ?? sprintf('Processing SKU %d of %d...', $scanned, $total);

        $job = $this->jobs->find($jobId);
        $existing = $this->decodeJsonField($job['result'] ?? null) ?? [];

        $this->jobs->update($jobId, [
            'status'           => 'running',
            'progress_message' => mb_substr($progressMessage, 0, 255),
            'result'           => array_merge($existing, [
                'scanned'       => $scanned,
                'total'         => $total,
                'current_sheet' => $currentSheet,
            ], $stats),
        ]);
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === self::MODE_IMPORT ? self::MODE_IMPORT : self::MODE_RECONCILE;
    }

    /**
     * @return array{ok: bool, message: string, status?: string, cancel_requested?: bool}
     */
    public function requestCancel(int $jobId): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            return ['ok' => false, 'message' => 'Import job not found.'];
        }

        $status = (string) $job['status'];

        if ($status === 'cancelled') {
            return ['ok' => true, 'message' => 'Import already cancelled.', 'status' => 'cancelled'];
        }

        if (! in_array($status, ['queued', 'running'], true)) {
            return ['ok' => false, 'message' => 'This import is not active.'];
        }

        $logId     = isset($job['activity_log_id']) ? (int) $job['activity_log_id'] : null;
        $sheetName = $this->normalizeSheetName($job['sheet_name'] ?? null);
        $mode      = $this->normalizeMode((string) ($job['import_mode'] ?? self::MODE_RECONCILE));
        $jobLabel  = $mode === self::MODE_RECONCILE ? 'Reconcile' : 'Import';

        if ($status === 'queued') {
            $this->cancelJob(
                $jobId,
                $logId,
                sprintf(
                    '%s: %s cancelled before it started.',
                    service('activityLog')->formatImportSheetLabel($sheetName),
                    $jobLabel,
                ),
                $sheetName,
                null,
                $mode,
            );

            return ['ok' => true, 'message' => $jobLabel . ' cancelled.', 'status' => 'cancelled'];
        }

        if ($this->isCancelRequested($jobId)) {
            return $this->forceCancelRunningJob($jobId, $job, $logId, $sheetName, $mode);
        }

        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $progress['cancel_requested'] = true;
        $cancelMessage = sprintf(
            '%s: Cancellation requested...',
            service('activityLog')->formatImportSheetLabel($sheetName),
        );

        $this->jobs->update($jobId, [
            'progress_message' => $cancelMessage,
            'result'           => $progress,
        ]);

        if ($logId !== null) {
            $this->updateJobLog(
                $logId,
                'running',
                $cancelMessage,
                [
                    'job_id'           => $jobId,
                    'sheet_name'       => $sheetName,
                    'cancel_requested' => true,
                    'import_mode'      => $mode,
                ],
                $mode,
            );
        }

        return [
            'ok'               => true,
            'message'          => 'Stopping ' . strtolower($jobLabel) . '...',
            'status'           => 'running',
            'cancel_requested' => true,
            'job_id'           => $jobId,
        ];
    }

    /**
     * @return array{ok: bool, message: string, status?: string, cancel_requested?: bool, job_id?: int}
     */
    private function forceCancelRunningJob(int $jobId, array $job, ?int $logId, ?string $sheetName, string $mode = self::MODE_IMPORT): array
    {
        $progress = $this->decodeJsonField($job['result'] ?? null) ?? [];
        $scanned  = (int) ($progress['scanned'] ?? 0);
        $total    = (int) ($progress['total'] ?? $scanned);
        $result   = array_merge($progress, [
            'scanned'     => $scanned,
            'total'       => max($total, $scanned),
            'cancelled'   => true,
            'import_mode' => $mode,
        ]);
        $jobLabel = $mode === self::MODE_RECONCILE ? 'Reconcile' : 'Import';

        $this->cancelJob(
            $jobId,
            $logId,
            sprintf(
                '%s: %s cancelled after processing %d of %d SKU(s).',
                service('activityLog')->formatImportSheetLabel($sheetName),
                $jobLabel,
                $scanned,
                max($total, $scanned),
            ),
            $sheetName,
            $result,
            $mode,
        );

        return [
            'ok'      => true,
            'message' => $jobLabel . ' cancelled.',
            'status'  => 'cancelled',
            'job_id'  => $jobId,
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
     * @param array<string, mixed>|null $result
     */
    private function cancelJob(
        int $jobId,
        ?int $logId,
        string $message,
        ?string $sheetName,
        ?array $result = null,
        string $mode = self::MODE_IMPORT,
    ): void {
        $update = [
            'status'           => 'cancelled',
            'progress_message' => $message,
            'finished_at'      => date('Y-m-d H:i:s'),
        ];

        if ($result !== null) {
            $update['result'] = $result;
        }

        $this->jobs->update($jobId, $update);

        if ($logId !== null) {
            $this->updateJobLog(
                $logId,
                'cancelled',
                $message,
                array_merge(is_array($result) ? $result : [], [
                    'job_id'      => $jobId,
                    'sheet_name'  => $sheetName,
                    'import_mode' => $mode,
                ]),
                $mode,
            );
        }

        service('activityLog')->reconcileStaleImportLogs();
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
        $cancelRequested = ! empty($progress['cancel_requested']);

        return [
            'job_id'             => (int) $job['id'],
            'status'             => (string) $job['status'],
            'is_active'          => $isActive,
            'cancel_requested'   => $cancelRequested,
            'can_cancel'         => $isActive && ! $cancelRequested,
            'sheet_name'         => $job['sheet_name'] ?? null,
            'import_mode'        => $this->normalizeMode((string) ($job['import_mode'] ?? self::MODE_RECONCILE)),
            'with_net32'         => ! empty($progress['with_net32']),
            'with_shipstation'   => ! empty($progress['with_shipstation']),
            'pipeline_phase'     => (string) ($progress['pipeline_phase'] ?? ''),
            'progress_message'   => (string) ($job['progress_message'] ?? ''),
            'scanned'            => $scanned,
            'total'              => $total,
            'imported'           => (int) ($progress['imported'] ?? 0),
            'added'              => (int) ($progress['added'] ?? 0),
            'updated'            => (int) ($progress['updated'] ?? 0),
            'removed'            => (int) ($progress['removed'] ?? 0),
            'unchanged'          => (int) ($progress['unchanged'] ?? 0),
            'ignored'            => (int) ($progress['ignored'] ?? 0),
            'net32_missing'      => (int) ($progress['net32_missing'] ?? ($progress['net32']['missing'] ?? 0)),
            'net32_updated'      => (int) ($progress['net32_updated'] ?? ($progress['net32']['updated'] ?? 0)),
            'shipstation_synced' => (int) ($progress['shipstation_synced'] ?? ($progress['shipstation']['synced'] ?? 0)),
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

        $pipelinePhase = (string) ($progress['pipeline_phase'] ?? '');
        $pipelineTotal = (int) ($progress['pipeline_total'] ?? 0);

        if (
            in_array($pipelinePhase, ['net32', 'shipstation'], true)
            && $pipelineTotal > 0
        ) {
            return [
                (int) ($progress['pipeline_processed'] ?? 0),
                $pipelineTotal,
            ];
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
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            if ($value instanceof \stdClass) {
                $value = (array) $value;
            } else {
                return null;
            }
        }

        return $this->normalizeJsonArray($value);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeJsonArray(array $data): array
    {
        foreach ($data as $key => $item) {
            if ($item instanceof \stdClass) {
                $data[$key] = $this->normalizeJsonArray((array) $item);
            } elseif (is_array($item)) {
                $data[$key] = $this->normalizeJsonArray($item);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function isActiveWorker(array $job): bool
    {
        if ((string) ($job['status'] ?? '') !== 'running') {
            return false;
        }

        $updatedAt = strtotime((string) ($job['updated_at'] ?? ''));

        return $updatedAt !== false && (time() - $updatedAt) < self::ACTIVE_WORKER_SECONDS;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function isUpdatedBeforeMinutes(array $job, int $minutes): bool
    {
        $updatedAt = strtotime((string) ($job['updated_at'] ?? ''));

        if ($updatedAt === false) {
            return true;
        }

        return $updatedAt < (time() - ($minutes * 60));
    }

    /**
     * @param array<string, mixed> $jobMeta
     */
    private function resolveStaleRunningMinutes(array $jobMeta): int
    {
        if (! empty($jobMeta['with_net32']) || ! empty($jobMeta['with_shipstation'])) {
            return self::STALE_PIPELINE_RUNNING_MINUTES;
        }

        return self::STALE_RUNNING_MINUTES;
    }

    /**
     * @param array<string, mixed> $jobMeta
     */
    private function buildResumeMessage(?string $sheetName, string $mode, array $jobMeta): string
    {
        $scope = service('activityLog')->formatImportSheetLabel($sheetName);
        $phase = (string) ($jobMeta['pipeline_phase'] ?? '');

        if ($phase === 'shipstation' || ! empty($jobMeta['net32_completed'])) {
            return sprintf('%s: Resuming ShipStation location sync...', $scope);
        }

        if ($phase === 'net32' || ! empty($jobMeta['reconcile_completed'])) {
            return sprintf('%s: Resuming Net32 quantity sync...', $scope);
        }

        return $mode === self::MODE_RECONCILE
            ? sprintf('%s: Resuming sheet reconcile...', $scope)
            : sprintf('%s: Resuming inventory import...', $scope);
    }

    /**
     * @param array<string, mixed> $jobMeta
     *
     * @return array<string, mixed>
     */
    private function extractStoredReconcileResult(array $jobMeta): array
    {
        $keys = [
            'sheet_name',
            'sheets',
            'scanned',
            'added',
            'updated',
            'removed',
            'unchanged',
            'ignored',
            'errors',
            'discovered_sheets',
            'sheet_names',
        ];

        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $jobMeta)) {
                $result[$key] = $jobMeta[$key];
            }
        }

        return $result;
    }
}

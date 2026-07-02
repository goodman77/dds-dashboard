<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLogModel;

class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogModel $logs,
    ) {
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function log(
        string $action,
        string $status,
        string $message,
        ?array $details = null,
        ?int $userId = null,
        ?int $referenceId = null,
    ): int {
        if ($userId === null && function_exists('auth') && auth()->loggedIn()) {
            $userId = (int) auth()->id();
        }

        return $this->logs->record($action, $status, $message, $details, $userId, $referenceId);
    }

    public function logSheetsSync(array $result, string $status = 'completed'): void
    {
        $this->logInventoryImport($result, $status);
    }

    public function logInventoryImport(array $result, string $status = 'completed'): void
    {
        $message = $this->buildInventoryImportMessage(
            $result,
            isset($result['sheet_name']) ? (string) $result['sheet_name'] : null,
        );

        if ($status === 'completed' && ($result['scanned'] ?? 0) === 0 && ($result['sheets'] ?? 0) === 0 && ($result['errors'] ?? []) !== []) {
            $status = 'failed';
        }

        $this->log('inventory_import', $status, $message, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function buildInventoryImportMessage(array $result, ?string $sheetName = null): string
    {
        $scope = $this->formatImportSheetLabel($sheetName ?? (isset($result['sheet_name']) ? (string) $result['sheet_name'] : null));

        $message = sprintf(
            '%s: Imported %d SKU row(s) from %d sheet(s). Scanned: %d, already in DB: %d, not in Net32: %d.',
            $scope,
            $result['imported'] ?? 0,
            $result['sheets'] ?? 0,
            $result['scanned'] ?? 0,
            $result['skipped'] ?? 0,
            $result['ignored'] ?? 0,
        );

        $errors = $result['errors'] ?? [];

        if ($errors !== []) {
            $message .= ' Warnings: ' . implode(' ', array_slice($errors, 0, 2));
        }

        return $message;
    }

    public function formatImportSheetLabel(?string $sheetName): string
    {
        $sheetName = trim((string) $sheetName);

        return $sheetName !== '' ? 'Sheet "' . $sheetName . '"' : 'All sheets';
    }

    public function logInventoryImportStarted(int $jobId, ?string $sheetName, ?int $userId = null): int
    {
        $message = sprintf(
            'Inventory import started (%s).',
            $this->formatImportSheetLabel($sheetName),
        );

        return $this->log(
            'inventory_import',
            'running',
            $message,
            [
                'job_id'     => $jobId,
                'sheet_name' => $sheetName,
            ],
            $userId,
            $jobId,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public function updateInventoryImportLog(int $logId, string $status, string $message, array $details = []): void
    {
        $this->logs->updateEntry($logId, $status, $message, $details);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $record
     *
     * @return array<string, array{label: string, from: mixed, to: mixed}>
     */
    public function detectInventoryChanges(array $existing, array $record): array
    {
        $fields = [
            'sheet_name'  => 'Sheet',
            'rack'        => 'Rack',
            'bin'         => 'Bin',
            'sku'         => 'SKU',
            'name'        => 'Name',
            'description' => 'Description',
            'quantity'    => 'Quantity',
            'is_main_sku' => 'Main SKU',
        ];

        $changes = [];

        foreach ($fields as $key => $label) {
            $from = $existing[$key] ?? null;
            $to   = $record[$key] ?? null;

            if ($key === 'is_main_sku') {
                $from = ! empty($from) ? 1 : 0;
                $to   = ! empty($to) ? 1 : 0;
            } elseif ($key === 'quantity') {
                $from = (int) $from;
                $to   = (int) $to;
            } elseif (in_array($key, ['name', 'description'], true)) {
                $from = trim((string) ($from ?? '')) !== '' ? trim((string) $from) : null;
                $to   = trim((string) ($to ?? '')) !== '' ? trim((string) $to) : null;
            } else {
                $from = trim((string) ($from ?? ''));
                $to   = trim((string) ($to ?? ''));
            }

            if ($from !== $to) {
                $changes[$key] = [
                    'label' => $label,
                    'from'  => $from,
                    'to'    => $to,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $record
     * @param array<string, array{label: string, from: mixed, to: mixed}> $changes
     */
    public function logInventoryEdit(
        array $existing,
        array $record,
        int $id,
        array $changes,
        string $status = 'completed',
        ?string $errorMessage = null,
    ): void {
        if ($changes === []) {
            return;
        }

        $sku = trim((string) ($record['sku'] ?? $existing['sku'] ?? ''));

        if ($status === 'failed') {
            $fieldSummary = $this->summarizeChangedFields($changes);
            $baseMessage  = $errorMessage ?? sprintf(
                'Could not update inventory row%s.',
                $sku !== '' ? ' for SKU ' . $sku : '',
            );

            $message = $fieldSummary !== ''
                ? sprintf('%s Changed: %s.', rtrim($baseMessage, '.'), $fieldSummary)
                : $baseMessage;
        } else {
            $message = sprintf(
                'Updated inventory row%s: %s.',
                $sku !== '' ? ' ' . $sku : '',
                $this->summarizeChangedFields($changes),
            );
        }

        $details = [
            'inventory_id' => $id,
            'sku'          => $sku,
            'changes'      => $changes,
        ];

        foreach (['sheet_name', 'rack', 'bin'] as $field) {
            $details[$field] = $record[$field] ?? $existing[$field] ?? null;
        }

        $this->log(
            'edit_qty',
            $status,
            $message,
            $details,
            null,
            $id,
        );
    }

    /**
     * @param array<string, array{label: string, from: mixed, to: mixed}> $changes
     */
    private function summarizeChangedFields(array $changes): string
    {
        $parts = [];

        foreach ($changes as $key => $change) {
            $parts[] = $this->summarizeInventoryChange($key, $change);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array{label: string, from: mixed, to: mixed} $change
     */
    private function summarizeInventoryChange(string $key, array $change): string
    {
        if (in_array($key, ['name', 'description'], true)) {
            return $change['label'];
        }

        if ($key === 'quantity') {
            return sprintf(
                '%s %s → %s',
                $change['label'],
                number_format((int) $change['from']),
                number_format((int) $change['to']),
            );
        }

        $from = $this->formatInventoryValue($key, $change['from']);
        $to   = $this->formatInventoryValue($key, $change['to']);
        $part = sprintf('%s %s → %s', $change['label'], $from, $to);

        if (mb_strlen($part) > 60) {
            return $change['label'];
        }

        return $part;
    }

    private function formatInventoryValue(string $field, mixed $value): string
    {
        if ($field === 'is_main_sku') {
            return ! empty($value) ? 'Yes' : 'No';
        }

        if ($field === 'quantity') {
            return number_format((int) $value);
        }

        if ($value === null || $value === '') {
            return '(empty)';
        }

        if (in_array($field, ['name', 'description', 'sheet_name'], true)) {
            return '"' . (string) $value . '"';
        }

        return (string) $value;
    }

    /**
     * Fix activity log rows left on running/queued when the linked import job already finished.
     */
    public function reconcileStaleImportLogs(): void
    {
        $db = \Config\Database::connect();

        $rows = $db->table('activity_logs al')
            ->select('al.id AS log_id, j.id AS job_id, j.status AS job_status, j.progress_message, j.result, j.errors, j.sheet_name')
            ->join('inventory_import_jobs j', 'j.id = al.reference_id AND j.activity_log_id = al.id', 'inner')
            ->where('al.action', 'inventory_import')
            ->whereIn('al.status', ['running', 'queued'])
            ->whereIn('j.status', ['completed', 'failed', 'cancelled'])
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $result = $this->decodeJsonField($row['result'] ?? null);
            $errors = $this->decodeJsonField($row['errors'] ?? null);

            $details = is_array($result) ? $result : [];
            $details['job_id']     = (int) $row['job_id'];
            $details['sheet_name'] = $row['sheet_name'] ?? null;

            if (is_array($errors) && $errors !== []) {
                $details['errors'] = array_values($errors);
            }

            $jobStatus = (string) $row['job_status'];
            $sheetName = isset($row['sheet_name']) ? (string) $row['sheet_name'] : null;

            if ($jobStatus === 'completed' && is_array($result)) {
                $message = $this->buildInventoryImportMessage(
                    array_merge($result, ['sheet_name' => $sheetName]),
                    $sheetName,
                );
            } else {
                $message = (string) ($row['progress_message'] ?? 'Import finished.');
            }

            $this->updateInventoryImportLog(
                (int) $row['log_id'],
                $jobStatus,
                $message,
                $details,
            );
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

    public function logSheetsSyncQueued(): void
    {
        $this->log(
            'sheets_sync',
            'queued',
            'Google Sheets inventory sync started in the background.',
        );
    }
}

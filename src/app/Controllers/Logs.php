<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLogModel;
use CodeIgniter\HTTP\ResponseInterface;

class Logs extends BaseController
{
    private const DEFAULT_PER_PAGE = 50;

    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [25, 50, 100, 200];

    protected ActivityLogModel $logs;

    public function __construct()
    {
        $this->logs = model(ActivityLogModel::class);
    }

    public function index()
    {
        service('activityLog')->reconcileStaleImportLogs();
        service('inventoryImportJob')->reconcileStuckJobs();
        service('inventoryQtySyncJob')->reconcileStuckJobs();

        $actionFilter = trim((string) $this->request->getGet('action'));
        $perPage      = $this->resolvePerPage();
        $group        = 'logs';
        $validActions = array_keys($this->logs->actionLabels());

        if ($actionFilter !== '' && ! in_array($actionFilter, $validActions, true)) {
            $actionFilter = '';
        }

        $entries = $this->enrichLogEntries($this->logs->paginateLogs(
            $actionFilter !== '' ? $actionFilter : null,
            $perPage,
            $group,
        ));

        $this->logs->pager->only(['action', 'per_page']);

        return view('logs/index', [
            'title'          => 'Logs',
            'entries'        => $entries,
            'actionFilter'   => $actionFilter,
            'actionLabels'   => $this->logs->actionLabels(),
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'defaultPerPage' => self::DEFAULT_PER_PAGE,
            'pager'          => $this->logs->pager,
            'pagerGroup'     => $group,
        ]);
    }

    public function cancelJob(): ResponseInterface
    {
        $action = trim((string) $this->request->getPost('action'));
        $jobId  = (int) $this->request->getPost('job_id');

        if ($jobId <= 0) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(['ok' => false, 'message' => 'Job ID is required.']);
        }

        $result = match ($action) {
            'inventory_import' => service('inventoryImportJob')->requestCancel($jobId),
            'inventory_qty_sync' => service('inventoryQtySyncJob')->requestCancel($jobId),
            default => ['ok' => false, 'message' => 'This log entry cannot be cancelled.'],
        };

        if ($result['ok']) {
            service('inventoryImportJob')->reconcileStuckJobs();
            service('inventoryQtySyncJob')->reconcileStuckJobs();
        }

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 422)
            ->setJSON($result);
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function enrichLogEntries(array $entries): array
    {
        foreach ($entries as &$entry) {
            $entry['can_cancel']       = false;
            $entry['cancel_requested'] = false;
            $entry['is_active']        = false;
            $entry['job_id']           = null;

            $action = (string) ($entry['action'] ?? '');
            $status = (string) ($entry['status'] ?? '');

            if (! in_array($status, ['queued', 'running'], true)) {
                continue;
            }

            if (! in_array($action, ['inventory_import', 'inventory_qty_sync'], true)) {
                continue;
            }

            $jobId = (int) ($entry['reference_id'] ?? 0);

            if ($jobId <= 0) {
                continue;
            }

            $jobStatus = $action === 'inventory_import'
                ? service('inventoryImportJob')->getStatus($jobId)
                : service('inventoryQtySyncJob')->getStatus($jobId);

            if ($jobStatus === null) {
                continue;
            }

            $entry['job_id']           = $jobId;
            $entry['is_active']        = (bool) ($jobStatus['is_active'] ?? false);
            $entry['can_cancel']       = (bool) ($jobStatus['can_cancel'] ?? false);
            $entry['cancel_requested'] = (bool) ($jobStatus['cancel_requested'] ?? false);
        }

        unset($entry);

        return $entries;
    }

    private function resolvePerPage(): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLogModel;

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

        $actionFilter = trim((string) $this->request->getGet('action'));
        $perPage      = $this->resolvePerPage();
        $group        = 'logs';
        $validActions = array_keys($this->logs->actionLabels());

        if ($actionFilter !== '' && ! in_array($actionFilter, $validActions, true)) {
            $actionFilter = '';
        }

        $entries = $this->logs->paginateLogs(
            $actionFilter !== '' ? $actionFilter : null,
            $perPage,
            $group,
        );

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

    private function resolvePerPage(): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }
}

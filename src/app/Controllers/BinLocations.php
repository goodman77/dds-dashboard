<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\InventoryModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class BinLocations extends BaseController
{
    private const DEFAULT_PER_PAGE = 500;

    /** @var list<int> */
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100, 200, 500];

    protected InventoryModel $bins;

    public function __construct()
    {
        $this->bins = model(InventoryModel::class);
    }

    public function index()
    {
        $search       = trim((string) $this->request->getGet('q'));
        $sheetName    = trim((string) $this->request->getGet('sheet'));
        $net32Filter  = trim((string) $this->request->getGet('net32'));
        $quantityFilter = trim((string) $this->request->getGet('qty'));
        $perPage      = $this->resolvePerPage();
        $group        = 'bins';
        $sheetNames   = $this->resolveSheetNameOptions();
        $importJobId  = (int) $this->request->getGet('import_job');
        $importJobId  = $importJobId > 0 ? $importJobId : null;
        $results      = $this->bins->paginateSearch(
            $search !== '' ? $search : null,
            $sheetName !== '' ? $sheetName : null,
            $perPage,
            $group,
            $this->normalizeNet32Filter($net32Filter),
            $this->normalizeQuantityFilter($quantityFilter),
        );

        $this->bins->pager->only(['q', 'sheet', 'net32', 'qty', 'per_page']);

        return view('bin_locations/index', [
            'title'          => 'Inventory',
            'locations'      => $results,
            'locationGroups' => $this->groupLocationsForDisplay($results),
            'search'         => $search,
            'sheetFilter'    => $sheetName,
            'net32Filter'    => $net32Filter,
            'quantityFilter' => $quantityFilter,
            'sheetNames'     => $sheetNames,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'defaultPerPage' => self::DEFAULT_PER_PAGE,
            'lastSyncedAt'   => $this->bins->getLastSyncedAt(),
            'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/' . config('GoogleSheets')->spreadsheetId,
            'pager'          => $this->bins->pager,
            'pagerGroup'     => $group,
            'totalLocations' => $this->bins->countAllResults(),
            'importJobStatus' => service('inventoryImportJob')->getStatus($importJobId),
            'importJobId'     => $importJobId,
            'flashSuccess'   => session()->getFlashdata('success'),
            'flashError'     => session()->getFlashdata('error'),
        ]);
    }

    public function importStatus(): ResponseInterface
    {
        $jobId = $this->request->getGet('job_id');

        $status = service('inventoryImportJob')->getStatus($jobId !== null ? (int) $jobId : null) ?? ['status' => 'none'];

        if (is_array($status)) {
            $status['sheet_names'] = $this->resolveSheetNameOptions();
        }

        return $this->response->setJSON($status);
    }

    public function cancelImport(): ResponseInterface
    {
        $jobId = (int) $this->request->getPost('job_id');

        if ($jobId <= 0) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON(['ok' => false, 'message' => 'Import job ID is required.']);
        }

        $result = service('inventoryImportJob')->requestCancel($jobId);

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 422)
            ->setJSON($result);
    }

    public function show(int $id): ResponseInterface
    {
        $location = $this->bins->find($id);

        if ($location === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'Inventory row not found.',
            ]);
        }

        return $this->response->setJSON([
            'ok'          => true,
            'id'          => (int) $location['id'],
            'sheet_name'  => (string) $location['sheet_name'],
            'rack'        => (string) $location['rack'],
            'bin'         => (string) $location['bin'],
            'sku'         => (string) ($location['sku'] ?? ''),
            'is_main_sku' => ! empty($location['is_main_sku']),
            'name'        => (string) ($location['name'] ?? ''),
            'description' => (string) ($location['description'] ?? ''),
            'quantity'    => (int) ($location['quantity'] ?? 0),
        ]);
    }

    public function validateSave(): ResponseInterface
    {
        $id = $this->request->getPost('id');
        $id = is_numeric($id) ? (int) $id : null;

        $result = $this->bins->previewManualSave($this->request->getPost(), $id);

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 422)
            ->setJSON($result);
    }

    public function store(): RedirectResponse
    {
        $result = $this->bins->saveFromInput($this->request->getPost());

        return $this->redirectFromSaveResult($result);
    }

    public function update(int $id): RedirectResponse
    {
        $result = $this->bins->saveFromInput($this->request->getPost(), $id);

        return $this->redirectFromSaveResult($result);
    }

    public function checkQuantity(int $id): ResponseInterface
    {
        $result = service('inventoryQuantityCheck')->checkRow($id);

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 422)
            ->setJSON($result);
    }

    public function sync(): RedirectResponse
    {
        $sheetName = trim((string) $this->request->getPost('sheet_name'));

        if ($sheetName === '') {
            return redirect()->to($this->inventoryUrl())->with(
                'error',
                'Choose a sheet tab to import.',
            );
        }

        $dispatch = service('inventoryImportJob')->dispatch(
            $sheetName,
            auth()->loggedIn() ? (int) auth()->id() : null,
        );

        $redirectQuery = [];

        if ($dispatch['started'] && isset($dispatch['job_id'])) {
            $redirectQuery['import_job'] = (int) $dispatch['job_id'];
        }

        return redirect()->to($this->inventoryUrl($redirectQuery))->with(
            $dispatch['started'] ? 'success' : 'error',
            $dispatch['message'],
        );
    }

    /**
     * @return list<string>
     */
    private function resolveSheetNameOptions(): array
    {
        $sheets = service('googleSheets')->getSheetNameOptions();
        $existing = $this->bins->getSheetNames();

        return \App\Libraries\GoogleSheets\GoogleSheetsClient::sortNamesAscending(
            array_merge($sheets, $existing),
        );
    }

    /**
     * @param array<string, int|string> $query
     */
    private function inventoryUrl(array $query = []): string
    {
        if ($query === []) {
            $query = array_filter([
                'q'           => trim((string) $this->request->getGet('q')),
                'sheet'       => trim((string) $this->request->getGet('sheet')),
                'net32'       => trim((string) $this->request->getGet('net32')),
                'qty'         => trim((string) $this->request->getGet('qty')),
                'per_page'    => (int) $this->request->getGet('per_page'),
                'page'        => (int) $this->request->getGet('page'),
                'import_job'  => (int) $this->request->getGet('import_job'),
            ], static fn ($value): bool => $value !== '' && $value !== 0);
        }

        return site_url('inventory' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    private function resolvePerPage(): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private function normalizeNet32Filter(string $filter): ?string
    {
        return in_array($filter, ['missing', 'ok', 'unchecked'], true) ? $filter : null;
    }

    private function normalizeQuantityFilter(string $filter): ?string
    {
        return $filter === 'zero' ? 'zero' : null;
    }

    /**
     * @param list<array<string, mixed>> $locations
     *
     * @return list<array{
     *     main: array<string, mixed>|null,
     *     alternates: list<array<string, mixed>>,
     *     sheet_name: string,
     *     rack: string,
     *     bin: string
     * }>
     */
    private function groupLocationsForDisplay(array $locations): array
    {
        /** @var array<string, array{main: array<string, mixed>|null, alternates: list<array<string, mixed>>, sheet_name: string, rack: string, bin: string}> $groups */
        $groups = [];
        $order = [];

        foreach ($locations as $location) {
            $key = implode('|', [
                (string) $location['sheet_name'],
                (string) $location['rack'],
                (string) $location['bin'],
            ]);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'main'       => null,
                    'alternates' => [],
                    'sheet_name' => (string) $location['sheet_name'],
                    'rack'       => (string) $location['rack'],
                    'bin'        => (string) $location['bin'],
                ];
                $order[] = $key;
            }

            if (! empty($location['is_main_sku'])) {
                $groups[$key]['main'] = $location;

                continue;
            }

            $groups[$key]['alternates'][] = $location;
        }

        $result = [];

        foreach ($order as $key) {
            $group = $groups[$key];

            if ($group['main'] === null && $group['alternates'] === []) {
                continue;
            }

            $result[] = $group;
        }

        return $result;
    }

    /**
     * @param array{ok: bool, message: string} $result
     */
    private function redirectFromSaveResult(array $result): RedirectResponse
    {
        $redirect = redirect()->to($this->inventoryUrl());

        if ($result['ok']) {
            return $redirect->with('success', $result['message']);
        }

        return $redirect->with('error', $result['message']);
    }
}

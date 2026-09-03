<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Inventory</h1>
                <p class="text-muted small mb-0">One row per SKU — rack, bin, and Net32 product details</p>
            </div>
            <div class="col-sm-6 text-sm-end mt-2 mt-sm-0">
                <div class="d-inline-flex flex-wrap justify-content-sm-end align-items-center gap-2">
                    <button type="button" class="btn btn-success btn-sm" id="inventory-add-btn">
                        <i class="bi bi-plus-lg"></i> Add Main SKU
                    </button>

                    <div class="btn-group">
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm dropdown-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            id="google-sheets-menu-btn"
                        >
                            <i class="bi bi-table"></i> Google Sheets
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <a
                                    class="dropdown-item"
                                    href="<?= esc($spreadsheetUrl) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <i class="bi bi-box-arrow-up-right me-2 text-muted"></i>Open spreadsheet
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button
                                    type="button"
                                    class="dropdown-item"
                                    id="sheets-sync-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#import-sheets-modal"
                                >
                                    <i class="bi bi-arrow-repeat me-2 text-primary"></i>Sync from Google Sheets
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="btn-group">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm dropdown-toggle"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            id="sync-check-menu-btn"
                        >
                            <i class="bi bi-cloud-check"></i> Sync &amp; Check
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <button
                                    type="button"
                                    class="dropdown-item"
                                    id="qty-sync-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#qty-sync-modal"
                                >
                                    <i class="bi bi-cloud-download me-2 text-warning"></i>Net32 Qty Sync
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    class="dropdown-item"
                                    id="shipstation-check-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#shipstation-check-modal"
                                >
                                    <i class="bi bi-geo-alt me-2 text-info"></i>ShipStation Location Sync
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (! empty($flashSuccess)) : ?>
            <div class="alert alert-success alert-dismissible fade show auto-dismiss-alert" role="alert">
                <?= esc($flashSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif ?>
        <?php if (! empty($flashError)) : ?>
            <div class="alert alert-danger alert-dismissible fade show auto-dismiss-alert" role="alert">
                <?= esc($flashError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif ?>

        <div id="import-status-panel" class="alert alert-info d-none">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <strong id="import-status-title">Google Sheets sync running...</strong>
                    <span id="import-status-badge" class="badge text-bg-info">running</span>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="import-cancel-btn">
                    <i class="bi bi-x-circle"></i> Cancel Import
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span id="import-progress-label" class="small fw-semibold">0%</span>
                <span id="import-progress-remaining" class="small text-muted"></span>
            </div>
            <div class="progress mb-2" style="height: 1.25rem;" aria-label="Inventory import progress">
                <div
                    id="import-progress-bar"
                    class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="0"
                    style="width: 0%"
                ></div>
            </div>
            <div id="import-status-counts" class="small fw-semibold mb-1"></div>
            <div id="import-status-message" class="small mb-0 text-muted"></div>
        </div>

        <div id="import-complete-panel" class="alert alert-success alert-dismissible fade show d-none" role="alert">
            <strong id="import-complete-title">Sync finished.</strong>
            <div id="import-complete-message" class="mb-0"></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div id="qty-sync-status-panel" class="alert alert-warning d-none">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <strong id="qty-sync-status-title">Net32 quantity sync running...</strong>
                    <span id="qty-sync-status-badge" class="badge text-bg-warning">running</span>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="qty-sync-cancel-btn">
                    <i class="bi bi-x-circle"></i> Cancel Sync
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span id="qty-sync-progress-label" class="small fw-semibold">0%</span>
                <span id="qty-sync-progress-remaining" class="small text-muted"></span>
            </div>
            <div class="progress mb-2" style="height: 1.25rem;" aria-label="Net32 quantity sync progress">
                <div
                    id="qty-sync-progress-bar"
                    class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="0"
                    style="width: 0%"
                ></div>
            </div>
            <div id="qty-sync-status-counts" class="small fw-semibold mb-1"></div>
            <div id="qty-sync-status-message" class="small mb-0 text-muted"></div>
        </div>

        <div id="qty-sync-complete-panel" class="alert alert-success alert-dismissible fade show d-none" role="alert">
            <strong id="qty-sync-complete-title">Quantity sync finished.</strong>
            <div id="qty-sync-complete-message" class="mb-0"></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div id="shipstation-check-status-panel" class="alert alert-info d-none">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <div class="d-flex align-items-center gap-2">
                    <strong id="shipstation-check-status-title">ShipStation location sync running...</strong>
                    <span id="shipstation-check-status-badge" class="badge text-bg-info">running</span>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="shipstation-check-cancel-btn">
                    <i class="bi bi-x-circle"></i> Cancel Check
                </button>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span id="shipstation-check-progress-label" class="small fw-semibold">0%</span>
                <span id="shipstation-check-progress-remaining" class="small text-muted"></span>
            </div>
            <div class="progress mb-2" style="height: 1.25rem;" aria-label="ShipStation location check progress">
                <div
                    id="shipstation-check-progress-bar"
                    class="progress-bar progress-bar-striped progress-bar-animated bg-info"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="0"
                    style="width: 0%"
                ></div>
            </div>
            <div id="shipstation-check-status-counts" class="small fw-semibold mb-1"></div>
            <div id="shipstation-check-status-message" class="small mb-0 text-muted"></div>
        </div>

        <div id="shipstation-check-complete-panel" class="alert alert-success alert-dismissible fade show d-none" role="alert">
            <strong id="shipstation-check-complete-title">ShipStation location sync finished.</strong>
            <div id="shipstation-check-complete-message" class="mb-0"></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-primary"><i class="bi bi-grid-3x3-gap"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inventory Rows</span>
                        <span class="info-box-number"><?= esc(number_format($totalLocations)) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-success"><i class="bi bi-clock-history"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Net32 Qty Sync</span>
                        <span class="info-box-number" style="font-size: 1rem;" id="last-net32-qty-sync-at">
                            <?= $lastNet32QtySyncAt ? esc(format_log_datetime($lastNet32QtySyncAt)) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-info"><i class="bi bi-geo-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last ShipStation Check</span>
                        <span class="info-box-number" style="font-size: 1rem;" id="last-shipstation-check-at">
                            <?= ! empty($lastShipStationCheckAt) ? esc(format_log_datetime($lastShipStationCheckAt)) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <form method="get" action="<?= site_url('inventory') ?>" class="row g-2 align-items-end">
                    <div class="col-lg-2 col-md-4">
                        <label for="q" class="form-label small text-muted mb-1">Search</label>
                        <input
                            type="search"
                            id="q"
                            name="q"
                            class="form-control"
                            placeholder="Search SKU, name, rack, bin, or sheet..."
                            value="<?= esc($search) ?>"
                        >
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="sheet" class="form-label small text-muted mb-1">Sheet tab</label>
                        <select name="sheet" id="sheet" class="form-select">
                            <option value="">All sheets</option>
                            <?php foreach ($sheetNames as $name) : ?>
                                <option value="<?= esc($name) ?>" <?= $sheetFilter === $name ? 'selected' : '' ?>>
                                    <?= esc($name) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="net32" class="form-label small text-muted mb-1">Net32 status</label>
                        <select name="net32" id="net32" class="form-select">
                            <option value="">All rows</option>
                            <option value="missing" <?= $net32Filter === 'missing' ? 'selected' : '' ?>>Not in Net32</option>
                            <option value="ok" <?= $net32Filter === 'ok' ? 'selected' : '' ?>>In Net32</option>
                            <option value="unchecked" <?= $net32Filter === 'unchecked' ? 'selected' : '' ?>>Not checked yet</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="shipstation" class="form-label small text-muted mb-1">ShipStation status</label>
                        <select name="shipstation" id="shipstation" class="form-select">
                            <option value="">All rows</option>
                            <option value="missing" <?= ($shipStationFilter ?? '') === 'missing' ? 'selected' : '' ?>>Not in ShipStation</option>
                            <option value="ok" <?= ($shipStationFilter ?? '') === 'ok' ? 'selected' : '' ?>>In ShipStation</option>
                            <option value="wrong_warehouse" <?= ($shipStationFilter ?? '') === 'wrong_warehouse' ? 'selected' : '' ?>>Wrong warehouse</option>
                            <option value="mismatch" <?= ($shipStationFilter ?? '') === 'mismatch' ? 'selected' : '' ?>>Location mismatch</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label for="qty" class="form-label small text-muted mb-1">Quantity</label>
                        <select name="qty" id="qty" class="form-select">
                            <option value="">All rows</option>
                            <option value="zero" <?= ($quantityFilter ?? '') === 'zero' ? 'selected' : '' ?>>Quantity 0</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <?php if ($perPage !== $defaultPerPage) : ?>
                            <input type="hidden" name="per_page" value="<?= esc($perPage) ?>">
                        <?php endif ?>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <?php if ($search !== '' || $sheetFilter !== '' || $net32Filter !== '' || ($quantityFilter ?? '') !== '' || ($shipStationFilter ?? '') !== '') : ?>
                            <a href="<?= site_url('inventory') ?>" class="btn btn-outline-secondary">Clear</a>
                        <?php endif ?>
                    </div>
                </form>
            </div>

            <div class="card-body p-0">
                <div class="px-3 py-2 border-bottom">
                <?= view('bin_locations/_pagination', [
                    'pager'          => $pager,
                    'pagerGroup'     => $pagerGroup,
                    'search'         => $search,
                    'sheetFilter'    => $sheetFilter,
                    'net32Filter'    => $net32Filter,
                    'quantityFilter' => $quantityFilter ?? '',
                    'shipStationFilter' => $shipStationFilter ?? '',
                    'perPage'        => $perPage,
                    'perPageOptions' => $perPageOptions,
                ]) ?>
                </div>

                <div class="table-responsive">
                    <style>
                        .inventory-sku-alt td {
                            background-color: rgba(0, 0, 0, 0.02);
                            border-top: none;
                        }
                        .inventory-sku-alt .inventory-sku-indent {
                            display: inline-block;
                            width: 1rem;
                        }
                        .inventory-sku-alt td:first-child {
                            padding-left: 2.25rem;
                        }
                        .inventory-group-start td {
                            border-bottom: none;
                        }
                        .inventory-sku-alt td {
                            border-top: none;
                        }
                        .inventory-group-end td,
                        .inventory-sku-main.inventory-group-start:last-child td,
                        tr.inventory-sku-main.inventory-group-start:only-of-type td {
                            border-bottom-width: 1px;
                        }
                    </style>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Sheet</th>
                                <th>Rack</th>
                                <th>Bin</th>
                                <th>SKU</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th class="text-end">Qty</th>
                                <th>Net32</th>
                                <th>ShipStation</th>
                                <th class="text-end" style="width: 220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($locationGroups === []) : ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        No inventory rows yet. Click <strong>Import from Google Sheets</strong> or <strong>Add Row</strong>.
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($locationGroups as $group) : ?>
                                    <?= view('bin_locations/_inventory_group', ['group' => $group]) ?>
                                <?php endforeach ?>
                            <?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($pager->getTotal($pagerGroup) > 0) : ?>
                <div class="card-footer">
                    <?= view('bin_locations/_pagination', [
                        'pager'          => $pager,
                        'pagerGroup'     => $pagerGroup,
                        'search'         => $search,
                        'sheetFilter'    => $sheetFilter,
                        'net32Filter'    => $net32Filter,
                        'quantityFilter' => $quantityFilter ?? '',
                        'shipStationFilter' => $shipStationFilter ?? '',
                        'perPage'        => $perPage,
                        'perPageOptions' => $perPageOptions,
                    ]) ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</section>

<div class="modal fade" id="import-sheets-modal" tabindex="-1" aria-labelledby="import-sheets-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= site_url('inventory/sync') ?>" method="post" id="sheets-sync-form">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="import-sheets-modal-label">Sync from Google Sheets</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Runs in the background (usually within a minute). Progress appears below and on the <a href="<?= site_url('logs') ?>">Logs</a> page.</p>
                    <div class="mb-3">
                        <label class="form-label">Sync mode</label>
                        <div class="vstack gap-2">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="import_mode"
                                    id="import-mode-reconcile"
                                    value="reconcile"
                                    checked
                                >
                                <label class="form-check-label" for="import-mode-reconcile">
                                    <strong>Reconcile</strong>
                                    <span class="text-muted d-block small">Match inventory to the sheet — adds new SKUs, updates moved bins, and <strong>removes SKUs no longer on the sheet</strong>.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="radio"
                                    name="import_mode"
                                    id="import-mode-import"
                                    value="import"
                                >
                                <label class="form-check-label" for="import-mode-import">
                                    <strong>Add new only</strong>
                                    <span class="text-muted d-block small">Only import SKUs that are not already in inventory. Does not update or remove existing rows.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="import-pipeline-options">
                        <label class="form-label">After reconcile</label>
                        <div class="vstack gap-2">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="with_net32"
                                    id="import-with-net32"
                                    value="1"
                                    checked
                                >
                                <label class="form-check-label" for="import-with-net32">
                                    Also sync Net32 quantities
                                    <span class="text-muted d-block small">Pull current stock levels from Net32 for every SKU in scope.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="with_shipstation"
                                    id="import-with-shipstation"
                                    value="1"
                                    checked
                                >
                                <label class="form-check-label" for="import-with-shipstation">
                                    Also sync ShipStation locations
                                    <span class="text-muted d-block small">Move each SKU to its sheet bin in ShipStation (same as the row pin button).</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label for="import-sheet-name" class="form-label">Sheet tab</label>
                        <select class="form-select" name="sheet_name" id="import-sheet-name" required>
                            <option value="">Choose a sheet...</option>
                            <option value="*">All sheets</option>
                            <?php foreach ($sheetNames as $name) : ?>
                                <option value="<?= esc($name) ?>"><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="sheets-sync-submit">
                        <i class="bi bi-arrow-repeat"></i> Start Sync
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="qty-sync-modal" tabindex="-1" aria-labelledby="qty-sync-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= site_url('inventory/qty-sync') ?>" method="post" id="qty-sync-form">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="qty-sync-modal-label">Net32 QTY Sync</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Checks every SKU on the selected sheet tab against Net32 and updates local quantities. Runs in the background — progress appears below and on the <a href="<?= site_url('logs') ?>">Logs</a> page.</p>
                    <div class="mb-0">
                        <label for="qty-sync-sheet-name" class="form-label">Sheet tab</label>
                        <select class="form-select" name="sheet_name" id="qty-sync-sheet-name" required>
                            <option value="">Choose a sheet...</option>
                            <?php foreach ($sheetNames as $name) : ?>
                                <option value="<?= esc($name) ?>"><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-warning" id="qty-sync-submit">
                        <i class="bi bi-cloud-download"></i> Start QTY Sync
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="shipstation-check-modal" tabindex="-1" aria-labelledby="shipstation-check-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= site_url('inventory/shipstation-check') ?>" method="post" id="shipstation-check-form">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="shipstation-check-modal-label">ShipStation Location Sync</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Syncs every SKU on the selected sheet with ShipStation — same as the row pin button. Moves existing SKUs to the correct bin location (does not create new SKUs). Runs in the background; progress appears below.</p>
                    <div class="mb-0">
                        <label for="shipstation-check-sheet-name" class="form-label">Sheet tab</label>
                        <select class="form-select" name="sheet_name" id="shipstation-check-sheet-name" required>
                            <option value="">Choose a sheet...</option>
                            <option value="*">All sheets</option>
                            <?php foreach ($sheetNames as $name) : ?>
                                <option value="<?= esc($name) ?>"><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-info" id="shipstation-check-submit">
                        <i class="bi bi-geo-alt"></i> Start Location Sync
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="inventory-form-modal" tabindex="-1" aria-labelledby="inventory-form-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="inventory-form" method="post" action="<?= site_url('inventory') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="inventory-form-modal-label">Add Inventory Row</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="inventory-form-error" class="alert alert-danger d-none" role="alert"></div>
                    <div id="inventory-form-warnings" class="alert alert-warning d-none" role="alert">
                        <strong>Please review before saving:</strong>
                        <ul id="inventory-form-warnings-list" class="mb-2 mt-2"></ul>
                        <p class="mb-0 small">Go back to edit the row, or choose Save anyway to continue.</p>
                    </div>
                    <div id="inventory-alternate-context" class="alert alert-light border small d-none" role="status"></div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="inventory-sheet-name" class="form-label">Sheet</label>
                            <input
                                type="text"
                                class="form-control"
                                id="inventory-sheet-name"
                                name="sheet_name"
                                list="inventory-sheet-options"
                                required
                                value="<?= esc($sheetNames[0] ?? '') ?>"
                            >
                            <datalist id="inventory-sheet-options">
                                <?php foreach ($sheetNames as $name) : ?>
                                    <option value="<?= esc($name) ?>"></option>
                                <?php endforeach ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label for="inventory-rack" class="form-label">Rack</label>
                            <input type="text" class="form-control" id="inventory-rack" name="rack" required>
                        </div>
                        <div class="col-md-4">
                            <label for="inventory-bin" class="form-label">Bin</label>
                            <input type="text" class="form-control" id="inventory-bin" name="bin" required>
                        </div>
                        <div class="col-md-4">
                            <label for="inventory-sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="inventory-sku" name="sku" required>
                        </div>
                        <div class="col-md-4">
                            <label for="inventory-name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="inventory-name" name="name">
                        </div>
                        <div class="col-md-4">
                            <label for="inventory-quantity" class="form-label">Quantity</label>
                            <input type="number" min="0" class="form-control" id="inventory-quantity" name="quantity" value="0">
                        </div>
                        <div class="col-md-12">
                            <label for="inventory-description" class="form-label">Description</label>
                            <textarea class="form-control" id="inventory-description" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-md-12" id="inventory-main-sku-field">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="inventory-is-main-sku" name="is_main_sku">
                                <label class="form-check-label" for="inventory-is-main-sku">Main SKU for this rack/bin</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="inventory-form-submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const importInitialStatus = <?= json_encode($importJobStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const qtySyncInitialStatus = <?= json_encode($qtySyncJobStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const shipStationCheckInitialStatus = <?= json_encode($shipStationCheckJobStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const importStatusUrl = <?= json_encode(site_url('inventory/import-status')) ?>;
const qtySyncStatusUrl = <?= json_encode(site_url('inventory/qty-sync-status')) ?>;
const shipStationCheckStatusUrl = <?= json_encode(site_url('inventory/shipstation-check-status')) ?>;
const importCancelUrl = <?= json_encode(site_url('inventory/import/cancel')) ?>;
const qtySyncCancelUrl = <?= json_encode(site_url('inventory/qty-sync/cancel')) ?>;
const shipStationCheckCancelUrl = <?= json_encode(site_url('inventory/shipstation-check/cancel')) ?>;
const importCsrfName = <?= json_encode(csrf_token()) ?>;
const importCsrfHash = <?= json_encode(csrf_hash()) ?>;
const importJobIdFromUrl = <?= json_encode((int) ($importJobId ?? 0)) ?>;
const qtySyncJobIdFromUrl = <?= json_encode((int) ($qtySyncJobId ?? 0)) ?>;
const shipStationCheckJobIdFromUrl = <?= json_encode((int) ($shipStationCheckJobId ?? 0)) ?>;
const inventoryStoreUrl = <?= json_encode(site_url('inventory')) ?>;
const inventorySheetNames = <?= json_encode(array_values($sheetNames), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const ALERT_DISMISS_MS = 20000;

function dismissAlertElement(el) {
    if (!el || !el.isConnected) {
        return;
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
        bootstrap.Alert.getOrCreateInstance(el).close();

        return;
    }

    el.remove();
}

function scheduleAlertDismiss(el, delayMs) {
    if (!el) {
        return;
    }

    setTimeout(function () {
        dismissAlertElement(el);
    }, delayMs);
}

document.querySelectorAll('.auto-dismiss-alert').forEach(function (el) {
    scheduleAlertDismiss(el, ALERT_DISMISS_MS);
});

window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
        return;
    }

    document.querySelectorAll('.auto-dismiss-alert, #import-complete-panel').forEach(function (el) {
        dismissAlertElement(el);
    });
});

(function () {
    const modalEl = document.getElementById('inventory-form-modal');
    const form = document.getElementById('inventory-form');
    const modalTitle = document.getElementById('inventory-form-modal-label');
    const addBtn = document.getElementById('inventory-add-btn');
    const sheetInput = document.getElementById('inventory-sheet-name');
    const rackInput = document.getElementById('inventory-rack');
    const binInput = document.getElementById('inventory-bin');
    const skuInput = document.getElementById('inventory-sku');
    const nameInput = document.getElementById('inventory-name');
    const descriptionInput = document.getElementById('inventory-description');
    const quantityInput = document.getElementById('inventory-quantity');
    const isMainInput = document.getElementById('inventory-is-main-sku');
    const mainSkuField = document.getElementById('inventory-main-sku-field');
    const alternateContext = document.getElementById('inventory-alternate-context');
    const submitBtn = document.getElementById('inventory-form-submit');
    const errorBox = document.getElementById('inventory-form-error');
    const warningsBox = document.getElementById('inventory-form-warnings');
    const warningsList = document.getElementById('inventory-form-warnings-list');
    const locationInputs = [sheetInput, rackInput, binInput].filter(Boolean);
    const defaultSheet = sheetInput?.value || '';
    const defaultSubmitLabel = submitBtn?.textContent || 'Save';

    if (!modalEl || !form) {
        return;
    }

    function showInventoryModal() {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function setLocationFieldsLocked(locked) {
        locationInputs.forEach(function (input) {
            input.readOnly = locked;
            input.classList.toggle('bg-body-secondary', locked);
        });
    }

    function setFormModeEdit() {
        form.dataset.formMode = 'edit';
        setLocationFieldsLocked(false);

        if (mainSkuField) {
            mainSkuField.classList.remove('d-none');
        }

        if (alternateContext) {
            alternateContext.classList.add('d-none');
            alternateContext.textContent = '';
        }

        if (submitBtn) {
            submitBtn.textContent = defaultSubmitLabel;
        }
    }

    function configureMainAddForm() {
        clearFormFeedback();
        form.action = inventoryStoreUrl;
        form.dataset.formMode = 'main';
        modalTitle.textContent = 'Add Main SKU';
        setLocationFieldsLocked(false);

        if (sheetInput) sheetInput.value = defaultSheet;
        if (rackInput) rackInput.value = '';
        if (binInput) binInput.value = '';
        if (skuInput) skuInput.value = '';
        if (nameInput) nameInput.value = '';
        if (descriptionInput) descriptionInput.value = '';
        if (quantityInput) quantityInput.value = '0';

        if (isMainInput) {
            isMainInput.checked = true;
        }

        if (mainSkuField) {
            mainSkuField.classList.add('d-none');
        }

        if (alternateContext) {
            alternateContext.classList.add('d-none');
            alternateContext.textContent = '';
        }

        if (submitBtn) {
            submitBtn.textContent = 'Add Main SKU';
        }

        showInventoryModal();
    }

    function configureAlternateAddForm(sheetName, rack, bin, mainSku) {
        clearFormFeedback();
        form.action = inventoryStoreUrl;
        form.dataset.formMode = 'alternate';
        modalTitle.textContent = 'Add Alternate SKU';
        setLocationFieldsLocked(true);

        if (sheetInput) sheetInput.value = sheetName;
        if (rackInput) rackInput.value = rack;
        if (binInput) binInput.value = bin;
        if (skuInput) skuInput.value = '';
        if (nameInput) nameInput.value = '';
        if (descriptionInput) descriptionInput.value = '';
        if (quantityInput) quantityInput.value = '0';

        if (isMainInput) {
            isMainInput.checked = false;
        }

        if (mainSkuField) {
            mainSkuField.classList.add('d-none');
        }

        if (alternateContext) {
            alternateContext.textContent = 'Alternate SKU for main SKU '
                + mainSku
                + ' at Sheet '
                + sheetName
                + ', Rack '
                + rack
                + ', Bin '
                + bin
                + '.';
            alternateContext.classList.remove('d-none');
        }

        if (submitBtn) {
            submitBtn.textContent = 'Add Alternate SKU';
        }

        showInventoryModal();
    }

    function clearFormFeedback() {
        form.dataset.awaitingConfirm = '';

        if (errorBox) {
            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        }

        if (warningsBox) {
            warningsBox.classList.add('d-none');
        }

        if (warningsList) {
            warningsList.innerHTML = '';
        }

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = form.dataset.formMode === 'main'
                ? 'Add Main SKU'
                : form.dataset.formMode === 'alternate'
                    ? 'Add Alternate SKU'
                    : defaultSubmitLabel;
        }
    }

    function showFormError(message) {
        form.dataset.awaitingConfirm = '';

        if (warningsBox) {
            warningsBox.classList.add('d-none');
        }

        if (warningsList) {
            warningsList.innerHTML = '';
        }

        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = form.dataset.formMode === 'main'
                ? 'Add Main SKU'
                : form.dataset.formMode === 'alternate'
                    ? 'Add Alternate SKU'
                    : defaultSubmitLabel;
        }

        if (!errorBox) {
            window.alert(message);
            return;
        }

        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function showFormWarnings(warnings) {
        if (errorBox) {
            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        }

        if (!warningsBox || !warningsList) {
            return;
        }

        warningsList.innerHTML = '';
        warnings.forEach(function (warning) {
            const item = document.createElement('li');
            item.textContent = warning;
            warningsList.appendChild(item);
        });

        warningsBox.classList.remove('d-none');
        form.dataset.awaitingConfirm = '1';

        if (submitBtn) {
            submitBtn.textContent = 'Save anyway';
        }
    }

    function getEditIdFromFormAction() {
        const match = String(form.action || '').match(/inventory\/(\d+)(?:\/|$|\?)/);

        return match ? match[1] : '';
    }

    addBtn?.addEventListener('click', configureMainAddForm);

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.add-alternate-sku');

        if (!button) {
            return;
        }

        configureAlternateAddForm(
            button.dataset.sheetName || '',
            button.dataset.rack || '',
            button.dataset.bin || '',
            button.dataset.mainSku || '',
        );
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        clearFormFeedback();
        setFormModeEdit();
    });

    form.querySelectorAll('input, textarea, select').forEach(function (field) {
        field.addEventListener('input', function () {
            if (form.dataset.awaitingConfirm === '1' || (errorBox && !errorBox.classList.contains('d-none'))) {
                clearFormFeedback();
            }
        });
    });

    function setSubmitButtonBusy(label) {
        if (!submitBtn) {
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> '
            + label;
    }

    function resetSubmitButtonLabel() {
        if (!submitBtn) {
            return;
        }

        submitBtn.disabled = false;

        if (form.dataset.awaitingConfirm === '1') {
            submitBtn.textContent = 'Save anyway';
            return;
        }

        if (form.dataset.formMode === 'main') {
            submitBtn.textContent = 'Add Main SKU';
        } else if (form.dataset.formMode === 'alternate') {
            submitBtn.textContent = 'Add Alternate SKU';
        } else {
            submitBtn.textContent = defaultSubmitLabel;
        }
    }

    function submitInventoryForm(forceConfirm) {
        if (!form.reportValidity()) {
            return;
        }

        const formData = new FormData(form);
        const editId = getEditIdFromFormAction();

        if (editId !== '') {
            formData.set('id', editId);
        }

        if (forceConfirm) {
            formData.set('confirm_warnings', '1');
        }

        setSubmitButtonBusy('Saving...');

        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                const data = result.data || {};

                if (data.needs_confirm && Array.isArray(data.warnings) && data.warnings.length > 0) {
                    showFormWarnings(data.warnings);
                    return;
                }

                if (!result.ok || !data.ok) {
                    showFormError(data.message || 'Could not save this inventory row.');
                    return;
                }

                bootstrap.Modal.getInstance(modalEl)?.hide();
                window.location.reload();
            })
            .catch(function () {
                showFormError('Could not save this inventory row. Please try again.');
            })
            .finally(function () {
                if (submitBtn?.disabled) {
                    resetSubmitButtonLabel();
                }
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const forceConfirm = form.dataset.awaitingConfirm === '1';

        if (forceConfirm) {
            form.dataset.awaitingConfirm = '';
        }

        submitInventoryForm(forceConfirm);
    });

    document.querySelectorAll('.edit-inventory-row').forEach(function (button) {
        button.addEventListener('click', function () {
            const editUrl = button.dataset.editUrl;

            if (!editUrl) {
                return;
            }

            clearFormFeedback();

            fetch(editUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data.ok) {
                        window.alert(result.data.message || 'Could not load row for editing.');
                        return;
                    }

                    const data = result.data;
                    form.action = editUrl;
                    setFormModeEdit();
                    modalTitle.textContent = 'Edit Inventory Row';
                    if (sheetInput) sheetInput.value = data.sheet_name || '';
                    if (rackInput) rackInput.value = data.rack || '';
                    if (binInput) binInput.value = data.bin || '';
                    if (skuInput) skuInput.value = data.sku || '';
                    if (nameInput) nameInput.value = data.name || '';
                    if (descriptionInput) descriptionInput.value = data.description || '';
                    if (quantityInput) quantityInput.value = String(data.quantity ?? 0);
                    if (isMainInput) isMainInput.checked = !!data.is_main_sku;

                    showInventoryModal();
                })
                .catch(function () {
                    window.alert('Could not load row for editing.');
                });
        });
    });
})();

(function () {
    function formatQuantity(value) {
        return Number(value || 0).toLocaleString();
    }

    function updateNet32Badge(row, exists) {
        const wrap = row.querySelector('[data-net32-badge="sku"]');

        if (!wrap) {
            return;
        }

        if (exists === true) {
            wrap.innerHTML = '<span class="badge text-bg-success">In Net32</span>';
        } else if (exists === false) {
            wrap.innerHTML = '<span class="badge text-bg-danger">Not in Net32</span>';
        } else {
            wrap.innerHTML = '<span class="badge text-bg-secondary">Not checked</span>';
        }
    }

    function showCheckQtyFeedback(button, message, isError) {
        const feedback = button.closest('td')?.querySelector('.check-qty-feedback');

        if (!feedback) {
            return;
        }

        feedback.hidden = false;
        feedback.className = 'check-qty-feedback small text-end ' + (isError ? 'text-danger' : 'text-success');
        feedback.textContent = message;
    }

    function updateShipStationBadge(row, data) {
        const wrap = row.querySelector('[data-shipstation-badge="sku"]');

        if (!wrap) {
            return;
        }

        const exists = data.shipstation_exists;
        const warehouse = (data.shipstation_warehouse || '').trim();
        const location = (data.shipstation_location || '').trim();
        const locationMatches = data.location_matches;
        const inConfiguredWarehouse = data.in_configured_warehouse;
        const detailParts = [];

        if (warehouse) {
            detailParts.push(warehouse);
        }

        if (location) {
            detailParts.push(location);
        }

        const detailText = detailParts.join(' · ');
        let html = '';

        if (exists === true) {
            html += '<span class="badge text-bg-success">In ShipStation</span>';
        } else if (exists === false) {
            html += '<span class="badge text-bg-danger">Not in ShipStation</span>';
        } else {
            html += '<span class="badge text-bg-secondary">Not checked</span>';
        }

        if (detailText) {
            html += '<span class="small text-muted" data-shipstation-detail-text>' + escapeHtml(detailText) + '</span>';

            if (inConfiguredWarehouse === false) {
                html += '<span class="badge text-bg-warning">Wrong warehouse</span>';
            } else if (locationMatches === false) {
                html += '<span class="badge text-bg-warning">Location mismatch</span>';
            } else if (locationMatches === true) {
                html += '<span class="badge text-bg-light border text-success">Location match</span>';
            }
        } else {
            html += '<span class="small text-muted" data-shipstation-detail-text hidden>—</span>';
        }

        wrap.innerHTML = html;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    document.querySelectorAll('.sync-shipstation-location').forEach(function (button) {
        button.addEventListener('click', function () {
            const syncUrl = button.dataset.syncUrl;
            const row = button.closest('tr');

            if (!syncUrl || !row) {
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

            fetch(syncUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};

                    if (typeof data.shipstation_exists === 'boolean') {
                        updateShipStationBadge(row, data);
                    }

                    showCheckQtyFeedback(button, data.message || 'ShipStation sync finished.', !result.ok);
                })
                .catch(function () {
                    showCheckQtyFeedback(button, 'Could not sync ShipStation location. Try again.', true);
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        });
    });

    document.querySelectorAll('.delete-inventory-row').forEach(function (button) {
        button.addEventListener('click', function () {
            const deleteUrl = button.dataset.deleteUrl;
            const sku = button.dataset.sku || 'this SKU';
            const row = button.closest('tr');

            if (!deleteUrl || !row) {
                return;
            }

            const confirmed = window.confirm(
                'Remove ' + sku + ' from inventory?\n\n'
                + 'This only deletes the row from inventory — the Google Sheet is not changed. '
                + 'If the SKU is still on the sheet, reconcile may add it back.',
            );

            if (!confirmed) {
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

            fetch(deleteUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};

                    if (!result.ok) {
                        showCheckQtyFeedback(button, data.message || 'Could not delete this row.', true);

                        return;
                    }

                    window.location.reload();
                })
                .catch(function () {
                    showCheckQtyFeedback(button, 'Could not delete this row. Try again.', true);
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        });
    });

    document.querySelectorAll('.check-inventory-qty').forEach(function (button) {
        button.addEventListener('click', function () {
            const checkUrl = button.dataset.checkUrl;
            const row = button.closest('tr');

            if (!checkUrl || !row) {
                return;
            }

            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

            fetch(checkUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const data = result.data || {};
                    const quantityCell = row.querySelector('.inventory-quantity');

                    if (quantityCell && typeof data.quantity === 'number') {
                        quantityCell.textContent = formatQuantity(data.quantity);
                    }

                    if (typeof data.sku_net32_exists === 'boolean') {
                        updateNet32Badge(row, data.sku_net32_exists);
                    }

                    showCheckQtyFeedback(button, data.message || 'Quantity check finished.', !result.ok);
                })
                .catch(function () {
                    showCheckQtyFeedback(button, 'Could not check quantity. Try again.', true);
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
        });
    });
})();

(function () {
    const pipelineOptions = document.getElementById('import-pipeline-options');
    const reconcileMode = document.getElementById('import-mode-reconcile');
    const importMode = document.getElementById('import-mode-import');
    const net32Checkbox = document.getElementById('import-with-net32');
    const shipstationCheckbox = document.getElementById('import-with-shipstation');

    function syncPipelineOptionsVisibility() {
        const show = reconcileMode?.checked ?? true;

        if (pipelineOptions) {
            pipelineOptions.classList.toggle('d-none', !show);
        }

        if (net32Checkbox) {
            net32Checkbox.disabled = !show;
        }

        if (shipstationCheckbox) {
            shipstationCheckbox.disabled = !show;
        }
    }

    reconcileMode?.addEventListener('change', syncPipelineOptionsVisibility);
    importMode?.addEventListener('change', syncPipelineOptionsVisibility);
    syncPipelineOptionsVisibility();
})();

document.getElementById('sheets-sync-form')?.addEventListener('submit', function () {
    const btn = document.getElementById('sheets-sync-submit');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Starting...';
});

document.getElementById('qty-sync-form')?.addEventListener('submit', function () {
    const btn = document.getElementById('qty-sync-submit');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Starting...';
});

document.getElementById('shipstation-check-form')?.addEventListener('submit', function () {
    const btn = document.getElementById('shipstation-check-submit');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Starting...';
});

(function () {
    const panel = document.getElementById('import-status-panel');
    const completePanel = document.getElementById('import-complete-panel');
    const progressBar = document.getElementById('import-progress-bar');
    const progressLabel = document.getElementById('import-progress-label');
    const progressRemaining = document.getElementById('import-progress-remaining');
    const statusBadge = document.getElementById('import-status-badge');
    const statusCounts = document.getElementById('import-status-counts');
    const statusMessage = document.getElementById('import-status-message');
    const completeMessage = document.getElementById('import-complete-message');
    const completeTitle = document.getElementById('import-complete-title');
    const importBtn = document.getElementById('sheets-sync-btn');
    const importSubmit = document.getElementById('sheets-sync-submit');
    const importCancelBtn = document.getElementById('import-cancel-btn');
    let pollTimer = null;
    let cancelRequested = false;
    let activeJobId = importJobIdFromUrl > 0
        ? importJobIdFromUrl
        : (importInitialStatus?.job_id ?? null);

    function setImportControlsDisabled(disabled) {
        if (importBtn) importBtn.disabled = disabled;
        if (importSubmit) importSubmit.disabled = disabled;

        const menuBtn = document.getElementById('google-sheets-menu-btn');

        if (menuBtn) {
            menuBtn.disabled = disabled;
        }
    }

    function setCancelButtonState(status) {
        if (!importCancelBtn) {
            return;
        }

        const canCancel = !!status?.can_cancel && !cancelRequested;
        importCancelBtn.classList.toggle('d-none', !status?.is_active);
        importCancelBtn.disabled = !canCancel;

        if (status?.cancel_requested || cancelRequested) {
            importCancelBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Stopping...';
        } else {
            importCancelBtn.innerHTML = '<i class="bi bi-x-circle"></i> Cancel Import';
        }
    }

    function resolveImportAlertClass(status) {
        if (status === 'completed') {
            return 'success';
        }

        if (status === 'cancelled') {
            return 'warning';
        }

        return 'danger';
    }

    function resolveImportBadgeClass(status) {
        if (status === 'failed') {
            return 'danger';
        }

        if (status === 'completed') {
            return 'success';
        }

        if (status === 'cancelled') {
            return 'secondary';
        }

        if (status === 'queued') {
            return 'info';
        }

        return 'warning';
    }

    function resolveImportProgress(status) {
        const pipelinePhase = status.pipeline_phase || '';
        const isPipelinePhase = pipelinePhase === 'net32' || pipelinePhase === 'shipstation';
        const total = Number(status.total) || 0;
        const scanned = Number(status.scanned) || 0;
        const isReconcile = status.import_mode === 'reconcile';

        if (total <= 0) {
            let preparingLabel = status.status === 'queued'
                ? 'Preparing sync...'
                : 'Reading Google Sheets...';

            if (pipelinePhase === 'net32') {
                preparingLabel = 'Starting Net32 quantity sync...';
            } else if (pipelinePhase === 'shipstation') {
                preparingLabel = 'Starting ShipStation location sync...';
            }

            return {
                completePercent: 0,
                remainingPercent: 100,
                remainingCount: 0,
                label: preparingLabel,
            };
        }

        let completePercent = status.percent;

        if (completePercent === null || completePercent === undefined) {
            completePercent = Math.floor((scanned / total) * 100);
        }

        if (scanned >= total) {
            completePercent = 100;
        } else if (scanned > 0 && completePercent === 0) {
            completePercent = 1;
        }

        completePercent = Math.max(0, Math.min(100, completePercent));
        const remainingPercent = Math.max(0, 100 - completePercent);
        const remainingCount = Math.max(0, total - scanned);
        let statsSuffix = '';
        let phasePrefix = '';

        if (isPipelinePhase) {
            phasePrefix = pipelinePhase === 'net32'
                ? 'Net32 quantities — '
                : 'ShipStation locations — ';
        }

        if (isReconcile && !isPipelinePhase) {
            statsSuffix = ' — added ' + (Number(status.added) || 0)
                + ', updated ' + (Number(status.updated) || 0)
                + ', removed ' + (Number(status.removed) || 0)
                + ', unchanged ' + (Number(status.unchanged) || 0)
                + ', skipped (new, not in Net32) ' + (Number(status.ignored) || 0);
        } else if (pipelinePhase === 'net32') {
            statsSuffix = ' — updated ' + (Number(status.net32_updated) || 0)
                + ', not found in Net32 ' + (Number(status.net32_missing) || 0);
        } else if (pipelinePhase === 'shipstation') {
            statsSuffix = ' — moved ' + (Number(status.shipstation_synced) || 0);
        } else if (!isReconcile) {
            statsSuffix = ' — imported ' + (Number(status.imported) || 0);
        }

        return {
            completePercent: completePercent,
            remainingPercent: remainingPercent,
            remainingCount: remainingCount,
            label: phasePrefix + completePercent + '% complete · ' + remainingPercent + '% remaining'
                + ' (' + scanned + ' of ' + total + ' SKUs, ' + remainingCount + ' left)'
                + statsSuffix,
        };
    }

    function setProgress(status) {
        if (!status || !panel || status.status === 'none') {
            return;
        }

        const progress = resolveImportProgress(status);
        const isPreparing = (Number(status.total) || 0) <= 0 && status.is_active;

        if (progressBar) {
            if (isPreparing) {
                progressBar.style.width = '0%';
                progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
                progressBar.parentElement?.setAttribute('aria-valuenow', '0');
            } else {
                progressBar.style.width = progress.completePercent + '%';
                progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
                progressBar.parentElement?.setAttribute('aria-valuenow', String(progress.completePercent));
            }
        }

        if (progressLabel) {
            progressLabel.textContent = isPreparing
                ? 'Preparing...'
                : progress.completePercent + '% complete';
        }

        if (progressRemaining) {
            progressRemaining.textContent = isPreparing
                ? ''
                : progress.remainingPercent + '% remaining';
        }

        statusBadge.textContent = status.status;
        statusBadge.className = 'badge text-bg-' + resolveImportBadgeClass(status.status);

        if (statusCounts) {
            statusCounts.textContent = progress.label;
        }

        statusMessage.textContent = status.progress_message || 'Working...';
        setCancelButtonState(status);

        if (status.is_active) {
            panel.classList.remove('d-none');
            completePanel.classList.add('d-none');
            setImportControlsDisabled(true);
        }
    }

    function showComplete(status) {
        if (!completePanel || !panel) {
            return;
        }

        panel.classList.add('d-none');
        completePanel.classList.remove('d-none');
        completePanel.className = 'alert alert-' + resolveImportAlertClass(status.status)
            + ' alert-dismissible fade show';

        let message = status.progress_message || (
            status.status === 'cancelled'
                ? (status.import_mode === 'reconcile' ? 'Reconcile cancelled.' : 'Import cancelled.')
                : (status.import_mode === 'reconcile' ? 'Reconcile finished.' : 'Import finished.')
        );

        if (completeTitle) {
            if (status.status === 'cancelled') {
                completeTitle.textContent = status.import_mode === 'reconcile' ? 'Sync cancelled.' : 'Import cancelled.';
            } else if (status.status === 'completed') {
                completeTitle.textContent = status.import_mode === 'reconcile'
                    ? ((status.with_net32 || status.with_shipstation) ? 'Full sync finished.' : 'Reconcile finished.')
                    : 'Import finished.';
            } else {
                completeTitle.textContent = status.import_mode === 'reconcile' ? 'Sync failed.' : 'Import failed.';
            }
        }

        if (Array.isArray(status.discovered_sheets) && status.discovered_sheets.length > 0) {
            message += ' New sheet tab(s) added to the dropdown: ' + status.discovered_sheets.join(', ') + '.';
        }

        completeMessage.textContent = message;
        updateSheetNameSelects(status.sheet_names || inventorySheetNames);
        setImportControlsDisabled(false);
        setCancelButtonState(status);
        cancelRequested = false;
        scheduleAlertDismiss(completePanel, ALERT_DISMISS_MS);

        if (status.job_id) {
            sessionStorage.setItem('inventory-import-complete-' + status.job_id, '1');
        }
    }

    function updateSheetNameSelects(names) {
        if (!Array.isArray(names) || names.length === 0) {
            return;
        }

        const filterSelect = document.getElementById('sheet');
        const selectedFilter = filterSelect?.value || '';
        const importSelect = document.getElementById('import-sheet-name');
        const selectedImport = importSelect?.value || '';
        const qtySyncSelect = document.getElementById('qty-sync-sheet-name');
        const selectedQtySync = qtySyncSelect?.value || '';
        const shipStationCheckSelect = document.getElementById('shipstation-check-sheet-name');
        const selectedShipStationCheck = shipStationCheckSelect?.value || '';
        const datalist = document.getElementById('inventory-sheet-options');

        if (filterSelect) {
            filterSelect.innerHTML = '<option value="">All sheets</option>';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                if (name === selectedFilter) {
                    option.selected = true;
                }
                filterSelect.appendChild(option);
            });
        }

        if (importSelect) {
            importSelect.innerHTML = '<option value="">Choose a sheet...</option><option value="*">All sheets</option>';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                if (name === selectedImport) {
                    option.selected = true;
                }
                importSelect.appendChild(option);
            });
        }

        if (qtySyncSelect) {
            qtySyncSelect.innerHTML = '<option value="">Choose a sheet...</option>';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                if (name === selectedQtySync) {
                    option.selected = true;
                }
                qtySyncSelect.appendChild(option);
            });
        }

        if (shipStationCheckSelect) {
            shipStationCheckSelect.innerHTML = '<option value="">Choose a sheet...</option><option value="*">All sheets</option>';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                if (name === selectedShipStationCheck) {
                    option.selected = true;
                }
                shipStationCheckSelect.appendChild(option);
            });
        }

        if (datalist) {
            datalist.innerHTML = '';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                datalist.appendChild(option);
            });
        }
    }

    function cancelImportJob() {
        if (!activeJobId || cancelRequested) {
            return;
        }

        if (!window.confirm('Cancel this inventory import? SKUs already imported will stay in the database.')) {
            return;
        }

        cancelRequested = true;
        setCancelButtonState({ is_active: true, can_cancel: false, cancel_requested: true });

        const body = new URLSearchParams();
        body.set(importCsrfName, importCsrfHash);
        body.set('job_id', String(activeJobId));

        fetch(importCancelUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                const data = result.data || {};

                if (!result.ok || !data.ok) {
                    cancelRequested = false;
                    window.alert(data.message || 'Could not cancel the import.');
                    setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
                    return;
                }

                if (data.status === 'cancelled') {
                    cancelRequested = false;
                    poll();
                    return;
                }

                if (statusMessage) {
                    statusMessage.textContent = data.message || 'Stopping import...';
                }

                poll();
            })
            .catch(function () {
                cancelRequested = false;
                window.alert('Could not cancel the import. Please try again.');
                setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
            });
    }

    importCancelBtn?.addEventListener('click', cancelImportJob);

    function poll() {
        const url = activeJobId ? importStatusUrl + '?job_id=' + activeJobId : importStatusUrl;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (status) {
                if (!status || status.status === 'none') {
                    clearInterval(pollTimer);
                    setImportControlsDisabled(false);
                    setCancelButtonState(null);
                    cancelRequested = false;
                    return;
                }

                activeJobId = status.job_id;
                setProgress(status);
                updateSheetNameSelects(status.sheet_names || inventorySheetNames);

                if (!status.is_active) {
                    clearInterval(pollTimer);
                    showComplete(status);
                }
            })
            .catch(function () {
                clearInterval(pollTimer);
                setImportControlsDisabled(false);
                setCancelButtonState(null);
                cancelRequested = false;
            });
    }

    if ((importInitialStatus && importInitialStatus.is_active) || importJobIdFromUrl > 0) {
        if (importInitialStatus) {
            setProgress(importInitialStatus);
        } else if (panel) {
            panel.classList.remove('d-none');
            if (progressLabel) {
                progressLabel.textContent = 'Preparing...';
            }
            if (statusCounts) {
                statusCounts.textContent = 'Starting import...';
            }
            setImportControlsDisabled(true);
        }

        poll();
        pollTimer = setInterval(poll, 2000);
    } else if (
        importInitialStatus
        && !importInitialStatus.is_active
        && importInitialStatus.finished_at
        && importInitialStatus.job_id
        && !sessionStorage.getItem('inventory-import-complete-' + importInitialStatus.job_id)
    ) {
        const finishedAt = new Date(String(importInitialStatus.finished_at).replace(' ', 'T'));
        if (!Number.isNaN(finishedAt.getTime()) && (Date.now() - finishedAt.getTime()) < 15000) {
            showComplete(importInitialStatus);
        }
    }
})();
</script>

<script>
(function () {
    const panel = document.getElementById('qty-sync-status-panel');
    const completePanel = document.getElementById('qty-sync-complete-panel');
    const progressBar = document.getElementById('qty-sync-progress-bar');
    const progressLabel = document.getElementById('qty-sync-progress-label');
    const progressRemaining = document.getElementById('qty-sync-progress-remaining');
    const statusBadge = document.getElementById('qty-sync-status-badge');
    const statusCounts = document.getElementById('qty-sync-status-counts');
    const statusMessage = document.getElementById('qty-sync-status-message');
    const completeMessage = document.getElementById('qty-sync-complete-message');
    const completeTitle = document.getElementById('qty-sync-complete-title');
    const qtySyncBtn = document.getElementById('qty-sync-btn');
    const qtySyncSubmit = document.getElementById('qty-sync-submit');
    const qtySyncCancelBtn = document.getElementById('qty-sync-cancel-btn');
    let pollTimer = null;
    let cancelRequested = false;
    let activeJobId = qtySyncJobIdFromUrl > 0
        ? qtySyncJobIdFromUrl
        : (qtySyncInitialStatus?.job_id ?? null);

    function setQtySyncControlsDisabled(disabled) {
        if (qtySyncBtn) qtySyncBtn.disabled = disabled;
        if (qtySyncSubmit) qtySyncSubmit.disabled = disabled;

        const menuBtn = document.getElementById('sync-check-menu-btn');
        const checkDisabled = document.getElementById('shipstation-check-btn')?.disabled ?? false;

        if (menuBtn) {
            menuBtn.disabled = disabled && checkDisabled;
        }
    }

    function updateLastNet32QtySyncDisplay(status) {
        const element = document.getElementById('last-net32-qty-sync-at');

        if (!element || !status?.last_net32_qty_sync_at_display) {
            return;
        }

        element.textContent = status.last_net32_qty_sync_at_display;
    }

    function setCancelButtonState(status) {
        if (!qtySyncCancelBtn) {
            return;
        }

        const canCancel = !!status?.can_cancel && !cancelRequested;
        const forceStop = !!status?.cancel_requested || cancelRequested;
        qtySyncCancelBtn.classList.toggle('d-none', !status?.is_active);
        qtySyncCancelBtn.disabled = !status?.is_active || (!canCancel && !forceStop);
        qtySyncCancelBtn.dataset.forceStop = forceStop ? '1' : '0';

        if (forceStop) {
            qtySyncCancelBtn.innerHTML = '<i class="bi bi-x-octagon"></i> Force Stop Sync';
        } else {
            qtySyncCancelBtn.innerHTML = '<i class="bi bi-x-circle"></i> Cancel Sync';
        }
    }

    function resolveQtySyncAlertClass(status) {
        if (status === 'completed') {
            return 'success';
        }

        if (status === 'cancelled') {
            return 'warning';
        }

        return 'danger';
    }

    function resolveQtySyncBadgeClass(status) {
        if (status === 'failed') {
            return 'danger';
        }

        if (status === 'completed') {
            return 'success';
        }

        if (status === 'cancelled') {
            return 'secondary';
        }

        if (status === 'queued') {
            return 'info';
        }

        return 'warning';
    }

    function resolveQtySyncProgress(status) {
        const total = Number(status.total) || 0;
        const processed = Number(status.processed) || 0;

        if (total <= 0) {
            return {
                completePercent: 0,
                remainingPercent: 100,
                label: status.status === 'queued' ? 'Preparing quantity sync...' : 'Starting Net32 checks...',
            };
        }

        let completePercent = status.percent;

        if (completePercent === null || completePercent === undefined) {
            completePercent = Math.floor((processed / total) * 100);
        }

        if (processed >= total) {
            completePercent = 100;
        } else if (processed > 0 && completePercent === 0) {
            completePercent = 1;
        }

        completePercent = Math.max(0, Math.min(100, completePercent));
        const remainingPercent = Math.max(0, 100 - completePercent);

        return {
            completePercent: completePercent,
            remainingPercent: remainingPercent,
            label: completePercent + '% complete · ' + remainingPercent + '% remaining'
                + ' (' + processed + ' of ' + total + ' SKUs)'
                + ' — updated ' + (Number(status.updated) || 0)
                + ', unchanged ' + (Number(status.unchanged) || 0)
                + ', not in Net32 ' + (Number(status.missing) || 0),
        };
    }

    function setProgress(status) {
        if (!status || !panel || status.status === 'none') {
            return;
        }

        const progress = resolveQtySyncProgress(status);
        const isPreparing = (Number(status.total) || 0) <= 0 && status.is_active;

        if (progressBar) {
            if (isPreparing) {
                progressBar.style.width = '0%';
                progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
                progressBar.parentElement?.setAttribute('aria-valuenow', '0');
            } else {
                progressBar.style.width = progress.completePercent + '%';
                progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
                progressBar.parentElement?.setAttribute('aria-valuenow', String(progress.completePercent));
            }
        }

        if (progressLabel) {
            progressLabel.textContent = isPreparing
                ? 'Preparing...'
                : progress.completePercent + '% complete';
        }

        if (progressRemaining) {
            progressRemaining.textContent = isPreparing
                ? ''
                : progress.remainingPercent + '% remaining';
        }

        statusBadge.textContent = status.status;
        statusBadge.className = 'badge text-bg-' + resolveQtySyncBadgeClass(status.status);

        if (statusCounts) {
            statusCounts.textContent = progress.label;
        }

        statusMessage.textContent = status.progress_message || 'Checking Net32 quantities...';
        setCancelButtonState(status);
        updateLastNet32QtySyncDisplay(status);

        if (status.is_active) {
            panel.classList.remove('d-none');
            completePanel.classList.add('d-none');
            setQtySyncControlsDisabled(true);
        }
    }

    function showComplete(status) {
        if (!completePanel || !panel) {
            return;
        }

        panel.classList.add('d-none');
        completePanel.classList.remove('d-none');
        completePanel.className = 'alert alert-' + resolveQtySyncAlertClass(status.status)
            + ' alert-dismissible fade show';

        const message = status.progress_message || (
            status.status === 'cancelled' ? 'Quantity sync cancelled.' : 'Quantity sync finished.'
        );

        if (completeTitle) {
            completeTitle.textContent = status.status === 'cancelled'
                ? 'Quantity sync cancelled.'
                : 'Quantity sync finished.';
        }

        completeMessage.textContent = message;
        updateLastNet32QtySyncDisplay(status);
        setQtySyncControlsDisabled(false);
        setCancelButtonState(null);
        cancelRequested = false;

        if (status.job_id) {
            sessionStorage.setItem('inventory-qty-sync-complete-' + status.job_id, '1');
        }
    }

    function cancelQtySyncJob() {
        if (!activeJobId) {
            return;
        }

        const isForceStop = qtySyncCancelBtn?.dataset.forceStop === '1';

        const confirmMessage = isForceStop
            ? 'Force stop this quantity sync now? It will be marked cancelled immediately.'
            : 'Cancel this Net32 quantity sync? Quantities already updated will stay as they are.';

        if (!window.confirm(confirmMessage)) {
            return;
        }

        if (!isForceStop) {
            cancelRequested = true;
        }

        setCancelButtonState({ is_active: true, can_cancel: false, cancel_requested: true });

        const body = new URLSearchParams();
        body.set(importCsrfName, importCsrfHash);
        body.set('job_id', String(activeJobId));

        fetch(qtySyncCancelUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                const data = result.data || {};

                if (!result.ok || !data.ok) {
                    cancelRequested = false;
                    window.alert(data.message || 'Could not cancel the quantity sync.');
                    setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
                    return;
                }

                if (data.status === 'cancelled') {
                    cancelRequested = false;
                    poll();
                    return;
                }

                setCancelButtonState({ is_active: true, can_cancel: false, cancel_requested: true });
                poll();
            })
            .catch(function () {
                cancelRequested = false;
                window.alert('Could not cancel the quantity sync. Please try again.');
                setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
            });
    }

    qtySyncCancelBtn?.addEventListener('click', cancelQtySyncJob);

    function poll() {
        const url = activeJobId ? qtySyncStatusUrl + '?job_id=' + activeJobId : qtySyncStatusUrl;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (status) {
                if (!status || status.status === 'none') {
                    clearInterval(pollTimer);
                    setQtySyncControlsDisabled(false);
                    setCancelButtonState(null);
                    cancelRequested = false;
                    return;
                }

                activeJobId = status.job_id;
                setProgress(status);

                if (!status.is_active) {
                    clearInterval(pollTimer);
                    showComplete(status);
                }
            })
            .catch(function () {
                clearInterval(pollTimer);
                setQtySyncControlsDisabled(false);
                setCancelButtonState(null);
                cancelRequested = false;
            });
    }

    if ((qtySyncInitialStatus && qtySyncInitialStatus.is_active) || qtySyncJobIdFromUrl > 0) {
        if (qtySyncInitialStatus) {
            setProgress(qtySyncInitialStatus);
        } else if (panel) {
            panel.classList.remove('d-none');
            if (progressLabel) {
                progressLabel.textContent = 'Preparing...';
            }
            if (statusCounts) {
                statusCounts.textContent = 'Starting quantity sync...';
            }
            setQtySyncControlsDisabled(true);
        }

        poll();
        pollTimer = setInterval(poll, 2000);
    } else if (
        qtySyncInitialStatus
        && !qtySyncInitialStatus.is_active
        && qtySyncInitialStatus.finished_at
        && qtySyncInitialStatus.job_id
        && !sessionStorage.getItem('inventory-qty-sync-complete-' + qtySyncInitialStatus.job_id)
    ) {
        const finishedAt = new Date(String(qtySyncInitialStatus.finished_at).replace(' ', 'T'));
        if (!Number.isNaN(finishedAt.getTime()) && (Date.now() - finishedAt.getTime()) < 15000) {
            showComplete(qtySyncInitialStatus);
        }
    }
})();
</script>

<script>
(function () {
    const panel = document.getElementById('shipstation-check-status-panel');
    const completePanel = document.getElementById('shipstation-check-complete-panel');
    const progressBar = document.getElementById('shipstation-check-progress-bar');
    const progressLabel = document.getElementById('shipstation-check-progress-label');
    const progressRemaining = document.getElementById('shipstation-check-progress-remaining');
    const statusBadge = document.getElementById('shipstation-check-status-badge');
    const statusCounts = document.getElementById('shipstation-check-status-counts');
    const statusMessage = document.getElementById('shipstation-check-status-message');
    const completeMessage = document.getElementById('shipstation-check-complete-message');
    const completeTitle = document.getElementById('shipstation-check-complete-title');
    const checkBtn = document.getElementById('shipstation-check-btn');
    const checkSubmit = document.getElementById('shipstation-check-submit');
    const checkCancelBtn = document.getElementById('shipstation-check-cancel-btn');
    let pollTimer = null;
    let cancelRequested = false;
    let activeJobId = shipStationCheckJobIdFromUrl > 0
        ? shipStationCheckJobIdFromUrl
        : (shipStationCheckInitialStatus?.job_id ?? null);

    function setCheckControlsDisabled(disabled) {
        if (checkBtn) checkBtn.disabled = disabled;
        if (checkSubmit) checkSubmit.disabled = disabled;

        const menuBtn = document.getElementById('sync-check-menu-btn');
        const qtyDisabled = document.getElementById('qty-sync-btn')?.disabled ?? false;

        if (menuBtn) {
            menuBtn.disabled = disabled && qtyDisabled;
        }
    }

    function updateLastShipStationCheckDisplay(status) {
        const element = document.getElementById('last-shipstation-check-at');

        if (!element || !status?.last_shipstation_check_at_display) {
            return;
        }

        element.textContent = status.last_shipstation_check_at_display;
    }

    function setCancelButtonState(status) {
        if (!checkCancelBtn) {
            return;
        }

        const canCancel = !!status?.can_cancel && !cancelRequested;
        const forceStop = !!status?.cancel_requested || cancelRequested;
        checkCancelBtn.classList.toggle('d-none', !status?.is_active);
        checkCancelBtn.disabled = !status?.is_active || (!canCancel && !forceStop);
        checkCancelBtn.dataset.forceStop = forceStop ? '1' : '0';
        checkCancelBtn.innerHTML = forceStop
            ? '<i class="bi bi-x-octagon"></i> Force Stop Sync'
            : '<i class="bi bi-x-circle"></i> Cancel Sync';
    }

    function resolveProgress(status) {
        const total = Number(status.total) || 0;
        const processed = Number(status.processed) || 0;

        if (total <= 0) {
            return {
                completePercent: 0,
                remainingPercent: 100,
                label: status.status === 'queued' ? 'Preparing location sync...' : 'Starting ShipStation sync...',
            };
        }

        let completePercent = status.percent;

        if (completePercent === null || completePercent === undefined) {
            completePercent = Math.floor((processed / total) * 100);
        }

        if (processed >= total) {
            completePercent = 100;
        } else if (processed > 0 && completePercent === 0) {
            completePercent = 1;
        }

        completePercent = Math.max(0, Math.min(100, completePercent));

        return {
            completePercent: completePercent,
            remainingPercent: Math.max(0, 100 - completePercent),
            label: completePercent + '% complete · ' + Math.max(0, 100 - completePercent) + '% remaining'
                + ' (' + processed + ' of ' + total + ' SKUs)'
                + ' — moved ' + (Number(status.synced) || 0)
                + ', match ' + (Number(status.matched) || 0)
                + ', mismatch ' + (Number(status.mismatched) || 0)
                + ', missing ' + (Number(status.missing) || 0)
                + ', empty ' + (Number(status.empty_location) || 0),
        };
    }

    function resolveAlertClass(status) {
        if (status === 'completed') return 'success';
        if (status === 'cancelled') return 'warning';
        return 'danger';
    }

    function resolveBadgeClass(status) {
        if (status === 'failed') return 'danger';
        if (status === 'completed') return 'success';
        if (status === 'cancelled') return 'secondary';
        if (status === 'queued') return 'info';
        return 'info';
    }

    function setProgress(status) {
        if (!status || !panel || status.status === 'none') {
            return;
        }

        const progress = resolveProgress(status);
        const isPreparing = (Number(status.total) || 0) <= 0 && status.is_active;

        if (progressBar) {
            progressBar.style.width = isPreparing ? '0%' : progress.completePercent + '%';
            progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
            progressBar.parentElement?.setAttribute('aria-valuenow', isPreparing ? '0' : String(progress.completePercent));
        }

        if (progressLabel) {
            progressLabel.textContent = isPreparing ? 'Preparing...' : progress.completePercent + '% complete';
        }

        if (progressRemaining) {
            progressRemaining.textContent = isPreparing ? '' : progress.remainingPercent + '% remaining';
        }

        statusBadge.textContent = status.status;
        statusBadge.className = 'badge text-bg-' + resolveBadgeClass(status.status);

        if (statusCounts) {
            statusCounts.textContent = progress.label;
        }

        statusMessage.textContent = status.progress_message || 'Syncing ShipStation locations...';
        setCancelButtonState(status);
        updateLastShipStationCheckDisplay(status);

        if (status.is_active) {
            panel.classList.remove('d-none');
            completePanel.classList.add('d-none');
            setCheckControlsDisabled(true);
        }
    }

    function showComplete(status) {
        if (!completePanel || !panel) {
            return;
        }

        panel.classList.add('d-none');
        completePanel.classList.remove('d-none');
        completePanel.className = 'alert alert-' + resolveAlertClass(status.status) + ' alert-dismissible fade show';

        if (completeTitle) {
            completeTitle.textContent = status.status === 'cancelled'
                ? 'ShipStation location sync cancelled.'
                : 'ShipStation location sync finished.';
        }

        completeMessage.textContent = status.progress_message || completeTitle.textContent;
        updateLastShipStationCheckDisplay(status);
        setCheckControlsDisabled(false);
        setCancelButtonState(null);
        cancelRequested = false;

        if (status.job_id) {
            sessionStorage.setItem('inventory-shipstation-check-complete-' + status.job_id, '1');
        }

        if (status.status === 'completed') {
            window.setTimeout(function () {
                window.location.reload();
            }, 1500);
        }
    }

    function cancelCheckJob() {
        if (!activeJobId) {
            return;
        }

        const isForceStop = checkCancelBtn?.dataset.forceStop === '1';
        const confirmMessage = isForceStop
            ? 'Force stop this ShipStation location sync now?'
            : 'Cancel this ShipStation location sync? Rows already synced will stay updated.';

        if (!window.confirm(confirmMessage)) {
            return;
        }

        if (!isForceStop) {
            cancelRequested = true;
        }

        setCancelButtonState({ is_active: true, can_cancel: false, cancel_requested: true });

        const body = new URLSearchParams();
        body.set(importCsrfName, importCsrfHash);
        body.set('job_id', String(activeJobId));

        fetch(shipStationCheckCancelUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                const data = result.data || {};

                if (!result.ok || !data.ok) {
                    cancelRequested = false;
                    window.alert(data.message || 'Could not cancel the location check.');
                    setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
                    return;
                }

                if (data.status === 'cancelled') {
                    cancelRequested = false;
                    poll();
                    return;
                }

                setCancelButtonState({ is_active: true, can_cancel: false, cancel_requested: true });
                poll();
            })
            .catch(function () {
                cancelRequested = false;
                window.alert('Could not cancel the location check. Please try again.');
                setCancelButtonState({ is_active: true, can_cancel: true, cancel_requested: false });
            });
    }

    checkCancelBtn?.addEventListener('click', cancelCheckJob);

    function poll() {
        const url = activeJobId ? shipStationCheckStatusUrl + '?job_id=' + activeJobId : shipStationCheckStatusUrl;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (status) {
                if (!status || status.status === 'none') {
                    clearInterval(pollTimer);
                    setCheckControlsDisabled(false);
                    setCancelButtonState(null);
                    cancelRequested = false;
                    return;
                }

                activeJobId = status.job_id;
                setProgress(status);

                if (!status.is_active) {
                    clearInterval(pollTimer);
                    showComplete(status);
                }
            })
            .catch(function () {
                clearInterval(pollTimer);
                setCheckControlsDisabled(false);
                setCancelButtonState(null);
                cancelRequested = false;
            });
    }

    if ((shipStationCheckInitialStatus && shipStationCheckInitialStatus.is_active) || shipStationCheckJobIdFromUrl > 0) {
        if (shipStationCheckInitialStatus) {
            setProgress(shipStationCheckInitialStatus);
        } else if (panel) {
            panel.classList.remove('d-none');
            if (progressLabel) progressLabel.textContent = 'Preparing...';
            if (statusCounts) statusCounts.textContent = 'Starting location check...';
            setCheckControlsDisabled(true);
        }

        poll();
        pollTimer = setInterval(poll, 2000);
    } else if (
        shipStationCheckInitialStatus
        && !shipStationCheckInitialStatus.is_active
        && shipStationCheckInitialStatus.finished_at
        && shipStationCheckInitialStatus.job_id
        && !sessionStorage.getItem('inventory-shipstation-check-complete-' + shipStationCheckInitialStatus.job_id)
    ) {
        const finishedAt = new Date(String(shipStationCheckInitialStatus.finished_at).replace(' ', 'T'));
        if (!Number.isNaN(finishedAt.getTime()) && (Date.now() - finishedAt.getTime()) < 15000) {
            showComplete(shipStationCheckInitialStatus);
        }
    }
})();
</script>

<?= $this->endSection() ?>

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
                <button type="button" class="btn btn-success btn-sm" id="inventory-add-btn">
                    <i class="bi bi-plus-lg"></i> Add Main SKU
                </button>
                <a href="<?= esc($spreadsheetUrl) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                    <i class="bi bi-table"></i> Open Google Sheet
                </a>
                <button type="button" class="btn btn-primary btn-sm" id="sheets-sync-btn" data-bs-toggle="modal" data-bs-target="#import-sheets-modal">
                    <i class="bi bi-arrow-repeat"></i> Import from Google Sheets
                </button>
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
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong id="import-status-title">Inventory import running...</strong>
                <span id="import-status-badge" class="badge text-bg-info">running</span>
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
            <strong>Import finished.</strong>
            <div id="import-complete-message" class="mb-0"></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <div class="info-box">
                    <span class="info-box-icon text-bg-primary"><i class="bi bi-grid-3x3-gap"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inventory Rows</span>
                        <span class="info-box-number"><?= esc(number_format($totalLocations)) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-box">
                    <span class="info-box-icon text-bg-success"><i class="bi bi-clock-history"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Synced</span>
                        <span class="info-box-number" style="font-size: 1rem;">
                            <?= $lastSyncedAt ? esc($lastSyncedAt) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <form method="get" action="<?= site_url('inventory') ?>" class="row g-2 align-items-end">
                    <div class="col-md-3">
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
                    <div class="col-md-2">
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
                    <div class="col-md-2">
                        <label for="net32" class="form-label small text-muted mb-1">Net32 status</label>
                        <select name="net32" id="net32" class="form-select">
                            <option value="">All rows</option>
                            <option value="missing" <?= $net32Filter === 'missing' ? 'selected' : '' ?>>Not in Net32</option>
                            <option value="ok" <?= $net32Filter === 'ok' ? 'selected' : '' ?>>In Net32</option>
                            <option value="unchecked" <?= $net32Filter === 'unchecked' ? 'selected' : '' ?>>Not checked yet</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="qty" class="form-label small text-muted mb-1">Quantity</label>
                        <select name="qty" id="qty" class="form-select">
                            <option value="">All rows</option>
                            <option value="zero" <?= ($quantityFilter ?? '') === 'zero' ? 'selected' : '' ?>>Quantity 0</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <?php if ($perPage !== $defaultPerPage) : ?>
                            <input type="hidden" name="per_page" value="<?= esc($perPage) ?>">
                        <?php endif ?>
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <?php if ($search !== '' || $sheetFilter !== '' || $net32Filter !== '' || ($quantityFilter ?? '') !== '') : ?>
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
                                <th class="text-end" style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($locationGroups === []) : ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
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
                    <h5 class="modal-title" id="import-sheets-modal-label">Import from Google Sheets</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">The import is queued and runs in the background (usually within a minute). Progress appears on the <a href="<?= site_url('logs') ?>">Logs</a> page with status <strong>Running</strong>, then <strong>Completed</strong> or <strong>Failed</strong>.</p>
                    <div class="mb-3">
                        <label class="form-label">Which sheets should be imported?</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="import_scope" id="import-scope-all" value="all" checked>
                            <label class="form-check-label" for="import-scope-all">All sheet tabs</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="import_scope" id="import-scope-sheet" value="sheet">
                            <label class="form-check-label" for="import-scope-sheet">One specific sheet tab</label>
                        </div>
                    </div>
                    <div class="mb-0" id="import-sheet-select-wrap" hidden>
                        <label for="import-sheet-name" class="form-label">Sheet tab</label>
                        <select class="form-select" name="sheet_name" id="import-sheet-name">
                            <option value="">Choose a sheet...</option>
                            <?php foreach ($sheetNames as $name) : ?>
                                <option value="<?= esc($name) ?>"><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="sheets-sync-submit">
                        <i class="bi bi-arrow-repeat"></i> Start Import
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
const importStatusUrl = <?= json_encode(site_url('inventory/import-status')) ?>;
const importJobIdFromUrl = <?= json_encode((int) ($importJobId ?? 0)) ?>;
const inventoryStoreUrl = <?= json_encode(site_url('inventory')) ?>;
const inventoryValidateUrl = <?= json_encode(site_url('inventory/validate')) ?>;
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
        form.dataset.confirmed = '';
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
        form.dataset.confirmed = '';
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

    form.addEventListener('submit', function (event) {
        if (form.dataset.confirmed === '1') {
            form.dataset.confirmed = '';
            return;
        }

        event.preventDefault();

        if (form.dataset.awaitingConfirm === '1') {
            form.dataset.awaitingConfirm = '';
            form.dataset.confirmed = '1';
            form.submit();
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        const formData = new FormData(form);
        const editId = getEditIdFromFormAction();

        if (editId !== '') {
            formData.set('id', editId);
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking...';
        }

        fetch(inventoryValidateUrl, {
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

                if (!result.ok || !data.ok) {
                    showFormError(data.message || 'Could not validate this inventory row.');
                    return;
                }

                if (Array.isArray(data.warnings) && data.warnings.length > 0) {
                    showFormWarnings(data.warnings);
                    return;
                }

                form.dataset.confirmed = '1';
                form.submit();
            })
            .catch(function () {
                showFormError('Could not validate this inventory row. Please try again.');
            })
            .finally(function () {
                if (submitBtn && form.dataset.confirmed !== '1') {
                    submitBtn.disabled = false;

                    if (form.dataset.awaitingConfirm === '1') {
                        submitBtn.textContent = 'Save anyway';
                    } else if (form.dataset.formMode === 'main') {
                        submitBtn.textContent = 'Add Main SKU';
                    } else if (form.dataset.formMode === 'alternate') {
                        submitBtn.textContent = 'Add Alternate SKU';
                    } else {
                        submitBtn.textContent = defaultSubmitLabel;
                    }
                }
            });
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

document.getElementById('sheets-sync-form')?.addEventListener('submit', function () {
    const btn = document.getElementById('sheets-sync-submit');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Starting...';
});

(function () {
    const scopeAll = document.getElementById('import-scope-all');
    const scopeSheet = document.getElementById('import-scope-sheet');
    const sheetWrap = document.getElementById('import-sheet-select-wrap');
    const sheetSelect = document.getElementById('import-sheet-name');

    function syncSheetPicker() {
        const showSheet = scopeSheet?.checked ?? false;
        if (sheetWrap) sheetWrap.hidden = !showSheet;
        if (sheetSelect) sheetSelect.required = showSheet;
    }

    scopeAll?.addEventListener('change', syncSheetPicker);
    scopeSheet?.addEventListener('change', syncSheetPicker);
    syncSheetPicker();
})();

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
    const importBtn = document.getElementById('sheets-sync-btn');
    const importSubmit = document.getElementById('sheets-sync-submit');
    let pollTimer = null;
    let activeJobId = importJobIdFromUrl > 0
        ? importJobIdFromUrl
        : (importInitialStatus?.job_id ?? null);

    function setImportControlsDisabled(disabled) {
        if (importBtn) importBtn.disabled = disabled;
        if (importSubmit) importSubmit.disabled = disabled;
    }

    function resolveImportProgress(status) {
        const total = Number(status.total) || 0;
        const scanned = Number(status.scanned) || 0;

        if (total <= 0) {
            return {
                completePercent: 0,
                remainingPercent: 100,
                remainingCount: 0,
                label: status.status === 'queued' ? 'Preparing import...' : 'Reading Google Sheets...',
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

        return {
            completePercent: completePercent,
            remainingPercent: remainingPercent,
            remainingCount: remainingCount,
            label: completePercent + '% complete · ' + remainingPercent + '% remaining'
                + ' (' + scanned + ' of ' + total + ' SKUs, ' + remainingCount + ' left)',
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
        statusBadge.className = 'badge text-bg-' + (
            status.status === 'failed' ? 'danger' : (status.status === 'completed' ? 'success' : 'info')
        );

        if (statusCounts) {
            statusCounts.textContent = progress.label;
        }

        statusMessage.textContent = status.progress_message || 'Working...';

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
        completePanel.className = 'alert alert-' + (status.status === 'completed' ? 'success' : 'danger')
            + ' alert-dismissible fade show';
        completeMessage.textContent = status.progress_message || 'Import finished.';
        setImportControlsDisabled(false);
        scheduleAlertDismiss(completePanel, ALERT_DISMISS_MS);

        if (status.job_id) {
            sessionStorage.setItem('inventory-import-complete-' + status.job_id, '1');
        }
    }

    function poll() {
        const url = activeJobId ? importStatusUrl + '?job_id=' + activeJobId : importStatusUrl;

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (status) {
                if (!status || status.status === 'none') {
                    clearInterval(pollTimer);
                    setImportControlsDisabled(false);
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
                setImportControlsDisabled(false);
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

<?= $this->endSection() ?>

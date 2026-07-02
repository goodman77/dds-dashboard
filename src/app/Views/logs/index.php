<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Logs</h1>
                <p class="text-muted small mb-0">Activity history logs (times shown in Pacific)</p>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <div class="card">
            <div class="card-header">
                <form method="get" action="<?= site_url('logs') ?>" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="action" class="form-label small text-muted mb-1">Action type</label>
                        <select name="action" id="action" class="form-select">
                            <option value="">All actions</option>
                            <?php foreach ($actionLabels as $value => $label) : ?>
                                <option value="<?= esc($value) ?>" <?= $actionFilter === $value ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="per_page" class="form-label small text-muted mb-1">Per page</label>
                        <select name="per_page" id="per_page" class="form-select">
                            <?php foreach ($perPageOptions as $option) : ?>
                                <option value="<?= esc($option) ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                                    <?= esc($option) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <?php if ($actionFilter !== '' || $perPage !== $defaultPerPage) : ?>
                            <a href="<?= site_url('logs') ?>" class="btn btn-outline-secondary">Clear</a>
                        <?php endif ?>
                    </div>
                </form>
            </div>

            <div class="card-body p-0">
                <?php if ($pager->getTotal($pagerGroup) > 0) : ?>
                    <div class="px-3 py-2 border-bottom text-muted small">
                        Page <?= esc($pager->getCurrentPage($pagerGroup)) ?> of <?= esc($pager->getPageCount($pagerGroup)) ?>
                        &middot; <?= esc(number_format($pager->getTotal($pagerGroup))) ?> entries
                    </div>
                <?php endif ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 190px;">Date (Pacific)</th>
                                <th style="width: 180px;">Action</th>
                                <th style="width: 110px;">Status</th>
                                <th>Message</th>
                                <th style="width: 130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($entries === []) : ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No activity logged yet. Inventory imports and quantity edits will appear here.
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($entries as $entry) : ?>
                                    <?php
                                    $status = (string) ($entry['status'] ?? '');
                                    $statusClass = match ($status) {
                                        'completed' => 'success',
                                        'failed'    => 'danger',
                                        'cancelled' => 'secondary',
                                        'running'   => 'warning',
                                        'queued'    => 'info',
                                        default     => 'secondary',
                                    };
                                    $displayDate = format_log_datetime(
                                        isset($entry['created_at']) ? (string) $entry['created_at'] : null,
                                    );
                                    $canCancel = ! empty($entry['can_cancel']);
                                    $cancelRequested = ! empty($entry['cancel_requested']);
                                    $showCancelControl = $canCancel || $cancelRequested;
                                    $cancelLabel = match ($entry['action'] ?? '') {
                                        'inventory_import' => 'Cancel Import',
                                        'inventory_qty_sync' => 'Cancel Sync',
                                        default            => 'Cancel',
                                    };
                                    $forceStopLabel = match ($entry['action'] ?? '') {
                                        'inventory_import' => 'Force Stop Import',
                                        'inventory_qty_sync' => 'Force Stop Sync',
                                        default            => 'Force Stop',
                                    };
                                    ?>
                                    <tr
                                        data-log-id="<?= esc((string) ($entry['id'] ?? '')) ?>"
                                        <?= ! empty($entry['is_active']) ? 'data-log-active="1"' : '' ?>
                                    >
                                        <td class="small text-nowrap"><?= esc($displayDate) ?></td>
                                        <td><?= esc($actionLabels[$entry['action']] ?? ucwords(str_replace('_', ' ', $entry['action']))) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= esc($statusClass) ?> log-status-badge"><?= esc(ucfirst($status)) ?></span>
                                        </td>
                                        <td class="log-message"><?= esc($entry['message']) ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($showCancelControl) : ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm log-cancel-btn"
                                                    data-action="<?= esc((string) ($entry['action'] ?? '')) ?>"
                                                    data-job-id="<?= esc((string) ($entry['job_id'] ?? '')) ?>"
                                                    data-cancel-label="<?= esc($cancelLabel) ?>"
                                                    data-force-label="<?= esc($forceStopLabel) ?>"
                                                    data-force-stop="<?= $cancelRequested ? '1' : '0' ?>"
                                                >
                                                    <?php if ($cancelRequested) : ?>
                                                        <i class="bi bi-x-octagon"></i> <?= esc($forceStopLabel) ?>
                                                    <?php else : ?>
                                                        <i class="bi bi-x-circle"></i> <?= esc($cancelLabel) ?>
                                                    <?php endif ?>
                                                </button>
                                            <?php else : ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif ?>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            <?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($pager->getTotal($pagerGroup) > 0) : ?>
                <div class="card-footer">
                    <?= $pager->links($pagerGroup, 'bootstrap') ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</section>

<script>
(function () {
    const cancelUrl = <?= json_encode(site_url('logs/cancel-job')) ?>;
    const csrfName = <?= json_encode(csrf_token()) ?>;
    let csrfHash = <?= json_encode(csrf_hash()) ?>;
    let pollTimer = null;

    function resolveCancelMessage(button) {
        const action = button.dataset.action || '';
        const isForceStop = button.dataset.forceStop === '1';

        if (isForceStop) {
            return 'Force stop this job now? It will be marked cancelled immediately.';
        }

        if (action === 'inventory_import') {
            return 'Cancel this inventory import? SKUs already imported will stay in the database.';
        }

        if (action === 'inventory_qty_sync') {
            return 'Cancel this Net32 quantity sync? Quantities already updated will stay as they are.';
        }

        return 'Cancel this job?';
    }

    function setButtonPending(button) {
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working...';
    }

    function setButtonForceStop(button) {
        button.disabled = false;
        button.dataset.forceStop = '1';
        button.innerHTML = '<i class="bi bi-x-octagon"></i> ' + (button.dataset.forceLabel || 'Force Stop');
    }

    function resetButton(button) {
        button.disabled = false;
        button.innerHTML = '<i class="bi bi-x-circle"></i> ' + (button.dataset.cancelLabel || 'Cancel');
    }

    function startPolling() {
        if (pollTimer !== null) {
            return;
        }

        pollTimer = window.setInterval(function () {
            if (document.querySelector('[data-log-active="1"]')) {
                window.location.reload();
            } else {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }, 3000);
    }

    document.querySelectorAll('.log-cancel-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }

            const action = button.dataset.action || '';
            const jobId = button.dataset.jobId || '';

            if (!action || !jobId) {
                return;
            }

            if (!window.confirm(resolveCancelMessage(button))) {
                return;
            }

            setButtonPending(button);

            const body = new URLSearchParams();
            body.set(csrfName, csrfHash);
            body.set('action', action);
            body.set('job_id', jobId);

            fetch(cancelUrl, {
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
                        window.alert(data.message || 'Could not cancel this job.');
                        if (button.dataset.forceStop === '1') {
                            setButtonForceStop(button);
                        } else {
                            resetButton(button);
                        }
                        return;
                    }

                    if (data.status === 'cancelled') {
                        window.location.reload();
                        return;
                    }

                    setButtonForceStop(button);
                    startPolling();
                })
                .catch(function () {
                    window.alert('Could not cancel this job. Please try again.');
                    if (button.dataset.forceStop === '1') {
                        setButtonForceStop(button);
                    } else {
                        resetButton(button);
                    }
                });
        });
    });

    if (document.querySelector('[data-log-active="1"]')) {
        startPolling();
    }
})();
</script>

<?= $this->endSection() ?>

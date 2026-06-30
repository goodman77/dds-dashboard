<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Logs</h1>
                <p class="text-muted small mb-0">Activity history logs</p>
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
                                <th style="width: 170px;">Date</th>
                                <th style="width: 180px;">Action</th>
                                <th style="width: 110px;">Status</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($entries === []) : ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
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
                                        'running'   => 'warning',
                                        'queued'    => 'info',
                                        default     => 'secondary',
                                    };
                                    $createdAt = $entry['created_at'] ?? null;
                                    $displayDate = ($createdAt !== null && $createdAt !== '')
                                        ? date('m/d/Y H:i:s', strtotime((string) $createdAt))
                                        : '—';
                                    ?>
                                    <tr>
                                        <td class="small text-nowrap"><?= esc($displayDate) ?></td>
                                        <td><?= esc($actionLabels[$entry['action']] ?? ucwords(str_replace('_', ' ', $entry['action']))) ?></td>
                                        <td><span class="badge text-bg-<?= esc($statusClass) ?>"><?= esc(ucfirst($status)) ?></span></td>
                                        <td><?= esc($entry['message']) ?></td>
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

<?= $this->endSection() ?>

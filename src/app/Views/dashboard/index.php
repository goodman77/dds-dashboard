<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Dashboard</h1>
            </div>
            <div class="col-sm-6 text-sm-end mt-2 mt-sm-0">
                <a href="<?= site_url('inventory') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-grid-3x3-gap"></i> Open Inventory
                </a>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (session('success')) : ?>
            <div class="alert alert-success"><?= esc(session('success')) ?></div>
        <?php endif ?>
        <?php if (session('error')) : ?>
            <div class="alert alert-danger"><?= esc(session('error')) ?></div>
        <?php endif ?>

        <div class="row">
            <div class="col-md-6">
                <a href="<?= site_url('inventory') ?>" class="small-box text-bg-primary text-decoration-none">
                    <div class="inner">
                        <h3><?= esc(number_format($totalRows)) ?></h3>
                        <p>Inventory Rows</p>
                    </div>
                    <i class="small-box-icon bi bi-boxes"></i>
                    <span class="small-box-footer link-light">
                        View inventory <i class="bi bi-arrow-right-circle"></i>
                    </span>
                </a>
            </div>

            <div class="col-md-6">
                <a href="<?= site_url('inventory') ?>" class="small-box text-bg-success text-decoration-none">
                    <div class="inner">
                        <h3><?= esc($sheetCount) ?></h3>
                        <p>Sheet Tabs</p>
                    </div>
                    <i class="small-box-icon bi bi-file-earmark-spreadsheet"></i>
                    <span class="small-box-footer link-light">
                        View inventory <i class="bi bi-arrow-right-circle"></i>
                    </span>
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-4">
                <a href="<?= site_url('inventory?qty=zero') ?>" class="small-box text-bg-warning text-decoration-none">
                    <div class="inner">
                        <h3><?= esc(number_format($zeroQuantityCount)) ?></h3>
                        <p>Net32 Quantity 0</p>
                    </div>
                    <i class="small-box-icon bi bi-inbox"></i>
                    <span class="small-box-footer link-dark">
                        View rows <i class="bi bi-arrow-right-circle"></i>
                    </span>
                </a>
            </div>

            <div class="col-lg-4 col-md-4">
                <a href="<?= site_url('inventory?net32=missing') ?>" class="small-box text-bg-danger text-decoration-none">
                    <div class="inner">
                        <h3><?= esc(number_format($net32Stats['missing'])) ?></h3>
                        <p>Net32 Missing in any store</p>
                    </div>
                    <i class="small-box-icon bi bi-cloud-slash"></i>
                    <span class="small-box-footer link-light">
                        View missing <i class="bi bi-arrow-right-circle"></i>
                    </span>
                </a>
            </div>

            <div class="col-lg-4 col-md-4">
                <a href="<?= site_url('inventory?qty=low') ?>" class="small-box text-bg-secondary text-decoration-none">
                    <div class="inner">
                        <h3><?= esc(number_format($lowStockCount)) ?></h3>
                        <p>Net32 Low stock (≤<?= esc((string) $lowStockThreshold) ?>)</p>
                    </div>
                    <i class="small-box-icon bi bi-graph-down-arrow"></i>
                    <span class="small-box-footer link-light">
                        View low stock <i class="bi bi-arrow-right-circle"></i>
                    </span>
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-success"><i class="bi bi-clock-history"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Google Sheets Sync</span>
                        <span class="info-box-number" style="font-size: 1rem;">
                            <?= $lastSyncedAt ? esc(format_log_datetime($lastSyncedAt)) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-warning"><i class="bi bi-cloud-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last Net32 Qty Sync</span>
                        <span class="info-box-number" style="font-size: 1rem;">
                            <?= $lastNet32Check ? esc(format_log_datetime($lastNet32Check)) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-4">
                <div class="info-box">
                    <span class="info-box-icon text-bg-info"><i class="bi bi-geo-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Last ShipStation Location Sync</span>
                        <span class="info-box-number" style="font-size: 1rem;">
                            <?= $lastShipStationCheck ? esc(format_log_datetime($lastShipStationCheck)) : 'Never' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Recent Logs</h3>
                <a href="<?= site_url('logs') ?>" class="btn btn-sm btn-outline-secondary">View all</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 190px;">Date (Pacific)</th>
                                <th style="width: 140px;">Action</th>
                                <th style="width: 110px;">Status</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentLogs === []) : ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        No activity logged yet.
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($recentLogs as $entry) : ?>
                                    <?php
                                    $status = (string) ($entry['status'] ?? '');
                                    $statusClass = match ($status) {
                                        'completed' => 'success',
                                        'failed'    => 'danger',
                                        'running'   => 'warning',
                                        'queued'    => 'info',
                                        default     => 'secondary',
                                    };
                                    $displayDate = format_log_datetime(
                                        isset($entry['created_at']) ? (string) $entry['created_at'] : null,
                                    );
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
        </div>

    </div>
</section>

<?= $this->endSection() ?>

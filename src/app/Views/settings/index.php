<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Settings</h1>
                <p class="text-muted small mb-0">App preferences used by inventory and the dashboard</p>
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
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Net32 low stock</h3>
                    </div>
                    <form action="<?= site_url('settings') ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="card-body">
                            <p class="text-muted small">
                                A SKU counts as low stock when <strong>any</strong> store (LV, Brook, or Coral)
                                has quantity at or below this threshold. Used by the dashboard tile and inventory filter.
                            </p>
                            <div class="mb-0">
                                <label for="net32_low_stock_threshold" class="form-label">Threshold</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="form-control"
                                    id="net32_low_stock_threshold"
                                    name="net32_low_stock_threshold"
                                    value="<?= esc((string) $lowStockThreshold) ?>"
                                    required
                                >
                                <div class="form-text">Default is 10. Set to 0 to only flag empty stock.</div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</section>

<?= $this->endSection() ?>

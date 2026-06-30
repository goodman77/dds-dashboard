<?php if ($pager->getTotal($pagerGroup) > 0) : ?>
    <div class="row align-items-center g-2">
        <div class="col-md-4">
            <form method="get" action="<?= site_url('inventory') ?>" class="d-flex align-items-center gap-2">
                <?php if ($search !== '') : ?>
                    <input type="hidden" name="q" value="<?= esc($search) ?>">
                <?php endif ?>
                <?php if ($sheetFilter !== '') : ?>
                    <input type="hidden" name="sheet" value="<?= esc($sheetFilter) ?>">
                <?php endif ?>
                <?php if (($net32Filter ?? '') !== '') : ?>
                    <input type="hidden" name="net32" value="<?= esc($net32Filter) ?>">
                <?php endif ?>
                <?php if (($quantityFilter ?? '') !== '') : ?>
                    <input type="hidden" name="qty" value="<?= esc($quantityFilter) ?>">
                <?php endif ?>
                <label for="per_page_top" class="form-label mb-0 text-muted small text-nowrap">Show</label>
                <select name="per_page" id="per_page_top" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <?php foreach ($perPageOptions as $option) : ?>
                        <option value="<?= esc($option) ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                            <?= esc($option) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <span class="text-muted small text-nowrap">per page</span>
            </form>
        </div>
        <div class="col-md-4 text-center">
            <span class="text-muted small">
                Page <?= esc($pager->getCurrentPage($pagerGroup)) ?> of <?= esc($pager->getPageCount($pagerGroup)) ?>
                &middot; <?= esc(number_format($pager->getTotal($pagerGroup))) ?> rows
            </span>
        </div>
        <div class="col-md-4">
            <?= $pager->links($pagerGroup, 'bootstrap') ?>
        </div>
    </div>
<?php endif ?>

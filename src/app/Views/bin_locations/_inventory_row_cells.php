<?php
/** @var array<string, mixed> $location */
/** @var bool $isAlternate */
$isAlternate = $isAlternate ?? false;
$net32Exists = array_key_exists('sku_net32_exists', $location) && $location['sku_net32_exists'] !== null
    ? (bool) $location['sku_net32_exists']
    : null;
?>
<td class="<?= $isAlternate ? 'border-start-0' : '' ?>">
    <?php if ($isAlternate) : ?>
        <span class="inventory-sku-indent text-muted me-1" aria-hidden="true">↳</span>
    <?php endif ?>
    <strong><?= esc($location['sku']) ?></strong>
    <?php if (! $isAlternate) : ?>
        <span class="badge text-bg-primary ms-1">Main</span>
    <?php endif ?>
</td>
<td class="small"><?= esc($location['name'] ?? '') !== '' ? esc($location['name']) : '—' ?></td>
<td class="small text-muted"><?= esc($location['description'] ?? '') !== '' ? esc($location['description']) : '—' ?></td>
<td class="text-end inventory-quantity"><?= esc(number_format((int) ($location['quantity'] ?? 0))) ?></td>
<td>
    <?= view('bin_locations/_net32_badge', [
        'exists'         => $net32Exists,
        'dataAttributes' => ['data-net32-badge' => 'sku'],
    ]) ?>
</td>
<td class="text-end">
    <div class="d-flex flex-column align-items-end gap-1">
        <div class="btn-group btn-group-sm" role="group" aria-label="Row actions">
            <button
                type="button"
                class="btn btn-outline-primary check-inventory-qty"
                data-check-url="<?= esc(site_url('inventory/' . $location['id'] . '/check-qty')) ?>"
                title="Check quantity in Net32"
                aria-label="Check quantity in Net32"
            >
                <i class="bi bi-arrow-repeat"></i>
            </button>
            <button
                type="button"
                class="btn btn-outline-secondary edit-inventory-row"
                data-edit-url="<?= esc(site_url('inventory/' . $location['id'])) ?>"
                title="Edit inventory row"
                aria-label="Edit inventory row"
            >
                <i class="bi bi-pencil"></i>
            </button>
        </div>
        <div class="check-qty-feedback small text-end" hidden></div>
    </div>
</td>

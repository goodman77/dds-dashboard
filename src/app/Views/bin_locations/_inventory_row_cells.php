<?php
/** @var array<string, mixed> $location */
/** @var bool $isAlternate */
$isAlternate = $isAlternate ?? false;
$canDelete = $canDelete ?? true;
$net32Exists = array_key_exists('sku_net32_exists', $location) && $location['sku_net32_exists'] !== null
    ? (bool) $location['sku_net32_exists']
    : null;
$shipStationExists = array_key_exists('shipstation_exists', $location) && $location['shipstation_exists'] !== null
    ? (bool) $location['shipstation_exists']
    : null;
$shipStationLocation = trim((string) ($location['shipstation_location'] ?? ''));
$shipStationWarehouse = trim((string) ($location['shipstation_warehouse'] ?? ''));
$locationMatches = array_key_exists('shipstation_location_matches', $location) && $location['shipstation_location_matches'] !== null
    ? (bool) (int) $location['shipstation_location_matches']
    : service('shipStationLocationCheck')->resolveLocationMatchForRow($location);
$inConfiguredWarehouse = service('shipStationLocationCheck')->resolveInConfiguredWarehouseForRow($location);
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
<td>
    <?= view('bin_locations/_shipstation_badge', [
        'exists'                => $shipStationExists,
        'warehouse'             => $shipStationWarehouse !== '' ? $shipStationWarehouse : null,
        'location'              => $shipStationLocation !== '' ? $shipStationLocation : null,
        'locationMatches'       => $locationMatches,
        'inConfiguredWarehouse' => $inConfiguredWarehouse,
        'dataAttributes'        => ['data-shipstation-badge' => 'sku'],
    ]) ?>
</td>
<td class="text-end">
    <div class="d-flex flex-column align-items-end gap-1">
        <div class="btn-group btn-group-sm" role="group" aria-label="Row actions">
            <?php if (! $isAlternate && ! empty($location['is_main_sku'])) : ?>
            <button
                type="button"
                class="btn btn-outline-success add-alternate-sku"
                data-sheet-name="<?= esc($location['sheet_name']) ?>"
                data-rack="<?= esc($location['rack']) ?>"
                data-bin="<?= esc($location['bin']) ?>"
                data-main-sku="<?= esc($location['sku']) ?>"
                title="Add alternate SKU"
                aria-label="Add alternate SKU for <?= esc($location['sku']) ?>"
            >
                <i class="bi bi-plus-lg"></i>
            </button>
            <?php endif ?>
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
                class="btn btn-outline-info sync-shipstation-location"
                data-sync-url="<?= esc(site_url('inventory/' . $location['id'] . '/sync-shipstation')) ?>"
                title="Sync location in ShipStation (existing SKUs only)"
                aria-label="Sync location in ShipStation"
            >
                <i class="bi bi-geo-alt-fill"></i>
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
            <?php if ($canDelete) : ?>
            <button
                type="button"
                class="btn btn-outline-danger delete-inventory-row"
                data-delete-url="<?= esc(site_url('inventory/' . $location['id'] . '/delete')) ?>"
                data-sku="<?= esc($location['sku']) ?>"
                title="Remove from inventory (Google Sheet unchanged)"
                aria-label="Remove <?= esc($location['sku']) ?> from inventory"
            >
                <i class="bi bi-trash"></i>
            </button>
            <?php else : ?>
            <button
                type="button"
                class="btn btn-outline-danger"
                disabled
                title="Remove alternate SKU(s) first before deleting the main SKU"
                aria-label="Cannot delete main SKU while alternate SKUs exist"
            >
                <i class="bi bi-trash"></i>
            </button>
            <?php endif ?>
        </div>
        <div class="check-qty-feedback small text-end" hidden></div>
    </div>
</td>

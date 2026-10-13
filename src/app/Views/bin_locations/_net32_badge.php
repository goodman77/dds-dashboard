<?php
use App\Libraries\Net32\Net32Stores;

/** @var array<string, mixed> $location */
$location = $location ?? [];
/** @var string $wrapperClass */
$wrapperClass = $wrapperClass ?? '';
/** @var array<string, string> $dataAttributes */
$dataAttributes = $dataAttributes ?? [];

$attrString = '';

foreach ($dataAttributes as $name => $value) {
    $attrString .= ' ' . esc($name, 'attr') . '="' . esc($value, 'attr') . '"';
}

$updateUrl = site_url('inventory/' . (int) ($location['id'] ?? 0) . '/update-qty');
?>
<div class="net32-store-chips <?= esc($wrapperClass) ?>"<?= $attrString ?> data-qty-update-url="<?= esc($updateUrl) ?>">
    <button
        type="button"
        class="net32-chip is-split net32-chip-editable"
        data-net32-action="split"
        title="Set a total and split LV 60% / Brook 20% / Coral 20%"
    >
        <span class="net32-chip-label">Split</span>
    </button>
    <?php foreach (Net32Stores::ids() as $store) : ?>
        <?php
        $exists = Net32Stores::existsFromRow($location, $store);
        $qty    = Net32Stores::qtyFromRow($location, $store);
        $title  = sprintf(
            '%s: %s%s — click to edit',
            Net32Stores::label($store),
            Net32Stores::existsLabel($exists, $qty),
            $exists === false || ($qty !== null && $qty !== '' && (int) $qty === 0)
                ? ''
                : ', qty ' . Net32Stores::formatStoreQty($qty),
        );
        ?>
        <button
            type="button"
            class="net32-chip net32-chip-editable <?= esc(Net32Stores::existsClass($exists, $qty)) ?>"
            data-net32-store="<?= esc($store) ?>"
            data-net32-action="store"
            data-current-qty="<?= esc($exists === false ? '' : (string) (int) ($qty ?? 0)) ?>"
            title="<?= esc($title) ?>"
        >
            <span class="net32-chip-label"><?= esc(Net32Stores::label($store)) ?></span>
            <strong class="inventory-qty-<?= esc($store) ?>"><?= esc(Net32Stores::formatStoreQty($exists === false ? null : $qty)) ?></strong>
        </button>
    <?php endforeach ?>
</div>

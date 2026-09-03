<?php
/** @var bool|null $exists */
$exists = $exists ?? null;
/** @var string|null $warehouse */
$warehouse = $warehouse ?? null;
/** @var string|null $location */
$location = $location ?? null;
/** @var bool|null $locationMatches */
$locationMatches = $locationMatches ?? null;
/** @var bool|null $inConfiguredWarehouse */
$inConfiguredWarehouse = $inConfiguredWarehouse ?? null;
/** @var string $wrapperClass */
$wrapperClass = $wrapperClass ?? '';
/** @var array<string, string> $dataAttributes */
$dataAttributes = $dataAttributes ?? [];

$attrString = '';

foreach ($dataAttributes as $name => $value) {
    $attrString .= ' ' . esc($name, 'attr') . '="' . esc($value, 'attr') . '"';
}

$warehouse = trim((string) ($warehouse ?? ''));
$location  = trim((string) ($location ?? ''));
$detailParts = array_values(array_filter([$warehouse !== '' ? $warehouse : null, $location !== '' ? $location : null]));
$detailText = $detailParts !== [] ? implode(' · ', $detailParts) : '';
?>
<span class="shipstation-badge-wrap d-flex flex-column gap-1 <?= esc($wrapperClass) ?>"<?= $attrString ?>>
<?php if ($exists === null) : ?>
    <span class="badge text-bg-secondary">Not checked</span>
<?php elseif ($exists) : ?>
    <span class="badge text-bg-success">In ShipStation</span>
<?php else : ?>
    <span class="badge text-bg-danger">Not in ShipStation</span>
<?php endif ?>
<?php if ($detailText !== '') : ?>
    <span class="small text-muted" data-shipstation-detail-text><?= esc($detailText) ?></span>
    <?php if ($inConfiguredWarehouse === false) : ?>
        <span class="badge text-bg-warning">Wrong warehouse</span>
    <?php elseif ($locationMatches === false) : ?>
        <span class="badge text-bg-warning">Location mismatch</span>
    <?php elseif ($locationMatches === true) : ?>
        <span class="badge text-bg-light border text-success">Location match</span>
    <?php endif ?>
<?php else : ?>
    <span class="small text-muted" data-shipstation-detail-text hidden>—</span>
<?php endif ?>
</span>

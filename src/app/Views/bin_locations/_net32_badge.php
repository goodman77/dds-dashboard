<?php
/** @var bool|null $exists */
$exists = $exists ?? null;
/** @var string $wrapperClass */
$wrapperClass = $wrapperClass ?? '';
/** @var array<string, string> $dataAttributes */
$dataAttributes = $dataAttributes ?? [];

$attrString = '';

foreach ($dataAttributes as $name => $value) {
    $attrString .= ' ' . esc($name, 'attr') . '="' . esc($value, 'attr') . '"';
}
?>
<span class="net32-badge-wrap <?= esc($wrapperClass) ?>"<?= $attrString ?>>
<?php if ($exists === null) : ?>
    <span class="badge text-bg-secondary">Not checked</span>
<?php elseif ($exists) : ?>
    <span class="badge text-bg-success">In Net32</span>
<?php else : ?>
    <span class="badge text-bg-danger">Not in Net32</span>
<?php endif ?>
</span>

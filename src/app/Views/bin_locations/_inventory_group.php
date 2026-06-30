<?php
/** @var array{main: array<string, mixed>|null, alternates: list<array<string, mixed>>, sheet_name: string, rack: string, bin: string} $group */
$main = $group['main'];
$alternates = $group['alternates'];
$displayAlternates = $main !== null ? $alternates : array_slice($alternates, 1);
$rowSpan = ($main !== null ? 1 : 0) + count($alternates);

if ($rowSpan === 0) {
    return;
}

$primaryLocation = $main ?? $alternates[0];
$hasAlternateRows = $displayAlternates !== [];
?>
<tr
    data-location-id="<?= (int) $primaryLocation['id'] ?>"
    class="inventory-sku-main inventory-group-start<?= ! $hasAlternateRows ? ' inventory-group-end' : '' ?>"
>
    <td rowspan="<?= $rowSpan ?>" class="align-middle bg-body">
        <span class="badge text-bg-secondary"><?= esc($group['sheet_name']) ?></span>
    </td>
    <td rowspan="<?= $rowSpan ?>" class="align-middle bg-body"><?= esc($group['rack']) ?></td>
    <td rowspan="<?= $rowSpan ?>" class="align-middle bg-body"><?= esc($group['bin']) ?></td>
    <?php
    $location = $primaryLocation;
    $isAlternate = false;
    echo view('bin_locations/_inventory_row_cells', compact('location', 'isAlternate'));
    ?>
</tr>

<?php foreach ($displayAlternates as $index => $location) : ?>
    <tr
        data-location-id="<?= (int) $location['id'] ?>"
        class="inventory-sku-alt<?= $index === count($displayAlternates) - 1 ? ' inventory-group-end' : '' ?>"
    >
        <?php
        $isAlternate = true;
        echo view('bin_locations/_inventory_row_cells', compact('location', 'isAlternate'));
        ?>
    </tr>
<?php endforeach ?>

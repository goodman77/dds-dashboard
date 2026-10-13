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
$groupClass = $hasAlternateRows ? ' inventory-has-alts' : '';
?>
<tr
    data-location-id="<?= (int) $primaryLocation['id'] ?>"
    class="inventory-sku-main inventory-group-start<?= $groupClass ?><?= ! $hasAlternateRows ? ' inventory-group-end' : '' ?>"
>
    <td rowspan="<?= $rowSpan ?>" class="align-middle inventory-group-meta inventory-group-rail">
        <span class="badge text-bg-secondary"><?= esc($group['sheet_name']) ?></span>
    </td>
    <td rowspan="<?= $rowSpan ?>" class="align-middle inventory-group-meta"><?= esc($group['rack']) ?></td>
    <td rowspan="<?= $rowSpan ?>" class="align-middle inventory-group-meta"><?= esc($group['bin']) ?></td>
    <?php
    $location = $primaryLocation;
    $isAlternate = false;
    $canDelete = empty($location['is_main_sku']) || $alternates === [];
    echo view('bin_locations/_inventory_row_cells', compact('location', 'isAlternate', 'canDelete'));
    ?>
</tr>

<?php foreach ($displayAlternates as $index => $location) : ?>
    <tr
        data-location-id="<?= (int) $location['id'] ?>"
        class="inventory-sku-alt<?= $groupClass ?><?= $index === count($displayAlternates) - 1 ? ' inventory-group-end' : '' ?>"
    >
        <?php
        $isAlternate = true;
        $canDelete = true;
        echo view('bin_locations/_inventory_row_cells', compact('location', 'isAlternate', 'canDelete'));
        ?>
    </tr>
<?php endforeach ?>

<!DOCTYPE html>
<html lang="en">

<?= view('partials/header') ?>

<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

<div class="app-wrapper">

    <?= view('partials/navbar') ?>

    <?= view('partials/sidebar') ?>

    <div class="content-wrapper">
        <section class="content pt-3">
            <div class="container-fluid">

                <?= $this->renderSection('content') ?>

            </div>
        </section>
    </div>

    <?= view('partials/footer') ?>

</div>

<?= view('partials/scripts') ?>

</body>
</html>
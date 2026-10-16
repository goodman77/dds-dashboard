<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?><?= lang('Auth.useMagicLink') ?> <?= $this->endSection() ?>

<?= $this->section('main') ?>

<div class="card auth-card">
    <div class="card-body">
        <h1 class="card-title"><?= lang('Auth.useMagicLink') ?></h1>

        <p class="mb-2"><strong><?= lang('Auth.checkYourEmail') ?></strong></p>
        <p class="text-muted mb-4"><?= lang('Auth.magicLinkDetails', [setting('Auth.magicLinkLifetime') / 60]) ?></p>

        <p class="text-center text-muted small mb-0">
            <a href="<?= url_to('login') ?>"><?= lang('Auth.backToLogin') ?></a>
        </p>
    </div>
</div>

<?= $this->endSection() ?>

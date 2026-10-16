<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?><?= lang('Auth.useMagicLink') ?> <?= $this->endSection() ?>

<?= $this->section('main') ?>

<div class="card auth-card">
    <div class="card-body">
        <h1 class="card-title"><?= lang('Auth.useMagicLink') ?></h1>

        <?php if (session('error') !== null) : ?>
            <div class="alert alert-danger" role="alert"><?= esc(session('error')) ?></div>
        <?php elseif (session('errors') !== null) : ?>
            <div class="alert alert-danger" role="alert">
                <?php if (is_array(session('errors'))) : ?>
                    <?php foreach (session('errors') as $error) : ?>
                        <?= esc($error) ?>
                        <br>
                    <?php endforeach ?>
                <?php else : ?>
                    <?= esc(session('errors')) ?>
                <?php endif ?>
            </div>
        <?php endif ?>

        <form action="<?= url_to('magic-link') ?>" method="post">
            <?= csrf_field() ?>

            <div class="form-floating mb-3">
                <input
                    type="email"
                    class="form-control"
                    id="floatingEmailInput"
                    name="email"
                    autocomplete="email"
                    placeholder="<?= lang('Auth.email') ?>"
                    value="<?= old('email', auth()->user()->email ?? null) ?>"
                    required
                >
                <label for="floatingEmailInput"><?= lang('Auth.email') ?></label>
            </div>

            <div class="d-grid mt-4 mb-3">
                <button type="submit" class="btn btn-primary"><?= lang('Auth.send') ?></button>
            </div>
        </form>

        <p class="text-center text-muted small mb-0">
            <a href="<?= url_to('login') ?>"><?= lang('Auth.backToLogin') ?></a>
        </p>
    </div>
</div>

<?= $this->endSection() ?>

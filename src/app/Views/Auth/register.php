<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?><?= lang('Auth.register') ?> <?= $this->endSection() ?>

<?= $this->section('main') ?>

<div class="card auth-card">
    <div class="card-body">
        <h1 class="card-title"><?= lang('Auth.register') ?></h1>

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

        <form action="<?= url_to('register') ?>" method="post">
            <?= csrf_field() ?>

            <div class="form-floating mb-2">
                <input type="text" class="form-control" id="floatingFirstNameInput" name="first_name" autocomplete="given-name" placeholder="First Name" value="<?= old('first_name') ?>" required>
                <label for="floatingFirstNameInput">First Name</label>
            </div>

            <div class="form-floating mb-2">
                <input type="text" class="form-control" id="floatingLastNameInput" name="last_name" autocomplete="family-name" placeholder="Last Name" value="<?= old('last_name') ?>" required>
                <label for="floatingLastNameInput">Last Name</label>
            </div>

            <div class="form-floating mb-2">
                <input type="email" class="form-control" id="floatingEmailInput" name="email" inputmode="email" autocomplete="email" placeholder="<?= lang('Auth.email') ?>" value="<?= old('email') ?>" required>
                <label for="floatingEmailInput"><?= lang('Auth.email') ?></label>
            </div>

            <div class="form-floating mb-4">
                <input type="text" class="form-control" id="floatingUsernameInput" name="username" inputmode="text" autocomplete="username" placeholder="<?= lang('Auth.username') ?>" value="<?= old('username') ?>" required>
                <label for="floatingUsernameInput"><?= lang('Auth.username') ?></label>
            </div>

            <div class="form-floating mb-2">
                <input type="password" class="form-control" id="floatingPasswordInput" name="password" inputmode="text" autocomplete="new-password" placeholder="<?= lang('Auth.password') ?>" required>
                <label for="floatingPasswordInput"><?= lang('Auth.password') ?></label>
            </div>

            <div class="form-floating mb-4">
                <input type="password" class="form-control" id="floatingPasswordConfirmInput" name="password_confirm" inputmode="text" autocomplete="new-password" placeholder="<?= lang('Auth.passwordConfirm') ?>" required>
                <label for="floatingPasswordConfirmInput"><?= lang('Auth.passwordConfirm') ?></label>
            </div>

            <div class="d-grid mt-2 mb-3">
                <button type="submit" class="btn btn-primary"><?= lang('Auth.register') ?></button>
            </div>

            <p class="text-center text-muted small mb-0">
                <?= lang('Auth.haveAccount') ?>
                <a href="<?= url_to('login') ?>"><?= lang('Auth.login') ?></a>
            </p>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

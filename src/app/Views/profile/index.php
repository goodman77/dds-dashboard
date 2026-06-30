<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Profile</h1>
                <p class="text-muted small mb-0">Your account details and password</p>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (session('success')) : ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= esc(session('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif ?>
        <?php if (session('error')) : ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= esc(session('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif ?>

        <?php
        $profileErrors = session('profile_errors') ?? [];
        $passwordErrors = session('password_errors') ?? [];
        ?>

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Account Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-4">
                            <dt class="col-sm-4">Username</dt>
                            <dd class="col-sm-8"><?= esc($profile['username'] !== '' ? $profile['username'] : '—') ?></dd>

                            <dt class="col-sm-4">Email</dt>
                            <dd class="col-sm-8"><?= esc($profile['email'] !== '' ? $profile['email'] : '—') ?></dd>

                            <dt class="col-sm-4">Role</dt>
                            <dd class="col-sm-8">
                                <span class="badge text-bg-<?= $profile['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                    <?= esc($profile['role_label']) ?>
                                </span>
                            </dd>

                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8">
                                <span class="badge text-bg-<?= $profile['active'] ? 'success' : 'danger' ?>">
                                    <?= $profile['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </dd>

                            <dt class="col-sm-4">Member since</dt>
                            <dd class="col-sm-8">
                                <?= ($profile['created_at'] ?? '') !== ''
                                    ? esc(date('m/d/Y H:i', strtotime((string) $profile['created_at'])))
                                    : '—' ?>
                            </dd>
                        </dl>

                        <form method="post" action="<?= site_url('profile') ?>">
                            <?= csrf_field() ?>

                            <div class="mb-3">
                                <label for="first_name" class="form-label">First name</label>
                                <input
                                    type="text"
                                    class="form-control<?= isset($profileErrors['first_name']) ? ' is-invalid' : '' ?>"
                                    id="first_name"
                                    name="first_name"
                                    value="<?= esc(old('first_name', $profile['first_name'])) ?>"
                                >
                                <?php if (isset($profileErrors['first_name'])) : ?>
                                    <div class="invalid-feedback"><?= esc($profileErrors['first_name']) ?></div>
                                <?php endif ?>
                            </div>

                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last name</label>
                                <input
                                    type="text"
                                    class="form-control<?= isset($profileErrors['last_name']) ? ' is-invalid' : '' ?>"
                                    id="last_name"
                                    name="last_name"
                                    value="<?= esc(old('last_name', $profile['last_name'])) ?>"
                                >
                                <?php if (isset($profileErrors['last_name'])) : ?>
                                    <div class="invalid-feedback"><?= esc($profileErrors['last_name']) ?></div>
                                <?php endif ?>
                            </div>

                            <button type="submit" class="btn btn-primary">Save Profile</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Change Password</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?= site_url('profile/password') ?>">
                            <?= csrf_field() ?>

                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current password</label>
                                <input
                                    type="password"
                                    class="form-control<?= isset($passwordErrors['current_password']) ? ' is-invalid' : '' ?>"
                                    id="current_password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    required
                                >
                                <?php if (isset($passwordErrors['current_password'])) : ?>
                                    <div class="invalid-feedback"><?= esc($passwordErrors['current_password']) ?></div>
                                <?php endif ?>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">New password</label>
                                <input
                                    type="password"
                                    class="form-control<?= isset($passwordErrors['password']) ? ' is-invalid' : '' ?>"
                                    id="password"
                                    name="password"
                                    autocomplete="new-password"
                                    required
                                >
                                <?php if (isset($passwordErrors['password'])) : ?>
                                    <div class="invalid-feedback"><?= esc($passwordErrors['password']) ?></div>
                                <?php endif ?>
                            </div>

                            <div class="mb-3">
                                <label for="password_confirm" class="form-label">Confirm new password</label>
                                <input
                                    type="password"
                                    class="form-control<?= isset($passwordErrors['password_confirm']) ? ' is-invalid' : '' ?>"
                                    id="password_confirm"
                                    name="password_confirm"
                                    autocomplete="new-password"
                                    required
                                >
                                <?php if (isset($passwordErrors['password_confirm'])) : ?>
                                    <div class="invalid-feedback"><?= esc($passwordErrors['password_confirm']) ?></div>
                                <?php endif ?>
                            </div>

                            <button type="submit" class="btn btn-outline-primary">Change Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>

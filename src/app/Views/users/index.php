<?= $this->extend('layouts/master') ?>

<?= $this->section('content') ?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2 align-items-center">
            <div class="col-sm-6">
                <h1 class="m-0">Users</h1>
                <p class="text-muted small mb-0">Create and manage employee accounts</p>
            </div>
            <div class="col-sm-6 text-sm-end mt-2 mt-sm-0">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#create-user-modal">
                    <i class="bi bi-person-plus"></i> Create Employee
                </button>
            </div>
        </div>
    </div>
</div>

<section class="content">
    <div class="container-fluid">

        <?php if (session('success')) : ?>
            <div class="alert alert-success"><?= esc(session('success')) ?></div>
        <?php endif ?>
        <?php if (session('error')) : ?>
            <div class="alert alert-danger"><?= esc(session('error')) ?></div>
        <?php endif ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">All Users</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end" style="width: 90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users === []) : ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No users found.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($users as $user) : ?>
                                    <tr>
                                        <td><?= esc($user['username'] !== '' ? $user['username'] : '—') ?></td>
                                        <td><?= esc($user['email']) ?></td>
                                        <td>
                                            <?php
                                            $name = trim($user['first_name'] . ' ' . $user['last_name']);
                                            echo esc($name !== '' ? $name : '—');
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= $user['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                                <?= esc($user['role_label']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= $user['active'] ? 'success' : 'danger' ?>">
                                                <?= $user['active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="small text-nowrap">
                                            <?= ($user['created_at'] ?? '') !== ''
                                                ? esc(date('m/d/Y H:i', strtotime((string) $user['created_at'])))
                                                : '—' ?>
                                        </td>
                                        <td class="text-end">
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm edit-user-btn"
                                                data-edit-url="<?= esc(site_url('users/' . $user['id'])) ?>"
                                                title="Edit name and status"
                                            >
                                                <i class="bi bi-pencil"></i>
                                                <span class="d-none d-md-inline">Edit</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            <?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="create-user-modal" tabindex="-1" aria-labelledby="create-user-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('users') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="create-user-modal-label">Create Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php $errors = session('errors') ?? []; ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>" id="username" name="username" value="<?= esc(old('username')) ?>" required>
                        <?php if (isset($errors['username'])) : ?>
                            <div class="invalid-feedback"><?= esc($errors['username']) ?></div>
                        <?php endif ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" value="<?= esc(old('email')) ?>" required>
                        <?php if (isset($errors['email'])) : ?>
                            <div class="invalid-feedback"><?= esc($errors['email']) ?></div>
                        <?php endif ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= esc(old('first_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= esc(old('last_name')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password" required>
                        <?php if (isset($errors['password'])) : ?>
                            <div class="invalid-feedback"><?= esc($errors['password']) ?></div>
                        <?php endif ?>
                    </div>

                    <div class="mb-0">
                        <label for="password_confirm" class="form-label">Confirm password</label>
                        <input type="password" class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>" id="password_confirm" name="password_confirm" required>
                        <?php if (isset($errors['password_confirm'])) : ?>
                            <div class="invalid-feedback"><?= esc($errors['password_confirm']) ?></div>
                        <?php endif ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="edit-user-modal" tabindex="-1" aria-labelledby="edit-user-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('users/0') ?>" id="edit-user-form">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="edit-user-modal-label">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php $editErrors = session('edit_errors') ?? []; ?>

                    <div class="mb-3">
                        <label class="form-label text-muted small">Account</label>
                        <div id="edit-user-account" class="fw-semibold">—</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="edit_first_name" class="form-label">First name</label>
                            <input type="text" class="form-control<?= isset($editErrors['first_name']) ? ' is-invalid' : '' ?>" id="edit_first_name" name="first_name" value="<?= esc(old('first_name')) ?>">
                            <?php if (isset($editErrors['first_name'])) : ?>
                                <div class="invalid-feedback"><?= esc($editErrors['first_name']) ?></div>
                            <?php endif ?>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_last_name" class="form-label">Last name</label>
                            <input type="text" class="form-control<?= isset($editErrors['last_name']) ? ' is-invalid' : '' ?>" id="edit_last_name" name="last_name" value="<?= esc(old('last_name')) ?>">
                            <?php if (isset($editErrors['last_name'])) : ?>
                                <div class="invalid-feedback"><?= esc($editErrors['last_name']) ?></div>
                            <?php endif ?>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="edit_active" class="form-label">Status</label>
                        <select class="form-select<?= isset($editErrors['active']) ? ' is-invalid' : '' ?>" id="edit_active" name="active">
                            <option value="1" <?= old('active', '1') === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= old('active') === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if (isset($editErrors['active'])) : ?>
                            <div class="invalid-feedback d-block"><?= esc($editErrors['active']) ?></div>
                        <?php endif ?>
                        <div class="form-text">Inactive users cannot log in.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('edit-user-modal');
    const editForm = document.getElementById('edit-user-form');
    const editAccount = document.getElementById('edit-user-account');
    const editFirstName = document.getElementById('edit_first_name');
    const editLastName = document.getElementById('edit_last_name');
    const editActive = document.getElementById('edit_active');

    function openEditModal(user) {
        if (!editForm || !user) {
            return;
        }

        editForm.action = <?= json_encode(site_url('users/')) ?> + user.id;
        if (editAccount) {
            editAccount.textContent = (user.username || user.email) + ' · ' + user.role_label;
        }
        if (editFirstName) editFirstName.value = user.first_name || '';
        if (editLastName) editLastName.value = user.last_name || '';
        if (editActive) editActive.value = user.active ? '1' : '0';

        bootstrap.Modal.getOrCreateInstance(editModal).show();
    }

    document.querySelectorAll('.edit-user-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const editUrl = button.dataset.editUrl;
            if (!editUrl) {
                return;
            }

            fetch(editUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data.ok) {
                        window.alert(result.data.message || 'Could not load user.');
                        return;
                    }

                    openEditModal(result.data.user);
                })
                .catch(function () {
                    window.alert('Could not load user.');
                });
        });
    });

    <?php if (session('errors')) : ?>
    bootstrap.Modal.getOrCreateInstance(document.getElementById('create-user-modal')).show();
    <?php endif ?>

    <?php if (session('edit_user_id')) : ?>
    fetch(<?= json_encode(site_url('users/' . session('edit_user_id'))) ?>, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.ok) {
                openEditModal(data.user);
                if (editFirstName) editFirstName.value = <?= json_encode(old('first_name')) ?> || editFirstName.value;
                if (editLastName) editLastName.value = <?= json_encode(old('last_name')) ?> || editLastName.value;
                if (editActive) editActive.value = <?= json_encode(old('active', '1')) ?>;
            }
        });
    <?php endif ?>
});
</script>

<?= $this->endSection() ?>

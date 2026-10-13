<?php
$user = auth()->user();
$firstName = trim((string) ($user->first_name ?? ''));
$lastName  = trim((string) ($user->last_name ?? ''));
$fullName  = trim($firstName . ' ' . $lastName);
if ($fullName === '') {
    $fullName = (string) ($user->username ?? $user->getEmail() ?? 'User');
}
$email = (string) ($user->getEmail() ?? '');
?>
<nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                <i class="bi bi-list"></i>
              </a>
            </li>
          </ul>
          <!--end::Start Navbar Links-->

          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto align-items-center">
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a
                href="#"
                class="nav-link d-flex align-items-center gap-2 py-1 pe-1"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Account menu"
              >
                <span class="user-avatar"><i class="bi bi-person-fill"></i></span>
                <span class="d-none d-md-inline user-trigger-name"><?= esc($fullName) ?></span>
                <i class="bi bi-chevron-down user-trigger-caret d-none d-md-inline"></i>
              </a>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-0">
                <li class="user-menu-header px-3 py-3">
                  <div class="d-flex align-items-center gap-2">
                    <span class="user-avatar user-avatar-lg"><i class="bi bi-person-fill"></i></span>
                    <div class="min-w-0">
                      <div class="fw-semibold text-truncate"><?= esc($fullName) ?></div>
                      <?php if ($email !== '') : ?>
                        <div class="small text-body-secondary text-truncate"><?= esc($email) ?></div>
                      <?php endif ?>
                    </div>
                  </div>
                </li>
                <li><hr class="dropdown-divider my-0"></li>
                <li>
                  <a href="<?= site_url('profile') ?>" class="dropdown-item py-2">
                    <i class="bi bi-person me-2"></i> Profile
                  </a>
                </li>
                <li><hr class="dropdown-divider my-0"></li>
                <li>
                  <a href="<?= site_url('logout') ?>" class="dropdown-item py-2 text-danger">
                    <i class="bi bi-box-arrow-right me-2"></i> Sign out
                  </a>
                </li>
              </ul>
            </li>
            <!--end::User Menu Dropdown-->
          </ul>
          <!--end::End Navbar Links-->
        </div>
        <!--end::Container-->
      </nav>

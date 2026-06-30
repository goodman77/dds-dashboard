      <!--begin::Sidebar-->
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a href="<?= site_url('dashboard') ?>" class="brand-link">
            <span class="brand-text fw-light">DDS Dashboard</span>
          </a>
        </div>

        <div class="sidebar-wrapper">
          <nav class="mt-2" aria-label="Main navigation">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" id="navigation">
              <li class="nav-item">
                <a href="<?= site_url('dashboard') ?>" class="nav-link <?= url_is('dashboard*') ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-speedometer"></i>
                  <p>Dashboard</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= site_url('inventory') ?>" class="nav-link <?= url_is('inventory*') || url_is('bin-locations*') ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-grid-3x3-gap"></i>
                  <p>Inventory</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= site_url('logs') ?>" class="nav-link <?= url_is('logs*') || url_is('reports*') ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-journal-text"></i>
                  <p>Logs</p>
                </a>
              </li>
              <?php if (auth()->loggedIn() && auth()->user()->inGroup('admin')) : ?>
              <li class="nav-item">
                <a href="<?= site_url('users') ?>" class="nav-link <?= url_is('users*') ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-people"></i>
                  <p>Users</p>
                </a>
              </li>
              <?php endif ?>
            </ul>
          </nav>
        </div>
      </aside>
      <!--end::Sidebar-->

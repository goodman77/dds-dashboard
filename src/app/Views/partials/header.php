<!--begin::Head-->
 <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>DDS Dashboard</title>

    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/png" href="<?= base_url('favicon-32.png') ?>" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">

    <!--begin::Theme Init (prevents flash of incorrect theme on load, #6043)-->
    <script>
      (() => {
        'use strict';
        const STORAGE_KEY = 'lte-theme';
        let stored = null;
        try {
          stored = localStorage.getItem(STORAGE_KEY);
        } catch {
          // localStorage may be unavailable (private mode, sandboxed iframe).
        }
        const prefersDark = globalThis.matchMedia('(prefers-color-scheme: dark)').matches;
        // Mirror the resolution in _scripts.astro: explicit "dark"/"light" win,
        // otherwise ("auto" or unset) fall back to the OS preference.
        let resolved = 'light';
        if (stored === 'dark' || stored === 'light') {
          resolved = stored;
        } else if (prefersDark) {
          resolved = 'dark';
        }
        document.documentElement.setAttribute('data-bs-theme', resolved);
        document.documentElement.style.colorScheme = resolved;
      })();
    </script>
    <!--end::Theme Init-->

    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->

    <!--begin::Primary Meta Tags-->
    <meta name="title" content="AdminLTE | Dashboard v2" />
    <meta name="author" content="ColorlibHQ" />
    <meta
      name="description"
      content="DDS Dashboard."
    />
    <meta
      name="keywords"
      content="DDS Dashboard."
    />
    <!--end::Primary Meta Tags-->

    <!--begin::Accessibility Features-->
    <!-- Skip links will be dynamically added by accessibility.js -->
    <meta name="supported-color-schemes" content="light dark" />
     <!-- AdminLTE -->
     <link rel="stylesheet"
          href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
    <!--end::Accessibility Features-->

    <!--begin::Fonts-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
      integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q="
      crossorigin="anonymous"
      media="print"
      onload="this.media = 'all'"
    />
    <!--end::Fonts-->

    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(OverlayScrollbars)-->

    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(Bootstrap Icons)-->

    <!--begin::Required Plugin(AdminLTE)-->
    <!-- AdminLTE -->
    <link rel="stylesheet"
          href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
    <!--end::Required Plugin(AdminLTE)-->

    <style>
      :root,
      [data-bs-theme=light],
      [data-bs-theme=dark] {
        --lte-sidebar-width: 230px;
      }

      .app-sidebar {
        background-color: #000 !important;
      }

      .app-sidebar .brand-link {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0.65rem 0.75rem;
      }

      .app-sidebar .brand-image {
        display: block;
        width: auto;
        max-width: 100%;
        height: 2.6rem;
        max-height: 45px !important;
        object-fit: contain;
      }

      .sidebar-menu .nav-link p {
        font-size: 1.17rem; /* ~30% larger than prior 0.9rem */
      }

      .sidebar-menu .nav-link .nav-icon {
        font-size: 1.17rem;
      }

      .user-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        background: #111;
        color: #fff;
        font-size: 1rem;
        flex-shrink: 0;
      }

      .user-avatar-lg {
        width: 2.5rem;
        height: 2.5rem;
        font-size: 1.15rem;
      }

      .user-menu > .nav-link {
        color: var(--bs-body-color);
      }

      .user-menu > .nav-link:hover,
      .user-menu > .nav-link:focus,
      .user-menu > .nav-link.show {
        color: var(--bs-body-color);
      }

      .user-trigger-name {
        font-size: 0.9rem;
        font-weight: 500;
        max-width: 10rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .user-trigger-caret {
        font-size: 0.7rem;
        opacity: 0.55;
      }

      .navbar-nav > .user-menu > .dropdown-menu {
        width: 260px;
        margin-top: 0.35rem !important;
      }

      .user-menu-header {
        background: var(--bs-tertiary-bg);
      }
    </style>

    <!-- apexcharts -->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css"
      integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0="
      crossorigin="anonymous"
    />
  </head>

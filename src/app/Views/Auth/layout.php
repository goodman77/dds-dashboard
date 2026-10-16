<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= $this->renderSection('title') ?> · DDS Dashboard</title>

    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/png" href="<?= base_url('favicon-32.png') ?>" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65"
        crossorigin="anonymous"
    >

    <style>
        :root {
            --auth-ink: #111111;
            --auth-muted: #5c6570;
            --auth-card: #ffffff;
            --auth-btn: #111111;
            --auth-btn-hover: #2a2a2a;
        }

        body.auth-body {
            min-height: 100vh;
            margin: 0;
            color: var(--auth-ink);
            background:
                radial-gradient(1200px 600px at 50% -10%, rgba(17, 17, 17, 0.08), transparent 60%),
                linear-gradient(180deg, #f4f5f7 0%, #e8eaee 100%);
        }

        .auth-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;
        }

        .auth-panel {
            width: 100%;
            max-width: 420px;
        }

        .auth-brand {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .auth-brand img {
            display: block;
            margin: 0 auto;
            width: auto;
            height: auto;
            max-width: min(260px, 70vw);
            max-height: 88px;
            object-fit: contain;
        }

        .auth-brand-title {
            margin: 0.85rem 0 0;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--auth-muted);
        }

        .auth-card {
            border: 0;
            border-radius: 1rem;
            background: var(--auth-card);
            box-shadow:
                0 1px 2px rgba(17, 17, 17, 0.04),
                0 18px 40px rgba(17, 17, 17, 0.08);
        }

        .auth-card .card-body {
            padding: 2rem 1.75rem 1.75rem;
        }

        .auth-card .card-title {
            margin-bottom: 1.5rem;
            font-size: 1.35rem;
            font-weight: 700;
            text-align: center;
            color: var(--auth-ink);
        }

        .auth-card .btn-primary {
            --bs-btn-bg: var(--auth-btn);
            --bs-btn-border-color: var(--auth-btn);
            --bs-btn-hover-bg: var(--auth-btn-hover);
            --bs-btn-hover-border-color: var(--auth-btn-hover);
            --bs-btn-active-bg: #000;
            --bs-btn-active-border-color: #000;
            padding: 0.7rem 1rem;
            font-weight: 600;
            border-radius: 0.65rem;
        }

        .auth-card .form-floating > .form-control {
            border-radius: 0.65rem;
        }

        .auth-card .form-floating > .form-control:focus {
            border-color: #111;
            box-shadow: 0 0 0 0.2rem rgba(17, 17, 17, 0.12);
        }

        .auth-card a {
            color: var(--auth-ink);
            font-weight: 600;
            text-decoration: none;
        }

        .auth-card a:hover {
            text-decoration: underline;
        }
    </style>

    <?= $this->renderSection('pageStyles') ?>
</head>
<body class="auth-body">
    <main class="auth-shell" role="main">
        <div class="auth-panel">
            <div class="auth-brand">
                <img
                    src="<?= base_url('assets/img/logo.webp') ?>"
                    alt="DDS Dashboard"
                >
                <p class="auth-brand-title">DDS Dashboard</p>
            </div>

            <?= $this->renderSection('main') ?>
        </div>
    </main>

    <?= $this->renderSection('pageScripts') ?>
</body>
</html>

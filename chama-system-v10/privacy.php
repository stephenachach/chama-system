<?php
require_once __DIR__ . '/config/auth.php';
$pageTitle = 'Privacy & security - ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= url('index.php') ?>"><i class="fa-solid fa-people-group me-2"></i><?= e(APP_NAME) ?></a>
        <a class="btn btn-sm btn-outline-light" href="<?= url('index.php') ?>">Back to home</a>
    </div>
</nav>
<div class="container py-4" style="max-width: 860px;">
    <h1 class="fw-bold mb-1">Privacy &amp; security</h1>
    <p class="text-muted mb-4">Plain-language summary of what we hold, who controls it, and how it is protected.</p>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa-solid fa-sitemap text-primary me-2"></i>Who controls your data</h2>
            <p class="mb-0">Your chama (savings group) is the <strong>controller</strong> of its members' data: the group decides what is recorded and why. We are the <strong>processor</strong>: we host and run the software on the group's behalf, under a written agreement. Your group's committee — not us — answers member questions about their records.</p>
        </div>
    </div>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa-solid fa-database text-primary me-2"></i>What the system holds</h2>
            <ul class="mb-0">
                <li>Member identity: name, phone number, member number, optional email, join date.</li>
                <li>Money records: contributions, loans, repayments, balances, statements, M-Pesa payment references.</li>
                <li>Welfare cases: request reason and decisions. <strong>This can be sensitive</strong>, so case details are visible only to the member's own group committee — never to other groups, and committee-only inside the group.</li>
                <li>Logins: email and a one-way password hash (never the password itself), plus failed-login counts for security.</li>
            </ul>
        </div>
    </div>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa-solid fa-lock text-primary me-2"></i>How we protect it</h2>
            <ul class="mb-0">
                <li>Every group's data is strictly separated (tenant isolation); users only ever see their own group.</li>
                <li>Role-based access: member, treasurer, admin — each sees only what their role needs.</li>
                <li>All state-changing actions are protected against cross-site request forgery, and destructive actions require an explicit confirmation.</li>
                <li>Login throttling and password hashing protect accounts; sessions are regenerated at login.</li>
                <li>M-Pesa secrets, SMS secrets and database credentials are stored outside the source-code repository.</li>
                <li>Payments: group dues and loan repayments are collected only into the <strong>group's own verified Paybill/Till</strong> — group money never routes through the platform. Platform subscriptions are the only payments the platform receives.</li>
            </ul>
        </div>
    </div>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa-solid fa-file-arrow-down text-primary me-2"></i>Your records are never held hostage</h2>
            <p class="mb-0">If a group's subscription expires, the group switches to <strong>read-only</strong>: members and committee can still log in, view everything and download statements and CSV exports. Editing resumes on renewal. On leaving the platform, a group can export its full history.</p>
        </div>
    </div>

    <div class="card mb-3 shadow-sm">
        <div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa-solid fa-message text-primary me-2"></i>Questions &amp; requests</h2>
            <p class="mb-1">Ask your group committee first (they control your records). For platform-level privacy questions or to report a security issue, contact the operator:</p>
            <p class="mb-0"><strong>Email:</strong> privacy@<?= e(preg_replace('#^www\.#', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?> &nbsp;·&nbsp; <strong>Phone:</strong> see your group's onboarding message.</p>
        </div>
    </div>

    <div class="alert alert-secondary small">
        <i class="fa-solid fa-circle-info me-1"></i> This page is a summary, not legal advice. Data-protection obligations under Kenya's Data Protection Act, 2019 (including any ODPC steps and welfare-data impact assessment) are handled with a qualified Kenyan privacy professional.
    </div>

    <p class="text-center text-muted small mb-4">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · <a href="<?= url('index.php') ?>">Home</a> · <a href="<?= url('start.php') ?>">Start a group</a> · <a href="<?= url('register.php') ?>">Join a group</a></p>
</div>
</body>
</html>

<?php
declare(strict_types=1);

// Shared top of the small sign-in pages (forgot / reset password):
// document head, brand, background wheel, and the opening of the card.
// Expects $pageTitle (string) and $cardTitle (string) from the page.
/** @var string $pageTitle */
/** @var string $cardTitle */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#f6efe0">
  <meta name="robots" content="noindex">
  <meta name="referrer" content="no-referrer">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — CycleAudit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/login.css?v=<?= filemtime(__DIR__ . '/../../css/login.css') ?>">
  <script nonce="<?= htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>">document.documentElement.classList.add('js')</script>
</head>
<body class="login">

<div class="stage">

  <a class="brand rise" style="--i:0" href="../index.html" aria-label="CycleAudit home">
    <span class="brand-mark"><svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M19 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M12 19l0 -4l-3 -3l5 -4l2 3l3 0" /> <path d="M17 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg></span>CycleAudit
  </a>

  <a class="back-home rise" style="--i:0" href="login.php">
    <svg class="ti" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/></svg>
    Back to sign in
  </a>

  <section class="pitch">
    <p class="pitch-title"><span class="rise" style="--i:1">Ride.</span> <span class="rise" style="--i:2">Measure.</span> <span class="accent rise" style="--i:3">Improve.</span></p>
    <p class="pitch-sub rise" style="--i:4">Audit every street, score every segment, and publish reports your city can act on.</p>
  </section>

  <main class="zone">

    <?php require __DIR__ . '/wheel.php'; ?>

    <section class="card card-in" aria-labelledby="auth-title">
      <h1 class="card-title" id="auth-title"><?= htmlspecialchars($cardTitle, ENT_QUOTES, 'UTF-8') ?></h1>

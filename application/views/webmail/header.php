<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="tr" data-theme="<?= !empty($theme) ? htmlspecialchars($theme) : 'dark' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= !empty($app_title) ? htmlspecialchars($app_title) : 'Hestia Webmail Suite' ?></title>

  <!-- Favicon / Browser Tab Icon -->
  <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/favicon.svg') ?>?v=1">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= base_url('assets/favicon-32x32.png') ?>?v=1">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= base_url('assets/favicon-16x16.png') ?>?v=1">
  <link rel="shortcut icon" href="<?= base_url('favicon.ico') ?>?v=1">
  <link rel="apple-touch-icon" href="<?= base_url('assets/apple-touch-icon.png') ?>?v=1">
  
  <!-- Modern Google Font: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Webmail Core CSS -->
  <link rel="stylesheet" href="<?= base_url('assets/css/webmail.css') ?>?v=<?= time() ?>">

  <!-- Multi-Language i18n Engine -->
  <script src="<?= base_url('assets/js/i18n.js') ?>?v=<?= time() ?>"></script>
  <script>
    window.BASE_URL = '<?= base_url() ?>';
  </script>
</head>
<body>
<div class="app-container">

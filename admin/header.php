<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? '后台管理' ?></title>
    <link rel="stylesheet" href="<?= $cssPath ?? '../assets/css/style.css' ?>">
</head>
<body class="admin-body">

<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/auth.php';
auth_start();
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');

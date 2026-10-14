<?php
require_once __DIR__ . '/_auth.php';
logout_user();
header('Location: login.php');
exit;

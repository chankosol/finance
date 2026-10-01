<?php
require_once '../includes/config.php';
unset($_SESSION['fin_user_id'], $_SESSION['fin_user_name']);
session_regenerate_id(true);
header('Location: login.php');
exit;

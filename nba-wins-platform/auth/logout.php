<?php
// nba-wins-platform/auth/logout.php
require_once '../config/db_connection.php';

// Logout the user
$auth->logout();

// Redirect to login page
header('Location: login.php?message=logged_out');
exit;
?>
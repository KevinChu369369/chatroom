<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /chatroom/public/login.php');
    exit;
}

<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

// Generate a secure random token
$token = bin2hex(random_bytes(32));


// Store the token in the database with an expiration time
$stmt = $conn->prepare("INSERT INTO ws_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
$stmt->bind_param("is", $_SESSION['user_id'], $token);
$stmt->execute();

// Return the token to the client
header('Content-Type: application/json');
echo json_encode(['token' => $token]);

<?php

declare(strict_types=1);
//CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden");
}
/**
 * Development Admin User Creation Script
 * Creates an admin user with username 'admin' and password 'admin123'
 * WARNING: This is for development use only!
 */

// Database configuration
$db_host = 'localhost';
$db_name = 'chatroom';
$db_user = 'root';
$db_pass = '';


try {
    // Create database connection
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Admin user credentials
    $username = 'admin';
    $email = 'admin@example.com';
    $password = 'admin123';
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Check if admin user already exists
    $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check_stmt->execute([$username, $email]);

    if ($check_stmt->rowCount() > 0) {
        echo "Admin user already exists!\n";
        exit;
    }

    // Insert admin user
    $insert_stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, is_admin, is_active, created_at) 
        VALUES (?, ?, ?, 1, 1, NOW())
    ");

    $insert_stmt->execute([$username, $email, $hashed_password]);

    $admin_user_id = $pdo->lastInsertId();

    // Initialize user status
    $status_stmt = $pdo->prepare("
        INSERT INTO user_status (user_id, is_online, last_seen) 
        VALUES (?, 0, NOW())
    ");
    $status_stmt->execute([$admin_user_id]);

    echo "✅ Admin user created successfully!\n";
    echo "Username: $username\n";
    echo "Password: $password\n";
    echo "Email: $email\n";
    echo "User ID: $admin_user_id\n";
    echo "\n⚠️  WARNING: This is for development use only. Remove this file in production!\n";
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

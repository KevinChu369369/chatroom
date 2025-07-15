<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

// Check if user is admin
$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user['is_admin']) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Room Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">

    <style>
        .card {
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <?php include("components/admin_nav.php") ?>
    <div class="main-layout">
        <div class="container py-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card stat-card bg-primary text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-people-fill stat-icon"></i>
                            <h5 class="card-title">Manage Users</h5>
                            <p class="card-text">Add, edit, or remove user accounts and manage permissions.</p>
                            <a href="users.php" class="btn btn-light">Go to Users</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card stat-card bg-primary text-white">
                        <div class="card-body text-center">
                            <i class="bi bi-chat-dots-fill stat-icon"></i>
                            <h5 class="card-title">Chat System</h5>
                            <p class="card-text">Return to the main chat interface to communicate with users.</p>
                            <a href="../index.php" class="btn btn-light">Go to Chat</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
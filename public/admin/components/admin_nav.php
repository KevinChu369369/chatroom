<?php
require_once __DIR__ . '/../../check_session.php';
?>
<nav class="vertical-nav bg-dark">
    <button class="nav-close-btn d-md-none" type="button">
        <i class="bi bi-x-lg"></i>
    </button>
    <div class="nav-toggle">
        <button class="navbar-toggler" type="button">
            <i class="bi bi-list"></i>
        </button>
    </div>
    <div class="nav-content">
        <div class="nav-header">
            <h6 class="mt-2 mb-0 text-light">Chat Room Admin</h6>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>" href="index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : ''; ?>" href="users.php">
                    <i class="bi bi-people-fill"></i>
                    <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../index.php">
                    <i class="bi bi-chat-dots-fill"></i>
                    <span>Back to Chat</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../auth.php?action=logout">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
<link rel="stylesheet" href="css/vertical_nav.css">
<script src="../js/vertical_nav.js"></script>
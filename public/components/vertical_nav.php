<?php
require_once __DIR__ . '/../check_session.php';
?>
<nav class="vertical-nav">
    <div class="nav-toggle">
        <button class="navbar-toggler" type="button">
            <i class="bi bi-list"></i>
        </button>
    </div>
    <div class="nav-content">
        <div class="nav-header">
            <h6 class="mt-2 mb-0">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></h6>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a class="nav-link active" id="chats-menu-btn" href="#">
                    <i class="bi bi-chat-dots-fill"></i>
                    <span>Chats</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="contacts-menu-btn" href="#">
                    <i class="bi bi-people-fill"></i>
                    <span>Contacts</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#starredModal">
                    <i class="bi bi-star-fill"></i>
                    <span>Starred Messages</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="auth.php?action=logout">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
<script src="js/vertical_nav.js"></script>
<link rel="stylesheet" href="css/vertical_nav.css">
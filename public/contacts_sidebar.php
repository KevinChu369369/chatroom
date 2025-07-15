<?php
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/../config.php';

// Check if this is an AJAX request
function is_ajax_request()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// If not an AJAX request, return 403 Forbidden
if (!is_ajax_request()) {
    header('Location: /chatroom/public/index.php');
    exit;
}

// Get all users except current user
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id != ? ORDER BY username");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$users = $stmt->get_result();
?>

<div class="contacts-sidebar">
    <div class="contact-header">
        <button class="mobile-nav-toggle d-md-none" type="button">
            <i class="bi bi-list"></i>
        </button>
        <h5 class="mb-0">Contacts</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal">
            <i class="bi bi-people-fill"></i>
        </button>
    </div>

    <div class="search-box">
        <input type="text" id="contactSearch" placeholder="Search contacts...">
    </div>

    <div class="contacts-list">
        <?php while ($user = $users->fetch_assoc()): ?>
            <div class="contact-item" onclick="startChat(<?php echo $user['id']; ?>)">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                </div>
                <div class="contact-info">
                    <div class="contact-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="contact-email"><?php echo htmlspecialchars($user['email']); ?></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>
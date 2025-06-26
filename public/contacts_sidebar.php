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
    <div class="sidebar-header">
        <h5 class="mb-0">Contacts</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal">
            <i class="bi bi-people-fill"></i> New Group
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

<!-- Create Group Modal -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createGroupForm">
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" class="form-control" name="group_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Members</label>
                        <div class="border p-3 rounded" style="max-height: 200px; overflow-y: auto;">
                            <?php
                            $users->data_seek(0); // Reset the result pointer
                            while ($user = $users->fetch_assoc()):
                            ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="members[]"
                                        value="<?php echo $user['id']; ?>" id="user<?php echo $user['id']; ?>">
                                    <label class="form-check-label" for="user<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </label>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <div class="alert alert-danger" style="display: none;"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createGroup()">Create Group</button>
            </div>
        </div>
    </div>
</div>
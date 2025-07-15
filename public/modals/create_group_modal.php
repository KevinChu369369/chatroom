<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

// Get all users except current user for the modal
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id != ? ORDER BY username");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$users = $stmt->get_result();
?>

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

<style>
    /* Modal specific overrides */
    #createGroupModal .modal-content {
        background-color: var(--app-bg);
        color: var(--app-text);
    }

    #createGroupModal .modal-header {
        border-bottom-color: var(--app-border);
    }

    #createGroupModal .btn-close {
        color: var(--app-text);
    }

    #createGroupModal .form-check {
        margin-bottom: 8px;
    }

    #createGroupModal .form-check:last-child {
        margin-bottom: 0;
    }
</style>
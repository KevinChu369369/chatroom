<div class="modal fade" id="leaveAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Leave as Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Please select a new admin before leaving:</p>
                <select class="form-select" id="newAdminSelect">
                    <option value="">Select a new admin...</option>
                    <?php
                    $stmt = $conn->prepare("
                        SELECT u.id, u.username 
                        FROM users u 
                        JOIN chatroom_members cm ON u.id = cm.user_id 
                        WHERE cm.chatroom_id = ? AND cm.is_active = TRUE AND u.id != ?
                    ");
                    $stmt->bind_param("ii", $current_room, $_SESSION['user_id']);
                    $stmt->execute();
                    $members = $stmt->get_result();
                    while ($member = $members->fetch_assoc()) {
                        echo "<option value='" . $member['id'] . "'>" . htmlspecialchars($member['username']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmLeaveAsAdmin()">Leave</button>
            </div>
        </div>
    </div>
</div>
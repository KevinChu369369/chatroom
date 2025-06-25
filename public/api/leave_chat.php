<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chatroom_id = isset($_POST['chatroom_id']) ? (int)$_POST['chatroom_id'] : 0;
    $new_admin_id = isset($_POST['new_admin_id']) ? (int)$_POST['new_admin_id'] : 0;

    if (!$chatroom_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid chatroom ID']);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Check if user is a member of the chatroom
        $stmt = $conn->prepare("
            SELECT 1 FROM chatroom_members 
            WHERE chatroom_id = ? AND user_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception('You are not a member of this chatroom');
        }

        // Check if it's a group chat
        $stmt = $conn->prepare("SELECT is_group, created_by FROM chatrooms WHERE id = ?");
        $stmt->bind_param("i", $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $chatroom = $result->fetch_assoc();

        if (!$chatroom['is_group']) {
            throw new Exception('Cannot leave a direct chat');
        }

        // Check if user is the creator
        $is_creator = $chatroom['created_by'] === $_SESSION['user_id'];

        // Count active members
        $stmt = $conn->prepare("
            SELECT COUNT(*) as active_count 
            FROM chatroom_members 
            WHERE chatroom_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("i", $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $active_count = $result->fetch_assoc()['active_count'];

        if ($is_creator) {
            if ($active_count > 1) {
                // If there are other active members, require new admin selection
                if (!$new_admin_id) {
                    throw new Exception('Please select a new admin');
                }

                // Verify new admin is an active member
                $stmt = $conn->prepare("
                    SELECT 1 FROM chatroom_members 
                    WHERE chatroom_id = ? AND user_id = ? AND is_active = TRUE
                ");
                $stmt->bind_param("ii", $chatroom_id, $new_admin_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    throw new Exception('Selected user is not an active member');
                }

                // Update chatroom creator
                $stmt = $conn->prepare("
                    UPDATE chatrooms 
                    SET created_by = ? 
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $new_admin_id, $chatroom_id);
                $stmt->execute();
            }
            // If there's only one active member (the creator), allow leaving without selecting new admin
        }

        // Set user as inactive
        $stmt = $conn->prepare("
            UPDATE chatroom_members 
            SET is_active = FALSE 
            WHERE chatroom_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

$conn->close();

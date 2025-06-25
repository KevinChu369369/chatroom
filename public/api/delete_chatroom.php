<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chatroom_id = (int)$_POST['chatroom_id'];

    // Verify the user is a member of the chatroom
    $stmt = $conn->prepare("
        SELECT 1 FROM chatroom_members
        WHERE chatroom_id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'You are not an active member of this chatroom'
        ]);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            REPLACE INTO deleted_messages (user_id, chatroom_id, deleted_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->bind_param("ii", $_SESSION['user_id'], $chatroom_id);
        $stmt->execute();

        // Delete all unread messages for this user in this chatroom
        $stmt = $conn->prepare("
            DELETE FROM unread_messages
            WHERE chatroom_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
        $stmt->execute();

        // Commit the transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Chatroom history deleted'
        ]);
    } catch (Exception $e) {
        // Rollback the transaction on error
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete chatroom history'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}

$conn->close();

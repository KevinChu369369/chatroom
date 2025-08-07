<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

// Function to check if user has access to a message
function hasMessageAccess($conn, $message_id, $user_id)
{
    $stmt = $conn->prepare("
        SELECT 1 
        FROM messages m
        JOIN chatroom_members cm ON m.chatroom_id = cm.chatroom_id
        WHERE m.id = ? AND cm.user_id = ? AND cm.is_active = TRUE
    ");
    $stmt->bind_param("ii", $message_id, $user_id);
    $stmt->execute();
    $has_access = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $has_access;
}

// Function to check if message is starred
function isMessageStarred($conn, $message_id, $user_id)
{
    $stmt = $conn->prepare("
        SELECT 1 
        FROM starred_messages 
        WHERE message_id = ? AND user_id = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ii", $message_id, $user_id);
    $stmt->execute();
    $is_starred = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $is_starred;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!$message_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid message ID']);
        exit;
    }

    // Verify the message exists and user has access to it
    if (!hasMessageAccess($conn, $message_id, $_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Message not found or access denied']);
        exit;
    }

    if ($action === 'star') {
        // Check if message is already starred
        if (isMessageStarred($conn, $message_id, $_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Message is already starred']);
            exit;
        }

        // Insert new star
        //Make sure if the record is exists, we can star it again. There is restriction to insert duplicate record.
        $stmt = $conn->prepare("
            INSERT INTO starred_messages (message_id, user_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE deleted_at = NULL
        ");

        $stmt->bind_param("ii", $message_id, $_SESSION['user_id']);
        $success = $stmt->execute();
        $stmt->close();
        $conn->close();

        echo json_encode(['success' => $success, 'starred' => true]);
    } elseif ($action === 'unstar' || $action === 'delete') {
        // Check if message is actually starred before trying to unstar
        if (!isMessageStarred($conn, $message_id, $_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Message is not starred']);
            exit;
        }

        // Remove star
        $stmt = $conn->prepare("
            UPDATE starred_messages 
            SET deleted_at = CURRENT_TIMESTAMP
            WHERE message_id = ? AND user_id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $message_id, $_SESSION['user_id']);
        $success = $stmt->execute();
        $stmt->close();


        if ($success) {
            // Update the star button in the chatroom if the message exists
            $stmt = $conn->prepare("
                SELECT 1 FROM messages WHERE id = ?
            ");
            $stmt->bind_param("i", $message_id);
            $stmt->execute();
            $message_exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($message_exists) {
                // Return additional data to update the UI
                echo json_encode([
                    'success' => true,
                    'starred' => false,
                    'update_ui' => true,
                    'message_id' => $message_id
                ]);
            } else {
                echo json_encode(['success' => true, 'starred' => false]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to unstar message']);
        }
        $conn->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// GET request to check if a message is starred or get starred messages list
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;

    if (!$message_id) {
        // Get starred messages list for the current user with more details
        // Only include messages from chatrooms where the user is still an active member
        $stmt = $conn->prepare("
            SELECT m.id, m.content, m.created_at as timestamp,
                   u.username, u.id as user_id,
                   c.name as chatroom_name, c.id as chatroom_id,
                   us.is_online, us.last_seen
            FROM starred_messages sm
            JOIN messages m ON sm.message_id = m.id
            JOIN users u ON m.user_id = u.id
            JOIN chatrooms c ON m.chatroom_id = c.id
            JOIN chatroom_members cm ON m.chatroom_id = cm.chatroom_id AND cm.user_id = ?
            LEFT JOIN user_status us ON u.id = us.user_id
            WHERE sm.user_id = ? AND sm.deleted_at IS NULL AND cm.is_active = TRUE
            ORDER BY sm.starred_at DESC
        ");

        $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'user_id' => $row['user_id'],
                'content' => $row['content'],
                'timestamp' => $row['timestamp'],
                'chatroom_name' => $row['chatroom_name'],
                'chatroom_id' => $row['chatroom_id'],
                'is_online' => (bool)$row['is_online'],
                'last_seen' => $row['last_seen']
            ];
        }

        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'messages' => $messages]);
    } else {
        // Check if a specific message is starred
        $is_starred = isMessageStarred($conn, $message_id, $_SESSION['user_id']);
        echo json_encode(['success' => true, 'starred' => $is_starred]);
        $conn->close();
    }
}

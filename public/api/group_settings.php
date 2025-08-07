<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_settings':
        handle_get_settings($conn);
        break;
    case 'update_settings':
        handle_update_settings($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function handle_get_settings($conn)
{
    $chatroom_id = isset($_POST['chatroom_id']) ? (int)$_POST['chatroom_id'] : 0;

    if (!$chatroom_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid chatroom ID']);
        exit;
    }

    try {
        // Check if user is a member of the chatroom and if it's a group
        $stmt = $conn->prepare("
            SELECT c.id, c.name, c.description, c.created_by, c.is_group
            FROM chatrooms c
            JOIN chatroom_members cm ON c.id = cm.chatroom_id
            WHERE c.id = ? AND cm.user_id = ? AND cm.is_active = TRUE AND c.is_group = TRUE
        ");
        $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $chatroom = $result->fetch_assoc();

        if (!$chatroom) {
            echo json_encode(['success' => false, 'message' => 'Chatroom not found or you are not a member']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'settings' => [
                'id' => $chatroom['id'],
                'name' => $chatroom['name'],
                'description' => $chatroom['description'] ?? ''
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to get group settings']);
    }
}

function handle_update_settings($conn)
{
    $chatroom_id = isset($_POST['chatroom_id']) ? (int)$_POST['chatroom_id'] : 0;
    $group_name = trim($_POST['group_name'] ?? '');
    $group_description = trim($_POST['group_description'] ?? '');

    if (!$chatroom_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid chatroom ID']);
        exit;
    }

    if (empty($group_name)) {
        echo json_encode(['success' => false, 'message' => 'Group name is required']);
        exit;
    }

    if (strlen($group_name) > 30) {
        echo json_encode(['success' => false, 'message' => 'Group name is too long (maximum 30 characters)']);
        exit;
    }

    if (strlen($group_description) > 255) {
        echo json_encode(['success' => false, 'message' => 'Description is too long (maximum 255 characters)']);
        exit;
    }

    try {
        // Check if user is the creator of the chatroom
        $stmt = $conn->prepare("
            SELECT c.id, c.name, c.description, c.created_by
            FROM chatrooms c
            JOIN chatroom_members cm ON c.id = cm.chatroom_id
            WHERE c.id = ? AND cm.user_id = ? AND cm.is_active = TRUE AND c.is_group = TRUE
        ");
        $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $chatroom = $result->fetch_assoc();

        if (!$chatroom) {
            echo json_encode(['success' => false, 'message' => 'Chatroom not found or you are not a member']);
            exit;
        }

        if ($chatroom['created_by'] !== $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Only group creators can update settings']);
            exit;
        }

        // Check if there are any changes
        $name_changed = $chatroom['name'] !== $group_name;
        $description_changed = ($chatroom['description'] ?? '') !== $group_description;

        if (!$name_changed && !$description_changed) {
            echo json_encode(['success' => true, 'message' => 'No changes detected']);
            exit;
        }

        // Update the chatroom settings
        $stmt = $conn->prepare("
            UPDATE chatrooms 
            SET name = ?, description = ? 
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $group_name, $group_description, $chatroom_id);
        $stmt->execute();

        // Create system message for settings update
        $changes = [];
        if ($name_changed) {
            $changes[] = "name";
        }
        if ($description_changed) {
            $changes[] = "description";
        }

        $change_text = implode(" and ", $changes);
        $system_message = "Group $change_text updated";

        // Store system message for all active members
        $stmt = $conn->prepare("
            INSERT INTO messages (chatroom_id, user_id, content, is_system)
            SELECT ?, cm.user_id, ?, TRUE
            FROM chatroom_members cm
            WHERE cm.chatroom_id = ? AND cm.is_active = TRUE
        ");
        $stmt->bind_param("isi", $chatroom_id, $system_message, $chatroom_id);
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Group settings updated successfully',
            'settings' => [
                'id' => $chatroom_id,
                'name' => $group_name,
                'description' => $group_description
            ],
            'system_message' => $system_message,
            'name_changed' => $name_changed,
            'description_changed' => $description_changed
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update group settings']);
    }
}

$conn->close();

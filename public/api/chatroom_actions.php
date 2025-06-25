<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_chatroom':
            $name = trim($_POST['name'] ?? '');
            $members = $_POST['members'] ?? [];

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Chatroom name is required']);
                exit;
            }

            // Start transaction
            $conn->begin_transaction();

            try {
                // Create chatroom
                $stmt = $conn->prepare("INSERT INTO chatrooms (name, created_by) VALUES (?, ?)");
                $stmt->bind_param("si", $name, $_SESSION['user_id']);
                $stmt->execute();
                $chatroom_id = $conn->insert_id;

                // Add creator as member
                $stmt = $conn->prepare("INSERT INTO chatroom_members (chatroom_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
                $stmt->execute();

                // Add selected members
                if (!empty($members)) {
                    $stmt = $conn->prepare("INSERT INTO chatroom_members (chatroom_id, user_id) VALUES (?, ?)");
                    foreach ($members as $member_id) {
                        $stmt->bind_param("ii", $chatroom_id, $member_id);
                        $stmt->execute();
                    }
                }

                $conn->commit();
                echo json_encode(['success' => true, 'chatroom_id' => $chatroom_id]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to create chatroom']);
            }
            break;

        case 'create_direct_chat':
            $user_id = (int)($_POST['user_id'] ?? 0);

            if ($user_id === 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                exit;
            }

            // Check if direct chat already exists
            $stmt = $conn->prepare("
                SELECT c.id 
                FROM chatrooms c 
                JOIN chatroom_members cm1 ON c.id = cm1.chatroom_id 
                JOIN chatroom_members cm2 ON c.id = cm2.chatroom_id 
                WHERE cm1.user_id = ? AND cm2.user_id = ? 
                AND NOT EXISTS (
                    SELECT 1 FROM chatroom_members cm3 
                    WHERE cm3.chatroom_id = c.id 
                    AND cm3.user_id NOT IN (?, ?)
                )
            ");
            $stmt->bind_param("iiii", $_SESSION['user_id'], $user_id, $_SESSION['user_id'], $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $chatroom = $result->fetch_assoc();
                $chatroom_id = $chatroom['id'];

                // Get chatroom details
                $stmt = $conn->prepare("
                    SELECT c.*, u.username as creator_name
                    FROM chatrooms c
                    JOIN users u ON c.created_by = u.id
                    WHERE c.id = ?
                ");
                $stmt->bind_param("i", $chatroom_id);
                $stmt->execute();
                $chatroom_result = $stmt->get_result();
                $chatroom_data = $chatroom_result->fetch_assoc();

                // Format chatroom data
                $chatroom = [
                    'id' => $chatroom_id,
                    'name' => $chatroom_data['name'],
                    'is_group' => false,
                    'unread_count' => 0,
                    'latest_message' => null
                ];

                echo json_encode(['success' => true, 'chatroom' => $chatroom]);
                exit;
            }

            // Get both users' usernames for chatroom name
            $stmt = $conn->prepare("SELECT username,id FROM users WHERE id IN (?, ?)");
            $stmt->bind_param("ii", $_SESSION['user_id'], $user_id);
            $stmt->execute();
            $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Find the other user's name
            $other_user_name = '';
            foreach ($users as $user) {
                if ($user['id'] != $_SESSION['user_id']) {
                    $other_user_name = $user['username'];
                    break;
                }
            }


            // Start transaction
            $conn->begin_transaction();

            try {
                // Create direct chat with the other user's name
                $name = $other_user_name;
                $stmt = $conn->prepare("INSERT INTO chatrooms (name, created_by) VALUES (?, ?)");
                $stmt->bind_param("si", $name, $_SESSION['user_id']);
                $stmt->execute();
                $chatroom_id = $conn->insert_id;

                // Add both users as members
                $stmt = $conn->prepare("INSERT INTO chatroom_members (chatroom_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
                $stmt->execute();
                $stmt->bind_param("ii", $chatroom_id, $user_id);
                $stmt->execute();

                $conn->commit();

                // Return full chatroom data
                $chatroom = [
                    'id' => $chatroom_id,
                    'name' => $name,
                    'is_group' => false,
                    'unread_count' => 0,
                    'latest_message' => null
                ];

                echo json_encode(['success' => true, 'chatroom' => $chatroom]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to create direct chat']);
            }
            break;

        case 'create_group':
            // Validate group name
            $group_name = trim($_POST['group_name']);
            if (empty($group_name)) {
                echo json_encode(['success' => false, 'message' => 'Group name is required']);
                exit;
            }

            // Validate members
            if (!isset($_POST['members']) || !is_array($_POST['members']) || empty($_POST['members'])) {
                echo json_encode(['success' => false, 'message' => 'Please select at least one member']);
                exit;
            }

            // Start transaction
            $conn->begin_transaction();

            try {
                // Create the chatroom
                $stmt = $conn->prepare("INSERT INTO chatrooms (name, created_by, is_group) VALUES (?, ?, 1)");
                $stmt->bind_param("si", $group_name, $_SESSION['user_id']);
                $stmt->execute();
                $chatroom_id = $conn->insert_id;

                // Add the creator as a member
                $stmt = $conn->prepare("INSERT INTO chatroom_members (chatroom_id, user_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $chatroom_id, $_SESSION['user_id']);
                $stmt->execute();

                // Add selected members
                foreach ($_POST['members'] as $member_id) {
                    $member_id = (int)$member_id;
                    if ($member_id != $_SESSION['user_id']) {
                        // Skip if it's the creator
                        $stmt->bind_param("ii", $chatroom_id, $member_id);
                        $stmt->execute();
                    }
                }

                // Commit transaction
                $conn->commit();
                echo json_encode(['success' => true, 'chatroom_id' => $chatroom_id]);
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to create group chat']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

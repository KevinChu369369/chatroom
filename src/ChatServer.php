<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../docker_config.php';

use Swoole\WebSocket\Server;

class ChatServer
{
    protected $user_connections = [];
    protected $db;
    protected $server;

    public function __construct()
    {
        try {
            global $host, $username, $password, $db_name;

            $this->user_connections = [];

            // Set timezone to GMT+8
            date_default_timezone_set('Asia/Hong_Kong');

            // Add error handling for database connection
            $this->db = new mysqli($host, $username, $password, $db_name);
            if ($this->db->connect_error) {
                throw new Exception("Database connection failed: " . $this->db->connect_error);
            }
        } catch (Exception $e) {
            echo date('Y-m-d H:i:s') . " Error in constructor: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    public function onStart($server)
    {
        $this->server = $server;
        echo date('Y-m-d H:i:s') . " Swoole WebSocket Server started\n";
    }

    public function onOpen($server, $request)
    {
        echo date('Y-m-d H:i:s') . " Connection opened: {$request->fd}\n";
    }

    public function onMessage($server, $frame)
    {
        try {
            $data = json_decode($frame->data, true);
            if (!$data || !isset($data['type'])) {
                return;
            }

            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($server, $frame->fd, $data);
                    break;
                case 'join':
                    $this->handleJoin($server, $frame->fd, $data);
                    break;
                case 'leave':
                    $this->handleLeave($server, $frame->fd, $data);
                    break;
                case 'message':
                    $this->handleChat($server, $frame->fd, $data);
                    break;
                case 'history':
                    $this->handleHistory($server, $frame->fd, $data);
                    break;
                case 'sync':
                    $this->handleSync($server, $frame->fd, $data);
                    break;
                case 'mark_messages_as_read':
                    $this->handleMarkMessagesAsRead($server, $frame->fd, $data);
                    break;
                case 'get_unread_counts':
                    $this->handleGetUnreadCounts($server, $frame->fd, $data);
                    break;
                case 'update_chatroom_list':
                    $this->handleUpdateChatroomList($server, $frame->fd, $data);
                    break;
                case 'load_messages':
                    $this->handleLoadMessages($server, $frame->fd, $data);
                    break;
                default:
                    $this->sendToClient($server, $frame->fd, [
                        'type' => 'error',
                        'message' => 'Unknown message type'
                    ]);
            }
        } catch (Exception $e) {
            echo date('Y-m-d H:i:s') . " Error: " . $e->getMessage() . "\n";
            $this->sendToClient($server, $frame->fd, [
                'type' => 'error',
                'message' => 'An error occurred while processing your request'
            ]);
        }
    }

    public function onClose($server, $fd)
    {
        echo date('Y-m-d H:i:s') . " Connection closed: fd id: {$fd}\n";
        // Remove user from connections
        $user_id = $this->getUserIdByFd($fd);
        if ($user_id !== null) {
            unset($this->user_connections[$user_id]);
        }
    }

    private function handleAuth($server, $fd, $data)
    {
        if (!isset($data['token']) || !isset($data['user_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'authentication failed'
            ]);
            return;
        }

        // Validate the token from the database
        $stmt = $this->db->prepare("
            SELECT user_id 
            FROM ws_tokens 
            WHERE token = ? 
            AND expires_at > NOW() 
            AND user_id = ?
        ");
        $stmt->bind_param("si", $data['token'], $data['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Invalid or expired token'
            ]);
            return;
        }

        // Store the connection with the user ID
        $this->user_connections[$row['user_id']] = $fd;

        // Send success response
        $this->sendToClient($server, $fd, [
            'type' => 'auth',
            'status' => 'success'
        ]);

        // Clean up expired tokens
        $this->db->query("DELETE FROM ws_tokens WHERE expires_at <= NOW()");
    }


    private function handleJoin($server, $fd, $data)
    {
        if (!isset($data['chatroom_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID is required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $user_id = $this->getUserIdByFd($fd);

        // Check if user is a member of the chatroom in database
        $stmt = $this->db->prepare("
            SELECT * FROM chatroom_members 
            JOIN chatrooms ON chatrooms.id = chatroom_members.chatroom_id
            WHERE user_id = ? AND chatroom_id = ? AND is_active = TRUE LIMIT 1
        ");
        $stmt->bind_param("ii", $user_id, $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $row = $result->fetch_assoc();

        if (!$row) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'You are not a member of this chatroom'
            ]);
            return;
        }

        $room_name = $row['name'];

        // Send join success response
        $this->sendToClient($server, $fd, [
            'type' => 'join',
            'success' => true,
            'room_name' => $room_name,
            'chatroom_id' => $chatroom_id
        ]);
    }

    private function handleLeave($server, $fd, $data)
    {
        if (!isset($data['chatroom_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID is required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];

        $this->sendToClient($server, $fd, [
            'type' => 'leave',
            'success' => true,
            'chatroom_id' => $chatroom_id
        ]);
    }

    private function handleChat($server, $fd, $data)
    {
        if (!isset($data['chatroom_id']) || !isset($data['message'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID and message are required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $message = $data['message'];
        $user_id = $this->getUserIdByFd($fd);

        // Check if user is a member of the chatroom
        $stmt = $this->db->prepare("
            SELECT 1 FROM chatroom_members 
            WHERE user_id = ? AND chatroom_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("ii", $user_id, $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result->fetch_assoc()) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'You are not a member of this chatroom'
            ]);
            return;
        }

        // Insert message into database
        $stmt = $this->db->prepare("
            INSERT INTO messages (chatroom_id, user_id, content, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param("iis", $chatroom_id, $user_id, $message);
        $stmt->execute();
        $message_id = $this->db->insert_id;

        // Create unread message records for all other members in the chatroom
        $stmt = $this->db->prepare("
            INSERT INTO unread_messages (message_id, user_id, chatroom_id, is_read)
            SELECT ?, cm.user_id, ?, FALSE
            FROM chatroom_members cm
            WHERE cm.chatroom_id = ? AND cm.user_id != ? AND cm.is_active = TRUE
        ");
        $stmt->bind_param("iiii", $message_id, $chatroom_id, $chatroom_id, $user_id);
        $stmt->execute();

        // Get user info for the message
        $stmt = $this->db->prepare("
            SELECT username
            FROM users
            WHERE id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_info = $result->fetch_assoc();

        // Create message data
        $messageData = [
            'type' => 'message',
            'id' => $message_id,
            'chatroom_id' => $chatroom_id,
            'user_id' => $user_id,
            'username' => $user_info['username'],
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Get all active members from the database
        $stmt = $this->db->prepare("
            SELECT user_id 
            FROM chatroom_members 
            WHERE chatroom_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("i", $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();

        // Broadcast to all active members who have a connection
        while ($member = $result->fetch_assoc()) {
            $member_id = $member['user_id'];
            if (isset($this->user_connections[$member_id])) {
                $this->sendToClient($server, $this->user_connections[$member_id], $messageData);
            }
        }
    }

    private function handleHistory($server, $fd, $data)
    {
        if (!isset($data['chatroom_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID is required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $user_id = $this->getUserIdByFd($fd);
        $target_message_id = isset($data['target_message_id']) ? (int)$data['target_message_id'] : null;

        // Check if user is a member of the chatroom
        if (!$this->isUserInChatroom($user_id, $chatroom_id)) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'You are not a member of this chatroom'
            ]);
            return;
        }

        // Get message history based on whether we have a target message
        if ($target_message_id) {
            // Get messages around the target message
            $stmt = $this->db->prepare("
                (SELECT m.*, u.username, 'before' as message_group
                FROM messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                WHERE m.chatroom_id = ? AND m.id < ?
                AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                ORDER BY m.created_at DESC
                LIMIT 25)
                UNION ALL
                (SELECT m.*, u.username, 'target' as message_group
                FROM messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                WHERE m.chatroom_id = ? AND m.id = ?
                AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at))
                UNION ALL
                (SELECT m.*, u.username, 'after' as message_group
                FROM messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                WHERE m.chatroom_id = ? AND m.id > ?
                AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                ORDER BY m.created_at ASC
                LIMIT 25)
                ORDER BY created_at ASC
            ");
            $stmt->bind_param(
                "iiiiiiiii",
                $user_id,
                $chatroom_id,
                $target_message_id,  // for 'before' query
                $user_id,
                $chatroom_id,
                $target_message_id,  // for 'target' query
                $user_id,
                $chatroom_id,
                $target_message_id   // for 'after' query
            );
        } else {
            // Get the oldest unread message ID for this user in this chatroom
            $stmt = $this->db->prepare("
                SELECT MIN(m.id) as oldest_unread_id, COUNT(*) as unread_count
                FROM messages m
                JOIN unread_messages um ON m.id = um.message_id AND um.user_id = ?
                WHERE m.chatroom_id = ? AND um.is_read = FALSE
            ");
            $stmt->bind_param("ii", $user_id, $chatroom_id);
            $stmt->execute();
            $unread_result = $stmt->get_result();
            $oldest_unread = $unread_result->fetch_assoc();
            $oldest_unread_id = $oldest_unread['oldest_unread_id'];
            $unread_count = $oldest_unread['unread_count'];

            // Get message history, excluding deleted messages
            if ($oldest_unread_id) {
                $stmt = $this->db->prepare("
                    (SELECT m.*, u.username, 'before' as message_group
                    FROM messages m
                    JOIN users u ON m.user_id = u.id
                    LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                    WHERE m.chatroom_id = ? AND m.id < ?
                    AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                    ORDER BY m.created_at DESC
                    LIMIT 25)
                    UNION ALL
                    (SELECT m.*, u.username, 'unread' as message_group
                    FROM messages m
                    JOIN users u ON m.user_id = u.id
                    LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                    WHERE m.chatroom_id = ? AND m.id >= ?
                    AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                    ORDER BY m.created_at ASC)
                    ORDER BY created_at ASC
                ");
                $stmt->bind_param("iiiiii", $user_id, $chatroom_id, $oldest_unread_id, $user_id, $chatroom_id, $oldest_unread_id);
            } else {
                $stmt = $this->db->prepare("
                    SELECT m.*, u.username, 'read' as message_group
                    FROM messages m
                    JOIN users u ON m.user_id = u.id
                    LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                    WHERE m.chatroom_id = ?
                    AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                    ORDER BY m.created_at DESC
                    LIMIT 50
                ");
                $stmt->bind_param("ii", $user_id, $chatroom_id);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        // For the case where we got messages in DESC order, reverse them
        if (!$target_message_id && !$oldest_unread_id) {
            $messages = array_reverse($messages);
        }

        // Format messages and mark unread status
        $formatted_messages = array_map(function ($msg) use ($user_id) {
            // Check if this message is unread for the current user
            $stmt = $this->db->prepare("
                SELECT is_read FROM unread_messages 
                WHERE message_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $msg['id'], $user_id);
            $stmt->execute();
            $unread_result = $stmt->get_result();
            $unread_row = $unread_result->fetch_assoc();

            // Message is unread if: there's an unread record with is_read = FALSE
            $is_unread = $unread_row && $unread_row['is_read'] == 0;

            return [
                'id' => $msg['id'],
                'chatroom_id' => $msg['chatroom_id'],
                'user_id' => $msg['user_id'],
                'username' => $msg['username'],
                'content' => $msg['content'],
                'created_at' => date('Y-m-d H:i:s', strtotime($msg['created_at'])),
                'is_unread' => $is_unread
            ];
        }, $messages);

        $this->sendToClient($server, $fd, [
            'type' => 'history',
            'chatroom_id' => $chatroom_id,
            'messages' => $formatted_messages,
            'target_message_id' => $target_message_id,
            'has_more_messages' => count($messages) >= 25,
            'oldest_unread_id' => isset($oldest_unread_id) ? $oldest_unread_id : null,
            'unread_count' => isset($unread_count) ? $unread_count : 0
        ]);
    }

    private function handleSync($server, $fd, $data)
    {
        if (!isset($data['chatroom_id']) || !isset($data['last_message_id'])) {
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $last_message_id = $data['last_message_id'];
        $user_id = $this->getUserIdByFd($fd);

        // Get any new messages since last_message_id
        $stmt = $this->db->prepare("
            SELECT m.*, u.username
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.chatroom_id = ? AND m.id > ? AND m.user_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->bind_param("iii", $chatroom_id, $last_message_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $new_messages = [];
        while ($row = $result->fetch_assoc()) {
            $new_messages[] = [
                'type' => 'message',
                'id' => $row['id'],
                'chatroom_id' => $row['chatroom_id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'message' => $row['content'],
                'created_at' => $row['created_at']
            ];
        }

        if (!empty($new_messages)) {
            $this->sendToClient($server, $fd, [
                'type' => 'sync',
                'messages' => $new_messages
            ]);
        }
    }

    private function handleMarkMessagesAsRead($server, $fd, $data)
    {
        if (!isset($data['chatroom_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID is required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $user_id = $this->getUserIdByFd($fd);

        // First, ensure all messages have an unread record
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO unread_messages (message_id, user_id, chatroom_id, is_read)
            SELECT m.id, ?, m.chatroom_id, FALSE
            FROM messages m
            WHERE m.chatroom_id = ? AND m.user_id != ?
        ");
        $stmt->bind_param("iii", $user_id, $chatroom_id, $user_id);
        $stmt->execute();

        // Then mark all unread messages as read
        $stmt = $this->db->prepare("
            UPDATE unread_messages 
            SET is_read = TRUE 
            WHERE chatroom_id = ? AND user_id = ? AND is_read = FALSE
        ");
        $stmt->bind_param("ii", $chatroom_id, $user_id);
        $stmt->execute();

        // Send confirmation back to client
        $this->sendToClient($server, $fd, [
            'type' => 'messages_marked_as_read',
            'chatroom_id' => $chatroom_id,
            'success' => true
        ]);
    }

    private function handleGetUnreadCounts($server, $fd, $data)
    {
        $user_id = $this->getUserIdByFd($fd);
        $current_chatroom_id = isset($data['current_chatroom_id']) ? $data['current_chatroom_id'] : null;

        // Get unread message counts for all chatrooms, excluding the current chatroom
        $stmt = $this->db->prepare("
            SELECT 
                c.id, 
                c.name, 
                c.is_group,
                CASE 
                    WHEN c.id = ? THEN 0
                    ELSE COUNT(DISTINCT um.id)
                END as unread_count
            FROM chatroom_members cm
            JOIN chatrooms c ON cm.chatroom_id = c.id
            LEFT JOIN unread_messages um ON cm.chatroom_id = um.chatroom_id 
                AND cm.user_id = um.user_id 
                AND um.is_read = FALSE
            WHERE cm.user_id = ? AND cm.is_active = TRUE
            GROUP BY c.id, c.name, c.is_group
            ORDER BY c.name
        ");
        $stmt->bind_param("ii", $current_chatroom_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $chatrooms = [];
        while ($row = $result->fetch_assoc()) {
            $chatrooms[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'is_group' => $row['is_group'],
                'unread_count' => (int)$row['unread_count']
            ];
        }

        $this->sendToClient($server, $fd, [
            'type' => 'get_unread_counts',
            'chatrooms' => $chatrooms
        ]);
    }

    private function handleUpdateChatroomList($server, $fd, $data)
    {
        $user_id = $this->getUserIdByFd($fd);

        // Get all active chatrooms for the user
        $stmt = $this->db->prepare("
            SELECT 
                c.id, 
                c.name, 
                c.is_group,
                (SELECT COUNT(*) FROM unread_messages um 
                 WHERE um.chatroom_id = c.id 
                 AND um.user_id = ? 
                 AND um.is_read = FALSE) as unread_count,
                (SELECT m.content 
                 FROM messages m 
                 LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                 WHERE m.chatroom_id = c.id 
                 AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                 ORDER BY m.created_at DESC LIMIT 1) as latest_message
            FROM chatroom_members cm
            JOIN chatrooms c ON cm.chatroom_id = c.id
            WHERE cm.user_id = ? AND cm.is_active = TRUE
            ORDER BY c.name
        ");
        $stmt->bind_param("iii", $user_id, $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $chatrooms = [];
        while ($row = $result->fetch_assoc()) {
            $chatrooms[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'is_group' => $row['is_group'],
                'unread_count' => (int)$row['unread_count'],
                'latest_message' => $row['latest_message']
            ];
        }

        $this->sendToClient($server, $fd, [
            'type' => 'update_chatroom_list',
            'chatrooms' => $chatrooms
        ]);
    }

    private function handleLoadMessages($server, $fd, $data)
    {
        if (!isset($data['chatroom_id']) || !isset($data['direction']) || !isset($data['reference_message_id'])) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'Chatroom ID, direction, and reference message ID are required'
            ]);
            return;
        }

        $chatroom_id = $data['chatroom_id'];
        $direction = $data['direction'];
        $reference_message_id = $data['reference_message_id'];
        $user_id = $this->getUserIdByFd($fd);

        // Check if user is a member of the chatroom
        if (!$this->isUserInChatroom($user_id, $chatroom_id)) {
            $this->sendToClient($server, $fd, [
                'type' => 'error',
                'message' => 'You are not a member of this chatroom'
            ]);
            return;
        }

        // Prepare the SQL query based on direction
        if ($direction === 'older') {
            $stmt = $this->db->prepare("
                SELECT m.*, u.username
                FROM messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                WHERE m.chatroom_id = ? AND m.id < ?
                AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                ORDER BY m.created_at DESC
                LIMIT 50
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT m.*, u.username
                FROM messages m
                JOIN users u ON m.user_id = u.id
                LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
                WHERE m.chatroom_id = ? AND m.id > ?
                AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
                ORDER BY m.created_at ASC
                LIMIT 50
            ");
        }

        $stmt->bind_param("iii", $user_id, $chatroom_id, $reference_message_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => $row['id'],
                'chatroom_id' => $row['chatroom_id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'content' => $row['content'],
                'created_at' => date('Y-m-d H:i:s', strtotime($row['created_at'])),
                'is_unread' => false // Loaded messages are always considered read
            ];
        }

        // For older messages, we need to reverse the order to show oldest first
        if ($direction === 'older') {
            $messages = array_reverse($messages);
        }

        $this->sendToClient($server, $fd, [
            'type' => 'loaded_messages',
            'direction' => $direction,
            'chatroom_id' => $chatroom_id,
            'messages' => $messages,
            'has_more_messages' => count($messages) >= 50,
            'should_scroll' => $direction === 'newer' && count($messages) > 0
        ]);
    }

    private function isUserInChatroom($user_id, $chatroom_id)
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM chatroom_members 
            WHERE user_id = ? AND chatroom_id = ? AND is_active = TRUE
        ");
        $stmt->bind_param("ii", $user_id, $chatroom_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }

    private function sendToClient($server, $fd, $message)
    {
        try {
            $json_message = json_encode($message);
            if ($json_message === false) {
                echo date('Y-m-d H:i:s') . " Error encoding message to JSON\n";
                return;
            }

            // Check if connection exists before sending
            if (!$server->exist($fd)) {
                echo date('Y-m-d H:i:s') . " Connection {$fd} no longer exists\n";
                // Clean up the connection from our tracking
                $user_id = $this->getUserIdByFd($fd);
                if ($user_id !== null) {
                    unset($this->user_connections[$user_id]);
                }
                return;
            }

            $server->push($fd, $json_message);
        } catch (\Exception $e) {
            echo date('Y-m-d H:i:s') . " Error sending message: " . $e->getMessage() . "\n";
        }
    }

    private function getUserIdByFd($fd)
    {
        foreach ($this->user_connections as $user_id => $conn_fd) {
            if ($conn_fd === $fd) {
                return $user_id;
            }
        }
        return null;
    }
}

try {
    $server = new Server("0.0.0.0", 9501);

    // Configure heartbeat settings
    $server->set([
        'heartbeat_check_interval' => 300,    // Check every 300 seconds
        'heartbeat_idle_time' => 600,         // Connection idle timeout after 600 seconds
    ]);

    $chat_server = new ChatServer();

    $server->on('Start', [$chat_server, 'onStart']);
    $server->on('Open', [$chat_server, 'onOpen']);
    $server->on('Message', [$chat_server, 'onMessage']);
    $server->on('Close', [$chat_server, 'onClose']);

    echo date('Y-m-d H:i:s') . " Starting Swoole WebSocket Server...\n";
    $server->start();
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " Error starting server: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/../config.php';

// Get user's chatrooms
$stmt = $conn->prepare("
    SELECT c.*, u.username as creator_name,
    (SELECT COUNT(*) FROM unread_messages um WHERE um.chatroom_id = c.id AND um.user_id = ? AND um.is_read = FALSE) as unread_count,
    (SELECT m.content 
     FROM messages m 
     LEFT JOIN deleted_messages dm ON m.chatroom_id = dm.chatroom_id AND dm.user_id = ?
     WHERE m.chatroom_id = c.id 
     AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
     ORDER BY m.created_at DESC LIMIT 1) as latest_message
    FROM chatrooms c
    JOIN chatroom_members cm ON c.id = cm.chatroom_id
    JOIN users u ON c.created_by = u.id
    LEFT JOIN deleted_messages dm ON c.id = dm.chatroom_id AND dm.user_id = ?
    WHERE cm.user_id = ? AND cm.is_active = TRUE 
    AND (
        EXISTS (
            SELECT 1 FROM unread_messages um 
            WHERE um.chatroom_id = c.id 
            AND um.user_id = cm.user_id 
            AND um.is_read = FALSE
        )
        OR EXISTS (
            SELECT 1 FROM messages m 
            WHERE m.chatroom_id = c.id 
            AND (dm.deleted_at IS NULL OR m.created_at > dm.deleted_at)
        )
    )
    ORDER BY c.name
");
$stmt->bind_param("iiii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$chatrooms = $stmt->get_result();

// Initialize current room as 0 (no preselection)
$current_room = 0;

// Get chatroom name and members if a room is selected
$room = null;
$room_name = '';
$members = [];
$is_group = false;

// Get the first chatroom as default
if ($chatrooms->num_rows > 0) {
    $first_room = $chatrooms->fetch_assoc();
    $current_room = $first_room['id'];
}

// Get chatroom name and members
if ($current_room > 0) {
    $stmt = $conn->prepare("
        SELECT c.*, u.username as creator_name,
        (SELECT GROUP_CONCAT(u2.username)
         FROM chatroom_members cm2
         JOIN users u2 ON cm2.user_id = u2.id
         WHERE cm2.chatroom_id = c.id AND cm2.is_active = TRUE) as members
        FROM chatrooms c
        JOIN users u ON c.created_by = u.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $current_room);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();

    if ($room) {
        $room_name = define_room_name($room);
        $members = explode(',', $room['members']);
        $is_group = $room['is_group'];
    }
}

function define_room_name($data)
{
    if ($data['is_group'] == 1 || $_SESSION['user_id'] == $data['created_by']) {
        return $data['name'];
    } else {
        return $data['creator_name'];
    }
}

function get_initials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Room</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/share.css">
    <link rel="stylesheet" href="css/index.css">
</head>

<body>
    <?php include("components/vertical_nav.php") ?>
    <div class="main-layout">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="search-box">
                <input type="text" placeholder="Search chats..." id="chatSearch">
            </div>
            <div class="chat-list">
                <?php
                $chatrooms->data_seek(0);
                while ($chatroom = $chatrooms->fetch_assoc()):
                    $is_active = $chatroom['id'] === $current_room;
                ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>"
                        data-chatroom-id="<?php echo $chatroom['id']; ?>"
                        data-is-group="<?php echo $chatroom['is_group']; ?>">
                        <div class="user-avatar">
                            <?php echo get_initials(define_room_name($chatroom)); ?>
                        </div>
                        <div class="chat-info" data-is-group="<?php echo $chatroom['is_group']; ?>">
                            <div class="chat-name">
                                <span class="room-name-text"><?php echo htmlspecialchars(define_room_name($chatroom)); ?></span>
                                <span class="badge bg-primary unread-badge" style="display: <?php echo $chatroom['unread_count'] > 0 ? 'inline-block' : 'none'; ?>;">
                                    <?php echo $chatroom['unread_count']; ?>
                                </span>
                            </div>
                            <div class="chat-preview"><?php echo $chatroom['latest_message'] ? htmlspecialchars($chatroom['latest_message']) : 'No messages yet'; ?></div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-link text-dark p-0" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <button class="dropdown-item" onclick="deleteChatroom(<?php echo $chatroom['id']; ?>)">
                                        <i class="bi bi-trash"></i> Delete Chatroom
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <div id="welcome-message" class="d-flex align-items-center justify-content-center h-100">
                <div class="text-center">
                    <h3>Welcome to Chat Room</h3>
                    <p>Select a chat from the sidebar or start a new conversation</p>
                    <a href="contacts.php" class="btn btn-primary">Start New Chat</a>
                </div>
            </div>
            <div id="chat-container" class="d-none">
                <!-- Chat content will be loaded here by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Include all the existing modals -->
    <?php include 'modals/members_modal.php'; ?>
    <?php include 'modals/leave_admin_modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/picmo@latest/dist/umd/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@picmo/popup-picker@latest/dist/umd/index.js"></script>
    <script src="js/websocket.js"></script>
    <script>
        let currentRoom = 0;
        let currentUser = '<?php echo $_SESSION['username']; ?>';
        let isGroup = false;
        let chatWebSocket;
        let lastMessageId = 0;
        let lastDate = '';

        function loadChatroom(chatroom_id) {
            currentRoom = chatroom_id;

            // Join the chatroom via WebSocket
            if (chatWebSocket && chatWebSocket.is_connected) {
                chatWebSocket.joinChatroom(chatroom_id);
            } else {
                chatWebSocket.requestMessageHistory(chatroom_id);
            }
        }

        // Function to generate chat interface
        function generateChatInterface(roomData) {
            return `
                <div class="chat-header">
                    <div class="user-avatar">
                        ${getInitials(roomData.name)}
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="mb-0 room-name-header">${roomData.name}</h5>
                        ${roomData.is_group ? `
                            <small class="text-muted">
                                ${roomData.member_count} members • Created by ${roomData.creator_name}
                            </small>
                        ` : ''}
                    </div>
                    <div class="d-flex gap-3">
                        ${!roomData.is_group ? '<div id="onlineUsers"></div>' : ''}
                        <i class="bi bi-three-dots-vertical action-icon" data-bs-toggle="dropdown"></i>
                        <ul class="dropdown-menu dropdown-menu-end">
                            ${roomData.is_group ? `
                                <li><a class="dropdown-item" data-bs-toggle="modal" data-bs-target="#membersModal">
                                        <i class="bi bi-people-fill"></i> View Members
                                    </a></li>
                                ${roomData.is_creator ? `
                                    <li><a class="dropdown-item" onclick="showLeaveAdminModal()">
                                            <i class="bi bi-box-arrow-right"></i> Leave as Admin
                                        </a></li>
                                ` : `
                                    <li><a class="dropdown-item" onclick="leaveChat()">
                                            <i class="bi bi-box-arrow-right"></i> Leave Chat
                                        </a></li>
                                `}
                            ` : ''}
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item text-danger" onclick="deleteChatroom()">
                                    <i class="bi bi-trash"></i> Delete Chatroom
                                </a></li>
                        </ul>
                    </div>
                </div>
                <div class="messages" id="messages">
                    <!-- Messages will be loaded here -->
                </div>
                <div class="chat-input">
                    <input type="text" id="messageInput" placeholder="Type a message...">
                    <button type="button" id="emojiButton">
                        <i class="bi bi-emoji-smile"></i>
                    </button>
                    <button type="button" onclick="sendMessage()">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            `;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize WebSocket
            chatWebSocket = new ChatWebSocket(<?php echo $_SESSION['user_id']; ?>);
            chatWebSocket.connect();

            // Make addChatroomToSidebar available globally
            window.addChatroomToSidebar = addChatroomToSidebar;

            // Initialize emoji picker
            const picker = picmoPopup.createPopup({}, {
                referenceElement: document.getElementById('emojiButton'),
                triggerElement: document.getElementById('emojiButton')
            });

            // Chat item click handler
            document.querySelectorAll('.chat-item').forEach(item => {
                item.addEventListener('click', function() {
                    const chatroomId = parseInt(this.dataset.chatroomId);
                    document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');

                    // Update current room and group status
                    currentRoom = chatroomId;
                    isGroup = this.querySelector('.chat-info').dataset.isGroup === '1';

                    // Get room data
                    const roomNameSpan = this.querySelector('.chat-name span:first-child');
                    const roomName = roomNameSpan ? roomNameSpan.textContent.trim() : '';
                    const isGroupChat = this.querySelector('.chat-info').dataset.isGroup === '1';

                    // Hide welcome message and show chat container
                    document.getElementById('welcome-message').classList.add('d-none');
                    const chatContainer = document.getElementById('chat-container');
                    chatContainer.classList.remove('d-none');

                    // Generate and set chat interface
                    const roomData = {
                        name: roomName,
                        is_group: isGroupChat === '1',
                        member_count: 0,
                        creator_name: '',
                        is_creator: false
                    };
                    chatContainer.innerHTML = generateChatInterface(roomData);

                    // Initialize emoji picker for new chat interface
                    const emojiButton = document.getElementById('emojiButton');
                    if (emojiButton) {
                        emojiButton.addEventListener('click', () => {
                            picker.toggle();
                        });
                    }

                    // Initialize message input event listener
                    const messageInput = document.getElementById('messageInput');
                    if (messageInput) {
                        messageInput.addEventListener('keypress', function(e) {
                            if (e.key === 'Enter') {
                                sendMessage();
                            }
                        });
                    }

                    history.pushState({
                        chatroomId
                    }, '', 'index.php');
                    lastMessageId = 0;
                    lastDate = '';
                    loadChatroom(chatroomId);
                });
            });

            // Search functionality
            document.getElementById('chatSearch').addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                document.querySelectorAll('.chat-item').forEach(item => {
                    const chatName = item.querySelector('.chat-name').textContent.toLowerCase();
                    item.style.display = chatName.includes(searchTerm) ? 'flex' : 'none';
                });
            });


            // Handle browser back/forward navigation
            window.addEventListener('popstate', function(event) {
                if (event.state && event.state.chatroomId) {
                    const chatroomId = event.state.chatroomId;
                    const chatItem = document.querySelector(`.chat-item[data-chatroom-id="${chatroomId}"]`);
                    if (chatItem) {
                        chatItem.click();
                    }
                }
            });

            // Mark messages as read when entering a chatroom
            if (currentRoom > 0) {
                setTimeout(() => {
                    if (chatWebSocket && chatWebSocket.is_connected) {
                        chatWebSocket.send({
                            type: "mark_messages_as_read",
                            chatroom_id: currentRoom
                        });
                    }
                }, 2000);
            }
        });

        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);

            if (date.toDateString() === today.toDateString()) {
                return 'Today';
            } else if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            } else {
                return date.toLocaleDateString();
            }
        }

        function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();

            if (message && currentRoom) {
                chatWebSocket.sendMessage(message);
                messageInput.value = '';
            }
        }

        function toggleStar(messageId, button) {
            const isCurrentlyStarred = button.querySelector('i').classList.contains('bi-star-fill');
            const action = isCurrentlyStarred ? 'unstar' : 'star';

            fetch('api/star_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `message_id=${messageId}&action=${action}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const icon = button.querySelector('i');
                        icon.classList.toggle('bi-star');
                        icon.classList.toggle('bi-star-fill');
                        icon.style.color = data.starred ? '#ffc107' : '#000';
                    }
                });
        }

        function deleteChatroom() {
            if (!currentRoom) {
                alert('Please select a chatroom first');
                return;
            }


            if (confirm('Are you sure you want to delete this chat history? The chat will be hidden but will reappear if you receive new messages.')) {
                fetch('api/delete_chatroom.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `chatroom_id=${currentRoom}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Remove the chatroom from the sidebar
                            const chatroomItem = document.querySelector(`.chat-item[data-chatroom-id="${currentRoom}"]`);
                            if (chatroomItem) {
                                chatroomItem.remove();
                            }
                            // Reset current room and reload the page
                            currentRoom = 0;
                            window.location.href = 'index.php';
                        } else {
                            alert(data.message || 'Failed to delete chat history');
                        }
                    })
                    .catch(error => {
                        alert('An error occurred while deleting the chat history');
                    });
            }
        }

        function showLeaveAdminModal() {
            if (currentRoom) {
                $('#leaveAdminModal').modal('show');
            }
        }

        function leaveChat() {
            if (currentRoom) {
                window.location.href = `api/leave_chat.php?chatroom_id=${currentRoom}`;
            }
        }

        function getInitials(name) {
            const words = name.split(' ');
            let initials = '';
            words.forEach(word => {
                initials += word.charAt(0).toUpperCase();
            });
            return initials.substring(0, 2);
        }

        function addChatroomToSidebar(chatroom) {
            const chatroomSelector = document.querySelector('.chat-list');
            if (!chatroomSelector) return;

            // Check if chatroom already exists
            let existingChatroom = document.querySelector(`.chat-item[data-chatroom-id="${chatroom.id}"]`);
            if (existingChatroom) {
                // Update existing chatroom
                const previewDiv = existingChatroom.querySelector('.chat-preview');
                if (previewDiv && chatroom.latest_message) {
                    previewDiv.textContent = chatroom.latest_message;
                }
                const unreadBadge = existingChatroom.querySelector('.unread-badge');
                if (unreadBadge) {
                    unreadBadge.style.display = chatroom.unread_count > 0 ? 'inline-block' : 'none';
                    unreadBadge.textContent = chatroom.unread_count;
                }
                return;
            }

            const chatroomItem = document.createElement('div');
            chatroomItem.className = 'chat-item';
            chatroomItem.dataset.chatroomId = chatroom.id;
            chatroomItem.dataset.isGroup = chatroom.is_group;

            chatroomItem.innerHTML = `
                <div class="user-avatar">
                    ${getInitials(chatroom.name)}
                </div>
                <div class="chat-info" data-is-group="${chatroom.is_group}">
                    <div class="chat-name">
                        <span class="room-name-text">${chatroom.name}</span>
                        <span class="badge bg-primary unread-badge" style="display: ${chatroom.unread_count > 0 ? 'inline-block' : 'none'};">
                            ${chatroom.unread_count}
                        </span>
                    </div>
                    <div class="chat-preview">${chatroom.latest_message || 'No messages yet'}</div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-link text-dark p-0" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <button class="dropdown-item" onclick="deleteChatroom(${chatroom.id})">
                                <i class="bi bi-trash"></i> Delete Chatroom
                            </button>
                        </li>
                    </ul>
                </div>
            `;

            // Add click handler
            chatroomItem.addEventListener('click', function() {
                const chatroomId = parseInt(this.dataset.chatroomId);
                document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');

                currentRoom = chatroomId;
                isGroup = this.querySelector('.chat-info').dataset.isGroup === '1';

                // Get room data
                const roomNameSpan = this.querySelector('.chat-name span:first-child');
                const roomName = roomNameSpan ? roomNameSpan.textContent.trim() : '';
                const isGroupChat = this.querySelector('.chat-info').dataset.isGroup === '1';

                // Hide welcome message and show chat container
                document.getElementById('welcome-message').classList.add('d-none');
                const chatContainer = document.getElementById('chat-container');
                chatContainer.classList.remove('d-none');

                // Generate and set chat interface
                const roomData = {
                    name: roomName,
                    is_group: isGroupChat === '1',
                    member_count: 0,
                    creator_name: '',
                    is_creator: false
                };
                chatContainer.innerHTML = generateChatInterface(roomData);

                // Initialize emoji picker for new chat interface
                const emojiButton = document.getElementById('emojiButton');
                if (emojiButton) {
                    emojiButton.addEventListener('click', () => {
                        picker.toggle();
                    });
                }

                // Initialize message input event listener
                const messageInput = document.getElementById('messageInput');
                if (messageInput) {
                    messageInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            sendMessage();
                        }
                    });
                }

                history.pushState({
                    chatroomId
                }, '', 'index.php');
                lastMessageId = 0;
                lastDate = '';

                // Use the global loadChatroom function
                loadChatroom(chatroomId);
            });

            // Insert at the beginning of the chat list
            const firstItem = chatroomSelector.querySelector('.chat-item');
            if (firstItem) {
                firstItem.parentNode.insertBefore(chatroomItem, firstItem);
            } else {
                chatroomSelector.appendChild(chatroomItem);
            }
        }
    </script>
</body>

</html>
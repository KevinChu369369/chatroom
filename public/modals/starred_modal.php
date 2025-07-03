<!-- Starred Messages Modal -->
<div class="modal fade" id="starredModal" tabindex="-1" aria-labelledby="starredModalLabel" inert>
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="starredModalLabel">Starred Messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="starred_messages_list" class="starred-messages-list">
                    <!-- Messages will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .starred-messages-list {
        max-height: 70vh;
        overflow-y: auto;
    }

    .starred-message-item {
        padding: 15px;
        border-bottom: 1px solid var(--app-border);
        position: relative;
    }

    .starred-message-item:last-child {
        border-bottom: none;
    }

    .starred-message-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
    }

    .starred-message-sender {
        font-weight: bold;
        color: #2c3e50;

    }

    .starred-message-time {
        color: #7f8c8d;
        font-size: 0.9em;
    }

    .starred-message-content {
        color: #34495e;
        margin-bottom: 5px;
    }

    .starred-message-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9em;
        color: #7f8c8d;
    }

    .starred-message-chatroom {
        cursor: pointer;
        color: #3498db;

    }

    .starred-message-chatroom:hover {
        color: var(--app-primary);
    }

    .unstar-btn {
        background: none;
        border: none;
        color: #e74c3c;
        cursor: pointer;
        padding: 5px;
    }

    .unstar-btn:hover {
        color: #c0392b;

    }

    .no-starred-messages {
        text-align: center;
        padding: 20px;
        color: #7f8c8d;
    }

    /* Modal specific overrides */
    #starredModal .modal-content {
        background-color: var(--app-bg);
        color: var(--app-text);
    }

    #starredModal .modal-header {
        border-bottom-color: var(--app-border);
    }

    #starredModal .btn-close {
        color: var(--app-text);
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const starred_modal = document.getElementById('starredModal');
        if (starred_modal) {
            // Load starred messages when modal is shown
            starred_modal.addEventListener('show.bs.modal', function() {
                // Remove inert attribute when showing modal
                starred_modal.removeAttribute('inert');
                loadStarredMessages();
            });

            // Handle focus management when modal is hidden
            starred_modal.addEventListener('hidden.bs.modal', function() {
                // Add inert attribute when modal is hidden
                starred_modal.setAttribute('inert', '');

                // Remove active class from starred messages menu item
                const starred_menu_item = document.querySelector('.nav-link[data-bs-toggle="modal"][data-bs-target="#starredModal"]');
                if (starred_menu_item) {
                    starred_menu_item.classList.remove('active');
                    // Return focus to the menu item that opened the modal
                    starred_menu_item.blur();
                }

                // Clear the modal content to prevent stale data
                const messages_container = document.getElementById('starred_messages_list');
                if (messages_container) {
                    messages_container.innerHTML = '';
                }
                // Move focus to body to prevent menu item focus
                document.body.focus();
            });

            // Handle modal closing
            starred_modal.addEventListener('hide.bs.modal', function() {
                document.body.focus();
                // Ensure no element inside modal retains focus
                document.activeElement.blur();
            });
        }
    });

    function loadStarredMessages() {
        const messages_container = document.getElementById('starred_messages_list');
        if (!messages_container) return;

        // Show loading state
        messages_container.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>';

        // Fetch starred messages
        fetch('api/star_message.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages) {
                    if (data.messages.length === 0) {
                        messages_container.innerHTML = '<div class="no-starred-messages">No starred messages yet</div>';
                        return;
                    }

                    messages_container.innerHTML = data.messages.map(message => `
                    <div class="starred-message-item" data-message-id="${message.id}">
                        <div class="starred-message-header">
                            <span class="starred-message-sender">${escapeHtml(message.username)}</span>
                            <span class="starred-message-time">${formatMessageTime(message.timestamp)}</span>
                        </div>
                        <div class="starred-message-content">${escapeHtml(message.content)}</div>
                        <div class="starred-message-footer">
                            <span class="starred-message-chatroom" onclick="openChatroom(${message.chatroom_id}, ${message.id})">
                                <i class="bi bi-chat-left-text"></i> ${escapeHtml(message.chatroom_name)}
                            </span>
                            <button class="unstar-btn" onclick="unstarMessage(${message.id}, this)">
                                <i class="bi bi-star-fill"></i> Unstar
                            </button>
                        </div>
                    </div>
                `).join('');
                } else {
                    messages_container.innerHTML = '<div class="no-starred-messages">Failed to load starred messages</div>';
                }
            })
            .catch(error => {
                messages_container.innerHTML = '<div class="no-starred-messages">Error loading starred messages</div>';
                console.log("Error loading starred messages", error);
            });
    }

    function unstarMessage(message_id, button_element) {
        fetch('api/star_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `message_id=${message_id}&action=unstar`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the message item from the list
                    const message_item = button_element.closest('.starred-message-item');
                    if (message_item) {
                        message_item.remove();
                    }

                    // Check if there are no more messages
                    const messages_container = document.getElementById('starred_messages_list');
                    if (messages_container && !messages_container.children.length) {
                        messages_container.innerHTML = '<div class="no-starred-messages">No starred messages yet</div>';
                    }
                } else {
                    alert('Failed to unstar message');
                }
            })
            .catch(error => {
                alert('Error unstarring message');
            });
    }

    function openChatroom(chatroom_id, message_id) {
        // Close the modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('starredModal'));
        if (modal) {
            modal.hide();
        }

        // Find and click the chatroom in the sidebar
        const chatroom_item = document.querySelector(`.chat-item[data-chatroom-id="${chatroom_id}"]`);
        if (chatroom_item) {
            // Store the target message ID in sessionStorage
            sessionStorage.setItem('target_message_id', message_id);
            chatroom_item.click();
        }
    }

    function formatMessageTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();

        // Reset time portions to midnight for accurate day comparison
        const message_date = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        // Calculate the difference in days
        const diff = today - message_date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));

        if (days === 0) {
            return date.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
        } else if (days === 1) {
            return 'Yesterday';
        } else if (days < 7) {
            return date.toLocaleDateString([], {
                weekday: 'long'
            });
        } else {
            return date.toLocaleDateString();
        }
    }

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
</script>
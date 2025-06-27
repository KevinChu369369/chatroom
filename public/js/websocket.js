class ChatWebSocket {
  constructor(user_id) {
    this.ws = null;
    this.user_id = user_id;
    this.current_chatroom_id = null;
    this.reconnect_attempts = 0;
    this.max_reconnect_attempts = 5;
    this.reconnect_delay = 1000;
    this.token = null;
    this.is_connected = false;
    this.pending_requests = [];
    this.last_date = "";
    this.last_message_id = 0;
    this.oldest_message_id = Infinity;
    this.is_loading_messages = false;
    this.has_more_messages = true;
    this.sync_interval = null;
    this.scroll_listener = null;
    this.mark_read_timeout = null;
  }

  async connect() {
    try {
      // First get a secure token
      const response = await fetch("api/get_ws_token.php");

      const data = await response.json();

      this.token = data.token;

      this.ws = new WebSocket("ws://localhost:9501");

      this.ws.onopen = () => {
        this.reconnect_attempts = 0;
        this.is_connected = true;

        this.authenticate();

        // Process any pending requests
        this.processPendingRequests();
      };

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);

          switch (data.type) {
            case "ping":
              // Respond to heartbeat
              this.send({
                type: "pong",
                timestamp: data.timestamp,
              });

              break;

            case "join":
              if (data.success) {
                // Request message history after joining
                this.requestMessageHistory(data.chatroom_id);
              }
              break;

            case "message":
              // Update last_message_id if this is a newer message
              if (data.id > this.last_message_id) {
                this.last_message_id = data.id;
              }

              // Check if the chatroom exists in the sidebar
              const chatroomExists = document.querySelector(
                `[data-chatroom-id="${data.chatroom_id}"]`
              );

              if (!chatroomExists && data.user_id !== this.user_id) {
                // Request updated chatroom list to get the new chatroom
                this.send({
                  type: "update_chatroom_list",
                  current_chatroom_id: this.current_chatroom_id,
                });
              }

              this.updateChatPreview(data.chatroom_id, data.message);

              // Display the message if it's for the current chatroom
              if (data.chatroom_id === this.current_chatroom_id) {
                // Create a message object with the correct structure
                const message = {
                  id: data.id,
                  user_id: data.user_id,
                  username: data.username,
                  content: data.message,
                  created_at: data.created_at,
                  type: "text",
                };
                this.appendMessage(message);
                // Remove any existing unread separator
                const messagesDiv = document.getElementById("messages");
                const existingSeparator =
                  messagesDiv.querySelector(".unread-separator");
                if (existingSeparator) {
                  existingSeparator.remove();
                }

                // Mark messages as read immediately for current chatroom
                this.markMessagesAsRead(this.current_chatroom_id);
              } else if (data.user_id !== this.user_id) {
                // Only request unread counts if message is not for current chatroom AND not from current user
                this.send({
                  type: "get_unread_counts",
                  current_chatroom_id: this.current_chatroom_id,
                });
              }
              break;

            case "sync":
              if (data.messages && data.messages.length > 0) {
                data.messages.forEach((message) => {
                  if (message.id > this.last_message_id) {
                    this.last_message_id = message.id;
                    if (message.chatroom_id === this.current_chatroom_id) {
                      const formattedMessage = {
                        id: message.id,
                        user_id: message.user_id,
                        username: message.username,
                        content: message.message,
                        created_at: message.created_at,
                        type: "text",
                      };
                      this.appendMessage(formattedMessage);
                    }
                  }
                });
              }
              break;

            case "history":
              this.handleHistory(data);
              break;

            case "auth":
              if (data.status === "success") {
                // Process any pending requests after successful authentication
                this.processPendingRequests();
              }
              break;

            case "error":
              alert("Error: " + data.message);
              break;

            case "messages_marked_as_read":
              if (
                data.success &&
                data.chatroom_id === this.current_chatroom_id
              ) {
                // Remove unread indicators from all messages
                const unreadMessages =
                  document.querySelectorAll(".unread-message");
                unreadMessages.forEach((msg) => {
                  msg.classList.remove("unread-message");
                });

                const chatroomItem = document.querySelector(
                  `[data-chatroom-id="${data.chatroom_id}"]`
                );
                if (chatroomItem) {
                  const unreadBadge =
                    chatroomItem.querySelector(".unread-badge");
                  if (unreadBadge) {
                    unreadBadge.style.display = "none";
                    unreadBadge.textContent = "0";
                  }
                }
              }
              break;

            case "get_unread_counts":
              if (data.chatrooms) {
                data.chatrooms.forEach((chatroom) => {
                  const chatroomItem = document.querySelector(
                    `[data-chatroom-id="${chatroom.id}"]`
                  );

                  if (chatroom.unread_count > 0) {
                    // Update existing chatroom
                    if (chatroomItem) {
                      const unreadBadge =
                        chatroomItem.querySelector(".unread-badge");
                      if (unreadBadge) {
                        unreadBadge.textContent = chatroom.unread_count;
                        unreadBadge.style.display = "inline";
                      }
                    }
                  } else if (chatroomItem) {
                    const unreadBadge =
                      chatroomItem.querySelector(".unread-badge");
                    if (unreadBadge) {
                      unreadBadge.style.display = "none";
                    }
                  }
                });
              }
              break;

            case "update_chatroom_list":
              if (data.chatrooms) {
                data.chatrooms.forEach((chatroom) => {
                  const chatroomItem = document.querySelector(
                    `[data-chatroom-id="${chatroom.id}"]`
                  );
                  if (!chatroomItem) {
                    // Add latest message to the chatroom data
                    window.addChatroomToSidebar(chatroom);
                  }
                });
              }
              break;

            case "older_messages":
              this.handleOlderMessages(data);
              break;
          }
        } catch (error) {}
      };

      this.ws.onclose = (event) => {
        this.is_connected = false;
        this.attemptReconnect();
      };

      this.ws.onerror = (error) => {
        this.is_connected = false;
      };
    } catch (error) {
      this.is_connected = false;
    }
  }

  authenticate() {
    this.send({
      type: "auth",
      token: this.token,
      user_id: this.user_id,
    });
  }

  joinChatroom(chatroom_id) {
    // Remove scroll listener from previous chatroom
    this.removeScrollListener();

    // Reset message tracking variables
    this.last_message_id = 0;
    this.oldest_message_id = Infinity;
    this.is_loading_messages = false;
    this.has_more_messages = true;
    this.last_date = "";

    // Clear any existing messages
    const messagesDiv = document.getElementById("messages");
    if (messagesDiv) {
      messagesDiv.innerHTML = "";
    }

    this.current_chatroom_id = chatroom_id;
    this.send({
      type: "join",
      chatroom_id: chatroom_id,
      user_id: this.user_id,
    });

    // Request initial message history
    this.requestMessageHistory(chatroom_id);
  }

  leaveChatroom(chatroom_id) {
    this.send({
      type: "leave",
      chatroom_id: chatroom_id,
      user_id: this.user_id,
    });

    // Reset all chat-related variables
    this.current_chatroom_id = null;
    this.last_message_id = 0;
    this.oldest_message_id = Infinity;
    this.is_loading_messages = false;
    this.has_more_messages = true;
    this.last_date = "";

    // Remove scroll listener
    this.removeScrollListener();
  }

  requestMessageHistory(chatroom_id) {
    this.send({
      type: "history",
      chatroom_id: chatroom_id,
      user_id: this.user_id,
    });
  }

  sendMessage(message) {
    if (!this.current_chatroom_id) {
      return;
    }

    this.send({
      type: "message",
      chatroom_id: this.current_chatroom_id,
      message: message,
    });
  }

  send(data) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN && this.is_connected) {
      try {
        const jsonData = JSON.stringify(data);
        this.ws.send(jsonData);
      } catch (error) {}
    } else {
      this.pending_requests.push(data);
    }
  }

  processPendingRequests() {
    if (this.pending_requests.length > 0 && this.is_connected) {
      const requests = [...this.pending_requests];
      this.pending_requests = [];

      for (const request of requests) {
        this.send(request);
      }
    }
  }

  attemptReconnect() {
    if (this.reconnect_attempts < this.max_reconnect_attempts) {
      this.reconnect_attempts++;
      setTimeout(
        () => this.connect(),
        this.reconnect_delay * this.reconnect_attempts
      );
    }
  }

  appendMessage(message) {
    const messagesDiv = document.getElementById("messages");
    if (!messagesDiv) {
      return;
    }

    const messageDate = new Date(message.created_at);
    const messageDateStr = messageDate.toLocaleDateString();

    if (messageDateStr !== this.last_date) {
      const dateSeparator = document.createElement("div");
      dateSeparator.className = "date-separator";
      const dateSpan = document.createElement("span");
      dateSpan.textContent = messageDateStr;
      dateSeparator.appendChild(dateSpan);
      messagesDiv.appendChild(dateSeparator);
      this.last_date = messageDateStr;
    }

    const messageDiv = this.createMessageElement(message);
    messageDiv.dataset.date = message.created_at; // Store the date for future reference
    messagesDiv.appendChild(messageDiv);

    // Only auto-scroll if we're already at the bottom or if it's our own message
    const isAtBottom =
      messagesDiv.scrollHeight -
        messagesDiv.scrollTop -
        messagesDiv.clientHeight <
      100;
    if (isAtBottom || message.user_id === this.user_id) {
      messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    // Check if message is starred
    this.checkStarStatus(message.id);

    return messageDiv;
  }

  toggleStar(messageId, button) {
    const isCurrentlyStarred = button
      .querySelector("i")
      .classList.contains("bi-star-fill");
    const action = isCurrentlyStarred ? "unstar" : "star";

    fetch("api/star_message.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: `message_id=${messageId}&action=${action}`,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          const icon = button.querySelector("i");
          icon.classList.toggle("bi-star");
          icon.classList.toggle("bi-star-fill");
          icon.style.color = data.starred ? "#ffc107" : "#000";
        }
      });
  }

  checkStarStatus(messageId) {
    fetch(`api/star_message.php?message_id=${messageId}`)
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          const messageDiv = document.querySelector(
            `[data-message-id="${messageId}"]`
          );
          if (messageDiv) {
            const starButton = messageDiv.querySelector(".star-btn i");
            if (data.starred) {
              starButton.classList.remove("bi-star");
              starButton.classList.add("bi-star-fill");
              starButton.style.color = "#ffc107";
            }
          }
        }
      });
  }

  startSync() {
    // Clear any existing sync interval
    if (this.sync_interval) {
      clearInterval(this.sync_interval);
    }

    // Start new sync interval (every 5 seconds)
    this.sync_interval = setInterval(() => {
      if (this.is_connected && this.current_chatroom_id) {
        this.send({
          type: "sync",
          chatroom_id: this.current_chatroom_id,
          last_message_id: this.last_message_id,
        });
      }
    }, 5000);
  }

  stopSync() {
    if (this.sync_interval) {
      clearInterval(this.sync_interval);
      this.sync_interval = null;
    }
  }

  addUnreadSeparator(messageElement, unreadCount) {
    const messagesDiv = document.getElementById("messages");
    if (!messagesDiv) {
      return;
    }

    const separator = document.createElement("div");
    separator.className = "unread-separator";
    separator.innerHTML = `
      <div class="unread-line"></div>
      <div class="unread-label">${unreadCount} unread message${
      unreadCount > 1 ? "s" : ""
    }</div>
      <div class="unread-line"></div>
    `;

    // Insert the separator before the first unread message
    if (messageElement && messageElement.parentNode) {
      messageElement.parentNode.insertBefore(separator, messageElement);
    }

    return separator;
  }

  markMessagesAsRead(chatroom_id) {
    // Use WebSocket instead of API call
    this.send({
      type: "mark_messages_as_read",
      chatroom_id: chatroom_id,
    });
  }

  updateChatPreview(chatroomId, message) {
    const chatroomItem = document.querySelector(
      `.chat-item[data-chatroom-id="${chatroomId}"]`
    );
    if (chatroomItem) {
      const previewDiv = chatroomItem.querySelector(".chat-preview");
      if (previewDiv) {
        previewDiv.textContent = message;
      }
    }
  }

  addScrollListener() {
    const messages_div = document.getElementById("messages");
    if (messages_div && !this.scroll_listener) {
      this.scroll_listener = () => {
        const scroll_top = messages_div.scrollTop;
        const scroll_height = messages_div.scrollHeight;
        const client_height = messages_div.clientHeight;

        // If user scrolled within 100px of bottom, mark messages as read
        if (scroll_height - scroll_top - client_height < 100) {
          this.markMessagesAsReadAfterScroll();
        }

        // If user scrolled to top and we have more messages to load
        if (
          scroll_top < 50 &&
          !this.is_loading_messages &&
          this.has_more_messages
        ) {
          this.loadOlderMessages();
        }
      };

      messages_div.addEventListener("scroll", this.scroll_listener);
    }
  }

  removeScrollListener() {
    const messagesDiv = document.getElementById("messages");
    if (messagesDiv && this.scroll_listener) {
      messagesDiv.removeEventListener("scroll", this.scroll_listener);
      this.scroll_listener = null;

      // Also remove any loading indicators that might be present
      const loadingDiv = messagesDiv.querySelector(".loading-messages");
      if (loadingDiv) {
        loadingDiv.remove();
      }
    }
  }

  markMessagesAsReadAfterScroll() {
    // Debounce the marking to avoid too many requests
    if (this.mark_read_timeout) {
      clearTimeout(this.mark_read_timeout);
    }

    this.mark_read_timeout = setTimeout(() => {
      if (this.current_chatroom_id) {
        this.markMessagesAsRead(this.current_chatroom_id);
      }
    }, 1000);
  }

  loadOlderMessages() {
    if (
      !this.current_chatroom_id ||
      this.is_loading_messages ||
      !this.has_more_messages
    ) {
      return;
    }

    this.is_loading_messages = true;

    // Show loading indicator
    const messages_div = document.getElementById("messages");
    if (!messages_div) {
      this.is_loading_messages = false;
      return;
    }

    const loading_div = document.createElement("div");
    loading_div.className = "loading-messages";
    loading_div.innerHTML = `
      <div class="spinner-border" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
    `;
    messages_div.insertBefore(loading_div, messages_div.firstChild);

    this.send({
      type: "load_older_messages",
      chatroom_id: this.current_chatroom_id,
      oldest_message_id: this.oldest_message_id,
    });
  }

  handleOlderMessages(data) {
    const messages_div = document.getElementById("messages");
    if (!messages_div) return;

    // Remove loading indicator
    const loading_div = messages_div.querySelector(".loading-messages");
    if (loading_div) {
      loading_div.remove();
    }

    if (data.messages && data.messages.length > 0) {
      // Store current scroll position and height
      const old_scroll_height = messages_div.scrollHeight;
      const old_scroll_top = messages_div.scrollTop;

      // Update oldest message ID
      const new_oldest_id = Math.min(...data.messages.map((m) => m.id));
      if (new_oldest_id < this.oldest_message_id) {
        this.oldest_message_id = new_oldest_id;
      }

      // Store the first existing message's date for comparison
      const first_existing_message = messages_div.querySelector(".message");
      const first_existing_date = first_existing_message
        ? new Date(first_existing_message.dataset.date).toLocaleDateString()
        : null;

      // Create a document fragment to hold new messages
      const fragment = document.createDocumentFragment();
      let current_date = "";

      // Process messages in chronological order
      data.messages.forEach((message) => {
        const message_date = new Date(message.created_at);
        const message_date_str = message_date.toLocaleDateString();

        // Only add date separator if it's a new date
        if (message_date_str !== current_date) {
          // Don't add a date separator if this date already exists at the top
          if (
            message_date_str !== first_existing_date ||
            !first_existing_message
          ) {
            const date_separator = document.createElement("div");
            date_separator.className = "date-separator";
            const date_span = document.createElement("span");
            date_span.textContent = message_date_str;
            date_separator.appendChild(date_span);
            fragment.appendChild(date_separator);
          }
          current_date = message_date_str;
        }

        const message_div = this.createMessageElement(message);
        message_div.dataset.date = message.created_at;
        fragment.appendChild(message_div);
      });

      // Insert all new messages at once
      if (messages_div.firstChild) {
        messages_div.insertBefore(fragment, messages_div.firstChild);
      } else {
        messages_div.appendChild(fragment);
      }

      // Maintain scroll position after adding new content
      // Example: If old height was 1000px and new height is 1200px,
      // and user was scrolled to position 500px,
      // we need to set scroll position to 700px (500 + (1200 - 1000))
      const new_scroll_height = messages_div.scrollHeight;
      const height_difference = new_scroll_height - old_scroll_height;
      messages_div.scrollTop = old_scroll_top + height_difference;
    }

    this.has_more_messages = data.has_more;
    this.is_loading_messages = false;
  }

  handleHistory(data) {
    if (
      data.chatroom_id === this.current_chatroom_id &&
      Array.isArray(data.messages)
    ) {
      // Clear existing messages first
      const messagesDiv = document.getElementById("messages");
      if (messagesDiv) {
        messagesDiv.innerHTML = "";
      }

      // Reset message tracking variables
      this.last_message_id = 0;
      this.oldest_message_id = Infinity;
      this.has_more_messages = true;
      this.last_date = "";
      this.is_loading_messages = false;

      // Update last_message_id and oldest_message_id from history
      if (data.messages.length > 0) {
        this.last_message_id = Math.max(...data.messages.map((m) => m.id));
        this.oldest_message_id = Math.min(...data.messages.map((m) => m.id));
        // Set has_more_messages based on the number of messages received
        this.has_more_messages = data.messages.length >= 50;
      } else {
        this.has_more_messages = false;
      }

      // Store the oldest unread message ID for scrolling
      let oldestUnreadElement = null;
      let hasUnreadMessages = false;
      let unreadCount = 0;

      // Remove any existing scroll listener before adding a new one
      this.removeScrollListener();

      // Append each message from the history
      data.messages.forEach((message) => {
        const messageElement = this.appendMessage(message);

        // Count unread messages and mark the first one
        if (message.is_unread) {
          unreadCount++;
          if (!oldestUnreadElement) {
            oldestUnreadElement = messageElement;
            hasUnreadMessages = true;
          }
        }
      });

      // Add scroll listener for infinite scrolling
      this.addScrollListener();

      // Only scroll to unread messages if there are actually unread messages
      if (hasUnreadMessages && oldestUnreadElement) {
        // Add a visual separator before the first unread message
        this.addUnreadSeparator(oldestUnreadElement, unreadCount);

        // Scroll to the unread separator
        setTimeout(() => {
          const separator = messagesDiv.querySelector(".unread-separator");
          if (separator) {
            separator.scrollIntoView({
              behavior: "smooth",
              block: "start",
            });
          }
        }, 100);
      } else {
        // No unread messages, scroll to bottom
        setTimeout(() => {
          messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }, 100);
      }

      // Mark messages as read after a short delay
      setTimeout(() => {
        this.markMessagesAsRead(data.chatroom_id);
      }, 1000);
    }
  }

  // Helper method to create message element
  createMessageElement(message) {
    const messageDiv = document.createElement("div");
    messageDiv.className = `message ${
      message.user_id === this.user_id ? "sent" : "received"
    }`;
    messageDiv.dataset.messageId = message.id;

    const headerDiv = document.createElement("div");
    headerDiv.className =
      "d-flex justify-content-between align-items-center mb-1";

    const starButton = document.createElement("button");
    starButton.className = "btn btn-sm star-btn";
    starButton.innerHTML = '<i class="bi bi-star"></i>';
    starButton.onclick = (e) => {
      e.stopPropagation();
      this.toggleStar(message.id, starButton);
    };

    headerDiv.appendChild(starButton);

    const contentDiv = document.createElement("div");
    contentDiv.className = "content";

    contentDiv.textContent = message.content;

    const timestampDiv = document.createElement("div");
    timestampDiv.className = "timestamp";
    // Convert UTC time to local time
    const messageTime = new Date(message.created_at);
    timestampDiv.textContent = messageTime.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });

    messageDiv.appendChild(headerDiv);
    messageDiv.appendChild(contentDiv);
    messageDiv.appendChild(timestampDiv);

    // Check if message is starred
    this.checkStarStatus(message.id);

    return messageDiv;
  }
}

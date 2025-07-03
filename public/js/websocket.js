class WebSocketHandler {
  constructor(user_id) {
    this.ws = null;
    this.user_id = user_id;
    this.current_chatroom_id = null;
    this.reconnect_attempts = 0;
    this.max_reconnect_attempts = 10;
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
    this.is_viewing_history = false;
    this.scroll_timeout = null;
    this.connection_status_element = null;
    this.pending_messages = [];
    this.sent_message_ids = new Set();
  }

  async connect() {
    try {
      // First get a secure token
      const response = await fetch("api/get_ws_token.php");
      const data = await response.json();
      this.token = data.token;

      this.ws = new WebSocket("ws://localhost:9501");

      this.ws.onopen = () => {
        console.log("WebSocket connected");
        this.reconnect_attempts = 0;
        this.is_connected = true;
        this.updateConnectionStatus("connected");
        this.authenticate();
        this.processPendingRequests();

        // Try to resend any pending messages
        this.resendPendingMessages();
      };

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          console.log("Received websocket message:", data);

          switch (data.type) {
            case "ping":
              this.send({
                type: "pong",
                timestamp: data.timestamp,
              });
              break;

            case "join":
              if (data.success) {
                this.requestMessageHistory(data.chatroom_id);
              }
              break;

            case "message":
              this.handleIncomingMessage(data);
              break;

            case "sync":
              if (data.messages && data.messages.length > 0) {
                data.messages.forEach((message) => {
                  if (message.id > this.last_message_id) {
                    this.last_message_id = message.id;
                    if (message.chatroom_id === this.current_chatroom_id) {
                      const formatted_message = {
                        id: message.id,
                        user_id: message.user_id,
                        username: message.username,
                        content: message.message,
                        created_at: message.created_at,
                        type: "text",
                      };
                      this.appendMessage(formatted_message);
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
                const unread_messages =
                  document.querySelectorAll(".unread-message");
                unread_messages.forEach((msg) => {
                  msg.classList.remove("unread-message");
                });

                const chatroom_item = document.querySelector(
                  `[data-chatroom-id="${data.chatroom_id}"]`
                );
                if (chatroom_item) {
                  const unread_badge =
                    chatroom_item.querySelector(".unread-badge");
                  if (unread_badge) {
                    unread_badge.style.display = "none";
                    unread_badge.textContent = "0";
                  }
                }
              }
              break;

            case "get_unread_counts":
              if (data.chatrooms) {
                data.chatrooms.forEach((chatroom) => {
                  const chatroom_item = document.querySelector(
                    `[data-chatroom-id="${chatroom.id}"]`
                  );

                  if (chatroom.unread_count > 0) {
                    // Update existing chatroom
                    if (chatroom_item) {
                      const unread_badge =
                        chatroom_item.querySelector(".unread-badge");
                      if (unread_badge) {
                        unread_badge.textContent = chatroom.unread_count;
                        unread_badge.style.display = "inline";
                      }
                    }
                  } else if (chatroom_item) {
                    const unread_badge =
                      chatroom_item.querySelector(".unread-badge");
                    if (unread_badge) {
                      unread_badge.style.display = "none";
                    }
                  }
                });
              }
              break;

            case "update_chatroom_list":
              if (data.chatrooms) {
                data.chatrooms.forEach((chatroom) => {
                  const chatroom_item = document.querySelector(
                    `[data-chatroom-id="${chatroom.id}"]`
                  );
                  if (!chatroom_item) {
                    // Add latest message to the chatroom data
                    window.addChatroomToSidebar(chatroom);
                  }
                });
              }
              break;

            case "loaded_messages":
              this.handleLoadedMessages(data);
              break;
          }
        } catch (error) {
          console.error("Error processing message:", error);
        }
      };

      this.ws.onclose = (event) => {
        console.log("WebSocket closed:", event);
        this.is_connected = false;
        this.updateConnectionStatus("disconnected");
        this.attemptReconnect();
      };

      this.ws.onerror = (error) => {
        console.error("WebSocket error:", error);
        this.is_connected = false;
        this.updateConnectionStatus("error");
      };
    } catch (error) {
      console.error("Connection error:", error);
      this.is_connected = false;
      this.updateConnectionStatus("error");
      this.attemptReconnect();
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
    const messages_div = document.getElementById("messages");
    if (messages_div) {
      messages_div.innerHTML = "";
    }

    this.current_chatroom_id = chatroom_id;
    this.send({
      type: "join",
      chatroom_id: chatroom_id,
      user_id: this.user_id,
    });

    // Request initial message history
    //this.requestMessageHistory(chatroom_id);
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
    const target_message_id = sessionStorage.getItem("target_message_id");

    this.send({
      type: "history",
      chatroom_id: chatroom_id,
      user_id: this.user_id,
      target_message_id: target_message_id || null,
    });

    // Clear the target message ID after sending
    if (target_message_id) {
      sessionStorage.removeItem("target_message_id");
    }
  }

  sendMessage(message) {
    if (!this.current_chatroom_id) {
      return;
    }

    const messageData = {
      type: "message",
      chatroom_id: this.current_chatroom_id,
      message: message,
      temp_id: Date.now(), // Add a temporary ID to track this message
    };

    console.log("Sending message:", messageData);

    // Optimistically add message to UI
    this.appendMessage({
      id: messageData.temp_id,
      user_id: this.user_id,
      username: current_user, // This should be defined in your page
      content: message,
      created_at: new Date().toISOString(),
      is_pending: true,
      temp_id: messageData.temp_id, // Store temp_id for later reference
    });

    if (this.ws && this.ws.readyState === WebSocket.OPEN && this.is_connected) {
      try {
        const json_data = JSON.stringify(messageData);
        this.ws.send(json_data);
        this.sent_message_ids.add(messageData.temp_id.toString());
      } catch (error) {
        console.error("Error sending message:", error);
        this.pending_messages.push(messageData);
        this.updateMessageStatus(messageData.temp_id, "failed");
      }
    } else {
      console.log("Connection not ready, queueing message");
      this.pending_messages.push(messageData);
      this.updateMessageStatus(messageData.temp_id, "pending");
    }
  }

  send(data) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN && this.is_connected) {
      try {
        const json_data = JSON.stringify(data);
        this.ws.send(json_data);
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
      const delay = Math.min(
        1000 * Math.pow(2, this.reconnect_attempts - 1),
        30000
      ); // Exponential backoff with max 30s
      console.log(
        `Attempting reconnect ${this.reconnect_attempts} in ${delay}ms`
      );

      setTimeout(() => {
        if (!this.is_connected) {
          this.connect();
        }
      }, delay);
    } else {
      this.updateConnectionStatus("error");
      this.connection_status_element.innerHTML =
        '<div class="alert alert-danger" role="alert">' +
        'Connection lost. Please <a href="javascript:void(0)" onclick="window.location.reload()">refresh</a> the page.' +
        "</div>";
    }
  }

  appendMessage(message) {
    const messages_div = document.getElementById("messages");
    if (!messages_div) {
      return;
    }

    // Check for existing message with same temp_id
    if (
      message.temp_id &&
      document.querySelector(`[data-temp-id="${message.temp_id}"]`)
    ) {
      console.log("Message with temp_id already exists:", message.temp_id);
      return;
    }

    const message_date = new Date(message.created_at);
    const message_date_str = message_date.toLocaleDateString();

    if (message_date_str !== this.last_date) {
      const date_separator = document.createElement("div");
      date_separator.className = "date-separator";
      const date_span = document.createElement("span");
      date_span.textContent = message_date_str;
      date_separator.appendChild(date_span);
      messages_div.appendChild(date_separator);
      this.last_date = message_date_str;
    }

    const created_message_div = this.createMessageElement(message);
    if (message.temp_id) {
      created_message_div.dataset.tempId = message.temp_id;
    }
    created_message_div.dataset.date = message.created_at;
    messages_div.appendChild(created_message_div);

    // Only auto-scroll if we're already at the bottom or if it's our own message
    const is_at_bottom =
      messages_div.scrollHeight -
        messages_div.scrollTop -
        messages_div.clientHeight <
      100;
    if (is_at_bottom || message.user_id === this.user_id) {
      messages_div.scrollTop = messages_div.scrollHeight;
    }

    // Check if message is starred
    if (!message.is_pending && message.id) {
      this.checkStarStatus(message.id);
    }

    return created_message_div;
  }

  toggleStar(message_id, button) {
    const is_currently_starred = button
      .querySelector("i")
      .classList.contains("bi-star-fill");
    const action = is_currently_starred ? "unstar" : "star";

    fetch("api/star_message.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: `message_id=${message_id}&action=${action}`,
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

  checkStarStatus(message_id) {
    fetch(`api/star_message.php?message_id=${message_id}`)
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          const message_div = document.querySelector(
            `[data-message-id="${message_id}"]`
          );
          if (message_div) {
            const star_button = message_div.querySelector(".star-btn i");
            if (data.starred) {
              star_button.classList.remove("bi-star");
              star_button.classList.add("bi-star-fill");
              star_button.style.color = "#ffc107";
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

  addUnreadSeparator(message_element, unread_count) {
    const messages_div = document.getElementById("messages");
    if (!messages_div) {
      return;
    }

    const separator = document.createElement("div");
    separator.className = "unread-separator";
    separator.innerHTML = `
      <div class="unread-line"></div>
      <div class="unread-label">${unread_count} unread message${
      unread_count > 1 ? "s" : ""
    }</div>
      <div class="unread-line"></div>
    `;

    // Insert the separator before the first unread message
    if (message_element && message_element.parentNode) {
      message_element.parentNode.insertBefore(separator, message_element);
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

  updateChatPreview(chatroom_id, message) {
    const chatroom_item = document.querySelector(
      `.chat-item[data-chatroom-id="${chatroom_id}"]`
    );
    if (chatroom_item) {
      const preview_div = chatroom_item.querySelector(".chat-preview");
      if (preview_div) {
        preview_div.textContent = message;
      }
    }
  }

  addScrollListener() {
    const messages_div = document.getElementById("messages");
    if (messages_div && !this.scroll_listener) {
      this.scroll_listener = () => {
        // Clear any existing timeout
        if (this.scroll_timeout) {
          clearTimeout(this.scroll_timeout);
        }

        // Debounce the scroll handling
        this.scroll_timeout = setTimeout(() => {
          const scroll_top = messages_div.scrollTop;
          const scroll_height = messages_div.scrollHeight;
          const client_height = messages_div.clientHeight;
          const scroll_bottom = scroll_height - scroll_top - client_height;

          // Mark messages as read when near bottom
          if (scroll_bottom < 100) {
            this.markMessagesAsReadAfterScroll();
          }

          // Load older messages when near top
          if (
            scroll_top < 50 &&
            !this.is_loading_messages &&
            this.has_more_messages
          ) {
            this.loadMessages("older");
          }

          // Load newer messages when near bottom
          if (
            scroll_bottom < 150 &&
            !this.is_loading_messages &&
            this.last_message_id > 0
          ) {
            this.loadMessages("newer");
          }
        }, 150); // Debounce time of 150ms
      };

      messages_div.addEventListener("scroll", this.scroll_listener);
    }
  }

  removeScrollListener() {
    const messages_div = document.getElementById("messages");
    if (messages_div && this.scroll_listener) {
      messages_div.removeEventListener("scroll", this.scroll_listener);
      this.scroll_listener = null;

      // Also remove any loading indicators that might be present
      const loadingDiv = messages_div.querySelector(".loading-messages");
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

  loadMessages(direction = "older") {
    if (!this.current_chatroom_id || this.is_loading_messages) {
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

    if (direction === "older") {
      messages_div.insertBefore(loading_div, messages_div.firstChild);
    } else {
      messages_div.appendChild(loading_div);
    }

    this.send({
      type: "load_messages",
      chatroom_id: this.current_chatroom_id,
      direction: direction,
      reference_message_id:
        direction === "older" ? this.oldest_message_id : this.last_message_id,
    });
  }

  handleLoadedMessages(data) {
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

      if (data.direction === "older") {
        // Update oldest message ID
        const new_oldest_id = Math.min(...data.messages.map((m) => m.id));
        if (new_oldest_id < this.oldest_message_id) {
          this.oldest_message_id = new_oldest_id;
        }
      } else {
        // Update last message ID
        const new_last_id = Math.max(...data.messages.map((m) => m.id));
        if (new_last_id > this.last_message_id) {
          this.last_message_id = new_last_id;
        }
      }

      // Create a document fragment to hold new messages
      const fragment = document.createDocumentFragment();
      let current_date = data.direction === "older" ? "" : this.last_date;

      // Process messages in chronological order
      data.messages.forEach((message) => {
        const message_date = new Date(message.created_at);
        const message_date_str = message_date.toLocaleDateString();

        // Only add date separator if it's a new date
        if (message_date_str !== current_date) {
          const date_separator = document.createElement("div");
          date_separator.className = "date-separator";
          const date_span = document.createElement("span");
          date_span.textContent = message_date_str;
          date_separator.appendChild(date_span);
          fragment.appendChild(date_separator);
          current_date = message_date_str;
        }

        const message_div = this.createMessageElement(message);
        message_div.dataset.date = message.created_at;
        fragment.appendChild(message_div);
      });

      // Insert messages and maintain scroll position
      if (data.direction === "older") {
        messages_div.insertBefore(fragment, messages_div.firstChild);
        const height_difference = messages_div.scrollHeight - old_scroll_height;
        messages_div.scrollTop = old_scroll_top + height_difference;
      } else {
        messages_div.appendChild(fragment);
        messages_div.scrollTop = old_scroll_top;
      }

      this.has_more_messages = data.has_more_messages;
    }

    this.is_loading_messages = false;
  }

  handleHistory(data) {
    if (
      data.chatroom_id === this.current_chatroom_id &&
      Array.isArray(data.messages)
    ) {
      // Set viewing history flag if we have a target message
      this.is_viewing_history = !!data.target_message_id;

      // Clear existing messages first
      const messages_div = document.getElementById("messages");
      if (messages_div) {
        messages_div.innerHTML = "";
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
        // Set has_more_messages based on the server response
        this.has_more_messages = data.has_more_messages;
      } else {
        this.has_more_messages = false;
      }

      // Remove any existing scroll listener before adding a new one
      this.removeScrollListener();

      let target_message_element = null;
      let has_unread_messages = false;
      let unread_count = 0;

      // Append each message from the history
      data.messages.forEach((message) => {
        const message_element = this.appendMessage(message);

        // If this is the target message, store its reference
        if (message.id === data.target_message_id) {
          target_message_element = message_element;
        }

        // Count unread messages and mark the first one
        if (message.is_unread) {
          unread_count++;
          if (!has_unread_messages) {
            has_unread_messages = true;
          }
        }
      });

      // Add scroll listener for infinite scrolling
      this.addScrollListener();

      // Handle scrolling to the appropriate position
      if (messages_div) {
        setTimeout(() => {
          if (target_message_element) {
            // If we have a target message, scroll to it and highlight it
            target_message_element.scrollIntoView({
              behavior: "smooth",
              block: "center",
            });
            target_message_element.classList.add("highlighted-message");
            setTimeout(() => {
              target_message_element.classList.remove("highlighted-message");
              // Reset viewing history flag after highlighting is done
              this.is_viewing_history = false;
            }, 3000);
          } else if (has_unread_messages) {
            // If we have unread messages, scroll to the first unread
            const separator = messages_div.querySelector(".unread-separator");
            if (separator) {
              separator.scrollIntoView({ behavior: "smooth", block: "start" });
            }
          } else {
            // Otherwise scroll to bottom
            messages_div.scrollTop = messages_div.scrollHeight;
          }
        }, 100);
      }

      // Mark messages as read after a short delay
      setTimeout(() => {
        this.markMessagesAsRead(data.chatroom_id);
      }, 1000);
    }
  }

  handleIncomingMessage(data) {
    console.log("Handling incoming message:", data);

    // Update last_message_id if this is a newer message
    if (data.id > this.last_message_id) {
      this.last_message_id = data.id;
    }

    // Check if the chatroom exists in the sidebar
    const chatroom_exists = document.querySelector(
      `[data-chatroom-id="${data.chatroom_id}"]`
    );

    if (!chatroom_exists && data.user_id !== this.user_id) {
      // Request updated chatroom list to get the new chatroom
      this.send({
        type: "update_chatroom_list",
        current_chatroom_id: this.current_chatroom_id,
      });
    }

    this.updateChatPreview(data.chatroom_id, data.message);

    // Only proceed if this is for the current chatroom
    if (data.chatroom_id === this.current_chatroom_id) {
      // Check if this message already exists (either as temp or permanent)
      const existingMessage =
        document.querySelector(`[data-message-id="${data.id}"]`) ||
        document.querySelector(`[data-temp-id="${data.temp_id}"]`);

      if (existingMessage) {
        console.log("Message already exists, updating if needed:", data);
        // If it's our message being confirmed by the server
        if (data.user_id === this.user_id && data.temp_id) {
          existingMessage.dataset.messageId = data.id;
          delete existingMessage.dataset.tempId;
          this.updateMessageStatus(data.id, "sent");
          this.sent_message_ids.delete(data.temp_id.toString());
        }
      } else if (data.user_id !== this.user_id) {
        // Only append new messages from other users
        console.log("Appending new message from other user:", data);
        const message = {
          id: data.id,
          user_id: data.user_id,
          username: data.username,
          content: data.message,
          created_at: data.created_at,
          type: "text",
        };
        this.appendMessage(message);
      }

      // Remove any existing unread separator
      const messages_div = document.getElementById("messages");
      const existing_separator =
        messages_div.querySelector(".unread-separator");
      if (existing_separator) {
        existing_separator.remove();
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
  }

  // Helper method to create message element
  createMessageElement(message) {
    const message_div = document.createElement("div");
    message_div.className = `message ${
      message.user_id === this.user_id ? "sent" : "received"
    }`;
    message_div.dataset.messageId = message.id;

    const header_div = document.createElement("div");
    header_div.className =
      "d-flex justify-content-between align-items-center mb-1";

    const star_button = document.createElement("button");
    star_button.className = "btn btn-sm star-btn";
    star_button.innerHTML = '<i class="bi bi-star"></i>';
    star_button.onclick = (e) => {
      e.stopPropagation();
      this.toggleStar(message.id, star_button);
    };

    header_div.appendChild(star_button);

    const content_div = document.createElement("div");
    content_div.className = "content";

    content_div.textContent = message.content;

    const timestamp_div = document.createElement("div");
    timestamp_div.className = "timestamp";
    // Convert UTC time to local time
    const message_time = new Date(message.created_at);
    timestamp_div.textContent = message_time.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });

    message_div.appendChild(header_div);
    message_div.appendChild(content_div);
    message_div.appendChild(timestamp_div);

    // Check if message is starred
    this.checkStarStatus(message.id);

    return message_div;
  }

  updateConnectionStatus(status) {
    // Create or get the status element
    if (!this.connection_status_element) {
      this.connection_status_element = document.createElement("div");
      this.connection_status_element.className = "connection-status";
      document.body.appendChild(this.connection_status_element);
    }

    switch (status) {
      case "connected":
        this.connection_status_element.style.display = "none";
        break;
      case "disconnected":
        this.connection_status_element.style.display = "block";
        this.connection_status_element.innerHTML =
          '<div class="alert alert-warning" role="alert">Disconnected. Attempting to reconnect...</div>';
        break;
      case "error":
        this.connection_status_element.style.display = "block";
        this.connection_status_element.innerHTML =
          '<div class="alert alert-danger" role="alert">Connection error. Retrying...</div>';
        break;
    }
  }

  updateMessageStatus(message_id, status) {
    const message_element = document.querySelector(
      `[data-message-id="${message_id}"]`
    );
    if (message_element) {
      const status_element =
        message_element.querySelector(".message-status") ||
        message_element.appendChild(document.createElement("div"));
      status_element.className = "message-status";

      switch (status) {
        case "pending":
          status_element.innerHTML = '<i class="bi bi-clock text-warning"></i>';
          break;
        case "failed":
          status_element.innerHTML =
            '<i class="bi bi-exclamation-circle text-danger"></i> Failed to send. ' +
            '<a href="javascript:void(0)" onclick="chat_ws.resendMessage(' +
            message_id +
            ')">Retry</a>';
          break;
        case "sent":
          status_element.remove(); // Remove status indicator when sent successfully
          break;
      }
    }
  }

  resendMessage(temp_id) {
    console.log("Resending message:", temp_id);
    const pending_message = this.pending_messages.find(
      (m) => m.temp_id === temp_id
    );
    if (pending_message) {
      this.sendMessage(pending_message.message);
      this.pending_messages = this.pending_messages.filter(
        (m) => m.temp_id !== temp_id
      );
    }
  }

  resendPendingMessages() {
    while (this.pending_messages.length > 0) {
      const message = this.pending_messages.shift();
      this.sendMessage(message.message);
    }
  }
}

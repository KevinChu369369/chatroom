class WebSocketHandler {
  constructor(user_id) {
    this.ws = null;
    this.user_id = user_id;
    this.current_chatroom_id = null;
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
    this.current_date = null;
    this.last_load_time = 0; // Track last load time to prevent rapid calls
    this.load_cooldown = 1000; // 1 second cooldown between loads
    document.addEventListener("click", () => {
      this.attemptReconnect();
    });
  }

  async connect() {
    try {
      // First get a secure token
      const response = await fetch("api/get_ws_token.php");
      const data = await response.json();
      this.token = data.token;

      this.ws = new WebSocket("ws://localhost:9501");

      this.ws.onopen = () => {
        this.is_connected = true;
        this.authenticate();
        this.processPendingRequests();

        // Try to resend any pending messages
        this.resendPendingMessages();
      };

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);

          switch (data.type) {
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

            case "group_created":
              this.handleGroupCreated(data);
              break;

            case "new_group_notification":
              this.handleNewGroupNotification(data);
              break;

            case "group_settings_updated":
              this.handleGroupSettingsUpdated(data);
              break;
          }
        } catch (error) {
          console.error("Error processing message:", error);
        }
      };

      this.ws.onclose = (event) => {
        this.is_connected = false;
      };

      this.ws.onerror = (error) => {
        console.error("WebSocket error:", error);
        this.is_connected = false;
      };
    } catch (error) {
      console.error("Connection error:", error);
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
    if (!this.is_connected) {
      this.connect();
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
      return;
    }

    const created_message_div = this.createMessageElement(message);
    if (message.temp_id) {
      created_message_div.dataset.tempId = message.temp_id;
    }
    created_message_div.dataset.date = message.created_at;
    messages_div.appendChild(created_message_div);

    // Update date separators after adding new message
    this.updateVisibleDateSeparator();

    // Auto-scroll logic
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
      // Simple throttled scroll handler for date separators
      let last_update = 0;
      messages_div.addEventListener("scroll", () => {
        const now = Date.now();
        if (now - last_update > 50) {
          // Throttle to max 20 updates per second
          this.updateVisibleDateSeparator();
          last_update = now;
        }
      });

      // Debounced listener for other scroll-related operations
      this.scroll_listener = () => {
        if (this.scroll_timeout) {
          clearTimeout(this.scroll_timeout);
        }

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
            this.attemptReconnect();
            this.loadMessages("older");
          }

          // Load newer messages when near bottom
          if (
            scroll_bottom < 150 &&
            !this.is_loading_messages &&
            this.last_message_id > 0
          ) {
            this.attemptReconnect();
            this.loadMessages("newer");
          }
        }, 150);
      };

      messages_div.addEventListener("scroll", this.scroll_listener);
    }
  }

  updateVisibleDateSeparator() {
    const messages_div = document.getElementById("messages");
    if (!messages_div) return;

    const message_elements = Array.from(
      messages_div.querySelectorAll("[data-date]")
    );
    if (!message_elements.length) return;

    const scroll_top = messages_div.scrollTop;
    let current_visible_date = null;

    // Remove all existing separators first
    messages_div
      .querySelectorAll(".date-separator")
      .forEach((sep) => sep.remove());

    // Create a map to store first message for each date
    const date_first_messages = new Map();

    // Find first visible message and first message of each date
    message_elements.forEach((message) => {
      const message_top = message.offsetTop;
      const message_date = new Date(message.dataset.date).toLocaleDateString();

      // Track first message of each date
      if (!date_first_messages.has(message_date)) {
        date_first_messages.set(message_date, message);
      }

      // Find first visible message's date
      if (message_top >= scroll_top && !current_visible_date) {
        current_visible_date = message_date;
      }
    });

    // Add separators for each unique date
    date_first_messages.forEach((first_message, date) => {
      const separator = document.createElement("div");
      separator.className = "date-separator";
      if (date === current_visible_date) {
        separator.classList.add("sticky");
      }

      const date_span = document.createElement("span");
      date_span.textContent = date;
      separator.appendChild(date_span);

      // Insert before the first message of this date
      messages_div.insertBefore(separator, first_message);
    });
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
    const now = Date.now();

    // Check cooldown to prevent rapid successive calls
    if (now - this.last_load_time < this.load_cooldown) {
      return;
    }

    if (!this.current_chatroom_id || this.is_loading_messages) {
      return;
    }

    this.last_load_time = now;

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

      // Remove existing date separators
      const existing_separators =
        messages_div.querySelectorAll(".date-separator");
      existing_separators.forEach((sep) => sep.remove());

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
      let messages_actually_added = 0; // Track how many messages were actually added

      // Process messages in chronological order
      data.messages.forEach((message) => {
        const message_date = new Date(message.created_at);
        const message_date_str = message_date.toLocaleDateString();

        if (message_date_str !== current_date) {
          const date_separator = document.createElement("div");
          date_separator.className = "date-separator sticky";
          const date_span = document.createElement("span");
          date_span.textContent = message_date_str;
          date_separator.appendChild(date_span);
          fragment.appendChild(date_separator);
          current_date = message_date_str;
        }

        // Skip system messages that don't belong to the current user
        if (message.is_system && message.user_id !== this.user_id) {
          return; // Skip this message in forEach
        }

        let message_div;
        if (message.is_system) {
          message_div = this.createSystemMessageElement(message);
        } else {
          message_div = this.createMessageElement(message);
        }
        message_div.dataset.date = message.created_at;
        fragment.appendChild(message_div);
        messages_actually_added++; // Increment counter for actually added messages
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

      // If no messages were actually added due to filtering, prevent further loading for this direction
      if (
        messages_actually_added === 0 &&
        data.messages &&
        data.messages.length > 0
      ) {
        this.has_more_messages = false;
      }
    }

    this.is_loading_messages = false;
  }

  handleHistory(data) {
    if (
      data.chatroom_id === this.current_chatroom_id &&
      Array.isArray(data.messages)
    ) {
      const messages_div = document.getElementById("messages");
      if (!messages_div) return;

      // Set viewing history flag if we have a target message
      this.is_viewing_history = !!data.target_message_id;

      // Clear existing messages
      messages_div.innerHTML = "";

      // Reset tracking variables
      this.last_message_id = 0;
      this.oldest_message_id = Infinity;
      this.has_more_messages = data.has_more_messages;
      this.is_loading_messages = false;

      if (data.messages.length > 0) {
        // Sort messages by date
        const sorted_messages = [...data.messages].sort(
          (a, b) => new Date(a.created_at) - new Date(b.created_at)
        );

        // Group messages by date
        let current_date = null;
        let target_message_element = null;

        sorted_messages.forEach((message) => {
          const message_date = new Date(
            message.created_at
          ).toLocaleDateString();

          // Add date separator if date changes
          if (message_date !== current_date) {
            const date_separator = document.createElement("div");
            date_separator.className = "date-separator";
            const date_span = document.createElement("span");
            date_span.textContent = message_date;
            date_separator.appendChild(date_span);
            messages_div.appendChild(date_separator);
            current_date = message_date;
          }

          // Skip system messages that don't belong to the current user
          if (message.is_system && message.user_id !== this.user_id) {
            return; // Skip this message in forEach
          }

          // Add message based on type
          let message_element;

          if (message.is_system) {
            message_element = this.appendSystemMessage(message);
          } else {
            message_element = this.appendMessage(message);
          }

          // Store reference if this is the target message
          if (message.id === data.target_message_id) {
            target_message_element = message_element;
          }

          // Update tracking variables
          this.last_message_id = Math.max(this.last_message_id, message.id);
          this.oldest_message_id = Math.min(this.oldest_message_id, message.id);
        });

        // Update date separators after all messages are added
        setTimeout(() => {
          this.updateVisibleDateSeparator();

          // Handle scrolling to target message if exists
          if (target_message_element) {
            target_message_element.scrollIntoView({
              behavior: "smooth",
              block: "center",
            });
            target_message_element.classList.add("highlighted-message");
            setTimeout(() => {
              target_message_element.classList.remove("highlighted-message");
              this.is_viewing_history = false;
            }, 3000);
          } else {
            // If no target message, scroll to bottom
            messages_div.scrollTop = messages_div.scrollHeight;
          }
        }, 0);
      }

      // Add scroll listener
      this.addScrollListener();
    }
  }

  handleIncomingMessage(data) {
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
        // If it's our message being confirmed by the server
        if (data.user_id === this.user_id && data.temp_id) {
          existingMessage.dataset.messageId = data.id;
          delete existingMessage.dataset.tempId;
          this.updateMessageStatus(data.id, "sent");
          this.sent_message_ids.delete(data.temp_id.toString());
        }
      } else if (data.user_id !== this.user_id) {
        // Only append new messages from other users
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

  handleGroupCreated(data) {
    if (data.success) {
      // Store the chatroom data temporarily,
      // this is used to add the chatroom to the sidebar
      // before the system message is added to the chatroom
      //vertical_nav.js have the same variable
      window.pending_chat_room = data.chatroom;

      // Switch to chats view first
      const chats_btn = document.querySelector("#chats-menu-btn");
      if (chats_btn) {
        // when the chats button is clicked, the chats view is switched to
        // and the chatroom is added to the sidebar(vertical_nav.js code)
        chats_btn.click();
      }
    }
  }

  handleNewGroupNotification(data) {
    // Only add if not already being added
    // Add the new group to the chatroom list
    const chatroom = data.chatroom;
    window.addChatroomToSidebar(chatroom);
  }

  handleGroupSettingsUpdated(data) {
    if (data.success) {
      // Check if the current user is the one who made the change
      const is_current_user_change = data.changed_by_user_id === this.user_id;

      // If we're in the group settings modal and we made the change, handle the response
      const settings_modal = document.getElementById("groupSettingsModal");
      if (
        settings_modal &&
        settings_modal.classList.contains("show") &&
        is_current_user_change
      ) {
        window.handleGroupSettingsResponse(data);
      } else {
        // Update UI for other users (including those with modal open in view-only mode)
        if (
          data.name_changed &&
          data.chatroom_id === this.current_chatroom_id
        ) {
          window.updateGroupNameInUI(data.settings.name);
        }

        // Update view-only elements if modal is open (for non-admin viewers)
        if (data.name_changed) {
          const name_view = document.getElementById("settingsGroupNameView");
          if (name_view) {
            name_view.textContent = data.settings.name;
          }
        }

        if (data.description_changed) {
          const desc_view = document.getElementById(
            "settingsGroupDescriptionView"
          );
          if (desc_view) {
            desc_view.textContent = data.settings.description || "";
          }
        }

        // Update sidebar for all users
        if (data.name_changed) {
          const chatroom_item = document.querySelector(
            `[data-chatroom-id="${data.chatroom_id}"] .room-name-text`
          );
          if (chatroom_item) {
            chatroom_item.textContent = data.settings.name;
          }

          // Update avatar
          const avatar = document.querySelector(
            `[data-chatroom-id="${data.chatroom_id}"] .user-avatar`
          );
          if (avatar) {
            avatar.textContent = this.getInitials(data.settings.name);
          }
        }

        // Add system message if we're viewing this chatroom
        if (
          data.system_message &&
          data.chatroom_id === this.current_chatroom_id
        ) {
          this.appendSystemMessage(data.system_message);
        }
      }
    }
  }

  getInitials(name) {
    const words = name.split(" ");
    let initials = "";
    words.forEach((word) => {
      initials += word.charAt(0).toUpperCase();
    });
    return initials.substring(0, 2);
  }

  appendSystemMessage(message) {
    const messages_div = document.getElementById("messages");

    if (!messages_div) return;

    // Check if this system message already exists by ID
    if (message.id) {
      const existing_message = messages_div.querySelector(
        `[data-message-id="${message.id}"]`
      );
      if (existing_message) {
        return existing_message;
      }
    }

    const system_div = document.createElement("div");
    system_div.className = "message system";
    system_div.dataset.date = message.created_at;
    if (message.id) {
      system_div.dataset.messageId = message.id;
      // Update last_message_id to prevent duplicate loading
      if (message.id > this.last_message_id) {
        this.last_message_id = message.id;
      }
    }

    const content_div = document.createElement("div");
    content_div.className = "content";
    content_div.textContent = message.content;

    const timestamp_div = document.createElement("div");
    timestamp_div.className = "timestamp";
    const message_time = new Date(message.created_at);
    timestamp_div.textContent = message_time.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });

    system_div.appendChild(content_div);
    system_div.appendChild(timestamp_div);

    // Add date separator if needed
    this.updateVisibleDateSeparator();

    // Insert the system message at the appropriate chronological position
    const existing_messages = Array.from(
      messages_div.querySelectorAll("[data-date]")
    );
    let insert_position = null;

    // Find the correct position to insert based on timestamp
    for (let i = 0; i < existing_messages.length; i++) {
      const existing_msg = existing_messages[i];
      const existing_time = new Date(existing_msg.dataset.date);

      if (message_time < existing_time) {
        insert_position = existing_msg;
        break;
      }
    }

    if (insert_position) {
      messages_div.insertBefore(system_div, insert_position);
    } else {
      messages_div.appendChild(system_div);
    }

    return system_div;
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

    // Add username for group chats only (above content) - but NOT for system messages

    if (is_group && message.username && !message.is_system) {
      const username_div = document.createElement("div");
      username_div.className = "username";
      username_div.textContent = message.username;
      message_div.appendChild(username_div);
    }

    message_div.appendChild(content_div);
    message_div.appendChild(timestamp_div);

    // Check if message is starred
    this.checkStarStatus(message.id);

    return message_div;
  }

  // Helper method to create system message element (without adding to DOM)
  createSystemMessageElement(message) {
    const system_div = document.createElement("div");
    system_div.className = "message system";
    system_div.dataset.date = message.created_at;
    if (message.id) {
      system_div.dataset.messageId = message.id;
    }

    const content_div = document.createElement("div");
    content_div.className = "content";
    content_div.textContent = message.content;

    const timestamp_div = document.createElement("div");
    timestamp_div.className = "timestamp";
    const message_time = new Date(message.created_at);
    timestamp_div.textContent = message_time.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });

    system_div.appendChild(content_div);
    system_div.appendChild(timestamp_div);

    return system_div;
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

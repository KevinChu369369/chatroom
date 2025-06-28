// Handle vertical nav toggle
const nav_toggle = document.querySelector(".nav-toggle");
const vertical_nav = document.querySelector(".vertical-nav");

nav_toggle.addEventListener("click", function () {
  vertical_nav.classList.toggle("expanded");
  document.querySelector(".main-layout").classList.toggle("nav-expanded");
});

// Close nav when clicking outside
document.addEventListener("click", function (e) {
  if (
    !vertical_nav.contains(e.target) &&
    vertical_nav.classList.contains("expanded")
  ) {
    vertical_nav.classList.remove("expanded");
    document.querySelector(".main-layout").classList.remove("nav-expanded");
  }
});

// Function to set active menu item
function setActiveMenuItem(clicked_link) {
  // Remove active class from all nav links
  document.querySelectorAll(".nav-menu .nav-link").forEach((link) => {
    link.classList.remove("active");
  });
  // Add active class to clicked link
  clicked_link.classList.add("active");
}

// Handle contacts view
document.addEventListener("DOMContentLoaded", function () {
  const contacts_link = document.querySelector("#contacts-menu-btn");
  if (contacts_link) {
    contacts_link.addEventListener("click", function (e) {
      e.preventDefault();
      setActiveMenuItem(this);

      // Load contacts into sidebar
      fetch("contacts_sidebar.php", {
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      })
        .then((response) => response.text())
        .then((html) => {
          const chat_side_bar = document.querySelector(".chat-sidebar");
          if (chat_side_bar) {
            // Store the current sidebar content
            if (!chat_side_bar.dataset.originalContent) {
              chat_side_bar.dataset.originalContent = chat_side_bar.innerHTML;
            }
            chat_side_bar.innerHTML = html;

            // Initialize contact search after loading the sidebar
            const contact_search = document.getElementById("contactSearch");
            if (contact_search) {
              contact_search.addEventListener("input", function (e) {
                const search_term = e.target.value.toLowerCase();
                document.querySelectorAll(".contact-item").forEach((item) => {
                  const contact_name = item
                    .querySelector(".contact-name")
                    .textContent.toLowerCase();
                  const contact_email = item
                    .querySelector(".contact-email")
                    .textContent.toLowerCase();
                  item.style.display =
                    contact_name.includes(search_term) ||
                    contact_email.includes(search_term)
                      ? "flex"
                      : "none";
                });
              });
            }
          }
        })
        .catch((error) => {
          alert("Error loading contacts : " + error);
        });
    });
  }

  // Add click handler for chats link to restore original sidebar
  const chats_link = document.querySelector("#chats-menu-btn");
  if (chats_link) {
    chats_link.addEventListener("click", function (e) {
      e.preventDefault();
      setActiveMenuItem(this);

      const chat_side_bar = document.querySelector(".chat-sidebar");
      if (chat_side_bar && chat_side_bar.dataset.originalContent) {
        chat_side_bar.innerHTML = chat_side_bar.dataset.originalContent;

        // Re-attach click handlers to all chat items
        document.querySelectorAll(".chat-item").forEach((item) => {
          item.addEventListener("click", function () {
            const chatroom_id = parseInt(this.dataset.chatroomId);
            document
              .querySelectorAll(".chat-item")
              .forEach((i) => i.classList.remove("active"));
            this.classList.add("active");

            // Update current room and group status. variable is used in index.php
            current_room = chatroom_id;
            is_group = this.querySelector(".chat-info").dataset.isGroup === "1";

            // Get room data
            const room_name_span = this.querySelector(
              ".chat-name span:first-child"
            );
            const room_name = room_name_span
              ? room_name_span.textContent.trim()
              : "";
            const is_group_chat =
              this.querySelector(".chat-info").dataset.isGroup === "1";

            // Hide welcome message and show chat container
            document.getElementById("welcome-message").classList.add("d-none");
            const chat_container = document.getElementById("chat-container");
            chat_container.classList.remove("d-none");

            // Generate and set chat interface
            const room_data = {
              name: room_name,
              is_group: is_group_chat === "1",
              member_count: 0,
              creator_name: "",
              is_creator: false,
            };
            chat_container.innerHTML = window.generateChatInterface(room_data);

            // Initialize emoji picker for new chat interface
            const emoji_button = document.getElementById("emojiButton");
            if (emoji_button) {
              emoji_button.addEventListener("click", () => {
                window.picker.toggle();
              });
            }

            // Initialize message input event listener
            const message_input = document.getElementById("messageInput");
            if (message_input) {
              message_input.addEventListener("keypress", function (e) {
                if (e.key === "Enter") {
                  window.sendMessage();
                }
              });
            }

            history.pushState(
              {
                chatroom_id,
              },
              "",
              "index.php"
            );

            // Reset message tracking variables. variables are used in index.php
            last_message_id = 0;
            last_date = "";

            // Load chatroom
            loadChatroom(chatroom_id);
          });
        });

        // If there's a pending chatroom from contacts view, handle it
        if (window.pending_chat_room) {
          const chatroom_id = window.pending_chat_room.id;
          // Add the chatroom to sidebar if it doesn't exist
          if (!document.querySelector(`[data-chatroom-id="${chatroom_id}"]`)) {
            window.addChatroomToSidebar(window.pending_chat_room);
          }
          // Find and click the chatroom
          const chatroom_item = document.querySelector(
            `[data-chatroom-id="${chatroom_id}"]`
          );
          if (chatroom_item) {
            const click_event = new Event("click", { bubbles: true });
            chatroom_item.dispatchEvent(click_event);
          }
          // Clear the pending chatroom
          delete window.pending_chat_room;
        }
      }
    });
  }
});

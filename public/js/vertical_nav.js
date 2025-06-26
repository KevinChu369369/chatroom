// Handle vertical nav toggle
const navToggle = document.querySelector(".nav-toggle");
const verticalNav = document.querySelector(".vertical-nav");

navToggle.addEventListener("click", function () {
  verticalNav.classList.toggle("expanded");
  document.querySelector(".main-layout").classList.toggle("nav-expanded");
});

// Close nav when clicking outside
document.addEventListener("click", function (e) {
  if (
    !verticalNav.contains(e.target) &&
    verticalNav.classList.contains("expanded")
  ) {
    verticalNav.classList.remove("expanded");
    document.querySelector(".main-layout").classList.remove("nav-expanded");
  }
});

// Function to set active menu item
function setActiveMenuItem(clickedLink) {
  // Remove active class from all nav links
  document.querySelectorAll(".nav-menu .nav-link").forEach((link) => {
    link.classList.remove("active");
  });
  // Add active class to clicked link
  clickedLink.classList.add("active");
}

// Handle contacts view
document.addEventListener("DOMContentLoaded", function () {
  const contactsLink = document.querySelector("#contacts-menu-btn");
  if (contactsLink) {
    contactsLink.addEventListener("click", function (e) {
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
          const chatSidebar = document.querySelector(".chat-sidebar");
          if (chatSidebar) {
            // Store the current sidebar content
            if (!chatSidebar.dataset.originalContent) {
              chatSidebar.dataset.originalContent = chatSidebar.innerHTML;
            }
            chatSidebar.innerHTML = html;

            // Initialize contact search after loading the sidebar
            const contactSearch = document.getElementById("contactSearch");
            if (contactSearch) {
              contactSearch.addEventListener("input", function (e) {
                const searchTerm = e.target.value.toLowerCase();
                document.querySelectorAll(".contact-item").forEach((item) => {
                  const contactName = item
                    .querySelector(".contact-name")
                    .textContent.toLowerCase();
                  const contactEmail = item
                    .querySelector(".contact-email")
                    .textContent.toLowerCase();
                  item.style.display =
                    contactName.includes(searchTerm) ||
                    contactEmail.includes(searchTerm)
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
  const chatsLink = document.querySelector("#chats-menu-btn");
  if (chatsLink) {
    chatsLink.addEventListener("click", function (e) {
      e.preventDefault();
      setActiveMenuItem(this);

      const chatSidebar = document.querySelector(".chat-sidebar");
      if (chatSidebar && chatSidebar.dataset.originalContent) {
        chatSidebar.innerHTML = chatSidebar.dataset.originalContent;

        // Re-attach click handlers to all chat items
        document.querySelectorAll(".chat-item").forEach((item) => {
          item.addEventListener("click", function () {
            const chatroomId = parseInt(this.dataset.chatroomId);
            document
              .querySelectorAll(".chat-item")
              .forEach((i) => i.classList.remove("active"));
            this.classList.add("active");

            // Update current room and group status
            window.currentRoom = chatroomId;
            window.isGroup =
              this.querySelector(".chat-info").dataset.isGroup === "1";

            // Get room data
            const roomNameSpan = this.querySelector(
              ".chat-name span:first-child"
            );
            const roomName = roomNameSpan
              ? roomNameSpan.textContent.trim()
              : "";
            const isGroupChat =
              this.querySelector(".chat-info").dataset.isGroup === "1";

            // Hide welcome message and show chat container
            document.getElementById("welcome-message").classList.add("d-none");
            const chatContainer = document.getElementById("chat-container");
            chatContainer.classList.remove("d-none");

            // Generate and set chat interface
            const roomData = {
              name: roomName,
              is_group: isGroupChat === "1",
              member_count: 0,
              creator_name: "",
              is_creator: false,
            };
            chatContainer.innerHTML = window.generateChatInterface(roomData);

            // Initialize emoji picker for new chat interface
            const emojiButton = document.getElementById("emojiButton");
            if (emojiButton) {
              emojiButton.addEventListener("click", () => {
                window.picker.toggle();
              });
            }

            // Initialize message input event listener
            const messageInput = document.getElementById("messageInput");
            if (messageInput) {
              messageInput.addEventListener("keypress", function (e) {
                if (e.key === "Enter") {
                  window.sendMessage();
                }
              });
            }

            history.pushState(
              {
                chatroomId,
              },
              "",
              "index.php"
            );
            window.lastMessageId = 0;
            window.lastDate = "";
            window.loadChatroom(chatroomId);
          });
        });

        // If there's a pending chatroom from contacts view, handle it
        if (window.pendingChatroom) {
          const chatroomId = window.pendingChatroom.id;
          // Add the chatroom to sidebar if it doesn't exist
          if (!document.querySelector(`[data-chatroom-id="${chatroomId}"]`)) {
            window.addChatroomToSidebar(window.pendingChatroom);
          }
          // Find and click the chatroom
          const chatroomItem = document.querySelector(
            `[data-chatroom-id="${chatroomId}"]`
          );
          if (chatroomItem) {
            const clickEvent = new Event("click", { bubbles: true });
            chatroomItem.dispatchEvent(clickEvent);
          }
          // Clear the pending chatroom
          delete window.pendingChatroom;
        }
      }
    });
  }
});

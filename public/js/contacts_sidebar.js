function startChat(userId) {
  const form_data = new FormData();
  form_data.append("action", "create_direct_chat");
  form_data.append("user_id", userId);

  fetch("api/chatroom_actions.php", {
    method: "POST",
    body: form_data,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success && data.chatroom) {
        // Switch back to chats view
        const chats_btn = document.querySelector("#chats-menu-btn");
        if (chats_btn) {
          // Store the chatroom data to be used after view switch
          window.pending_chat_room = data.chatroom;
          chats_btn.click();

          // Add a small delay to ensure the chat view is loaded
          setTimeout(() => {
            // Find the chatroom item
            const chatroom_id = data.chatroom.id;
            if (chatroom_id) {
              const chat_room_item = document.querySelector(
                `[data-chatroom-id="${chatroom_id}"]`
              );
              if (chat_room_item) {
                // Trigger the click event
                const click_event = new Event("click", { bubbles: true });
                chat_room_item.dispatchEvent(click_event);
              }
            }
          }, 300); // Increased delay to ensure DOM is ready
        }
      } else {
        alert(data.message || "Failed to start chat");
      }
    })
    .catch((error) => {
      console.error("Error starting chat:", error);
      alert("An error occurred while starting the chat");
    });
}

function createGroup() {
  const form = document.getElementById("createGroupForm");
  const form_data = new FormData(form);

  // Validate group name
  const group_name = form_data.get("group_name").trim();
  if (!group_name) {
    showError("Group name is required");
    return;
  }

  // Validate member selection
  const selected_members = form_data.getAll("members[]");
  if (selected_members.length === 0) {
    showError("Please select at least one member");
    return;
  }

  // Send group creation request via WebSocket
  if (window.chat_ws && window.chat_ws.is_connected) {
    window.chat_ws.send({
      type: "create_group",
      name: group_name,
      members: selected_members,
    });

    // Close the modal
    const modal = bootstrap.Modal.getInstance(
      document.getElementById("createGroupModal")
    );
    modal.hide();
  } else {
    showError("WebSocket connection is not available");
  }
}

function showError(message) {
  const alert = document.querySelector("#createGroupModal .alert-danger");
  alert.textContent = message;
  alert.style.display = "block";
}

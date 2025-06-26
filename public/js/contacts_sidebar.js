function startChat(userId) {
  const formData = new FormData();
  formData.append("action", "create_direct_chat");
  formData.append("user_id", userId);

  fetch("api/chatroom_actions.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      console.log("startChat data", data);
      if (data.success && data.chatroom) {
        // Switch back to chats view
        const chatsBtn = document.querySelector("#chats-menu-btn");
        if (chatsBtn) {
          // Store the chatroom data to be used after view switch
          window.pendingChatroom = data.chatroom;
          chatsBtn.click();

          // Add a small delay to ensure the chat view is loaded
          setTimeout(() => {
            // Find the chatroom item
            const chatroomId = data.chatroom.id;
            if (chatroomId) {
              const chatroomItem = document.querySelector(
                `[data-chatroom-id="${chatroomId}"]`
              );
              if (chatroomItem) {
                console.log("chatroomItem click", chatroomItem);
                // Trigger the click event
                const clickEvent = new Event("click", { bubbles: true });
                chatroomItem.dispatchEvent(clickEvent);
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
  const formData = new FormData(form);
  formData.append("action", "create_group");

  // Validate group name
  const groupName = formData.get("group_name").trim();
  if (!groupName) {
    showError("Group name is required");
    return;
  }

  // Validate member selection
  const selectedMembers = formData.getAll("members[]");
  if (selectedMembers.length === 0) {
    showError("Please select at least one member");
    return;
  }

  fetch("api/chatroom_actions.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        // Close the modal and reload the chat list
        const modal = bootstrap.Modal.getInstance(
          document.getElementById("createGroupModal")
        );
        modal.hide();
        window.location.reload();
      } else {
        showError(data.message);
      }
    });
}

function showError(message) {
  const alert = document.querySelector("#createGroupModal .alert-danger");
  alert.textContent = message;
  alert.style.display = "block";
}

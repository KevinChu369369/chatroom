<?php
require_once __DIR__ . '/../check_session.php';
require_once __DIR__ . '/../../config.php';
?>

<!-- Group Settings Modal -->
<div class="modal fade" id="groupSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Group Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="groupSettingsForm">
                    <input type="hidden" id="settingsChatroomId" name="chatroom_id">

                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <!-- View-only for non-admins -->
                        <div class="form-control-plaintext view-only" id="settingsGroupNameView"></div>
                        <!-- Editable for admins -->
                        <input type="text" class="form-control admin-only" id="settingsGroupName" name="group_name" required maxlength="30">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <!-- View-only for non-admins -->
                        <div class="form-control-plaintext view-only" id="settingsGroupDescriptionView"></div>
                        <!-- Editable for admins -->
                        <textarea class="form-control admin-only" id="settingsGroupDescription" name="group_description" rows="3" maxlength="255" placeholder="Add a description for this group..."></textarea>
                        <div class="form-text admin-only">Maximum 255 characters</div>
                    </div>

                    <div class="alert alert-danger" id="groupSettingsError" style="display: none;"></div>
                    <div class="alert alert-success" id="groupSettingsSuccess" style="display: none;"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary admin-only" onclick="updateGroupSettings()">
                    <span class="spinner-border spinner-border-sm d-none" id="settingsSpinner"></span>
                    Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Modal specific overrides */
    #groupSettingsModal .modal-content {
        background-color: var(--app-bg);
        color: var(--app-text);
    }

    #groupSettingsModal .modal-header {
        border-bottom-color: var(--app-border);
    }

    #groupSettingsModal .btn-close {
        color: var(--app-text);
    }

    #groupSettingsModal .form-control {
        background-color: var(--app-bg);
        border-color: var(--app-border);
        color: var(--app-text);
    }

    #groupSettingsModal .form-control:focus {
        background-color: var(--app-bg);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        color: var(--app-text);
    }

    #groupSettingsModal .form-text {
        color: var(--app-text-muted);
    }

    #groupSettingsModal textarea {
        resize: vertical;
        min-height: 60px;
    }

    #groupSettingsModal .spinner-border-sm {
        width: 1rem;
        height: 1rem;
        margin-right: 0.5rem;
    }

    /* Admin/View-only element visibility */
    #groupSettingsModal:not(.is-admin) .admin-only {
        display: none !important;
    }

    #groupSettingsModal.is-admin .view-only {
        display: none !important;
    }

    #groupSettingsModal:not(.is-admin) .view-only {
        display: block !important;
    }

    /* Style for view-only text */
    #groupSettingsModal .form-control-plaintext {
        padding: 0.375rem 0.75rem;
        margin-bottom: 0;
        font-size: 1rem;
        line-height: 1.5;
        color: var(--app-text);
        background-color: transparent;
        border-radius: 0.375rem;
        min-height: 38px;
        display: flex;
        align-items: center;
    }

    #groupSettingsModal .form-control-plaintext:empty::before {
        content: "No description set";
        color: var(--app-text-muted);
        font-style: italic;
    }
</style>
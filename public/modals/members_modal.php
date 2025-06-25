<div class="modal fade" id="membersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Group Members</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php
                    $stmt = $conn->prepare("
                        SELECT u.id, u.username, u.is_admin, cm.is_active
                        FROM users u
                        JOIN chatroom_members cm ON u.id = cm.user_id
                        WHERE cm.chatroom_id = ?
                        ORDER BY u.username
                    ");
                    $stmt->bind_param("i", $current_room);
                    $stmt->execute();
                    $members_result = $stmt->get_result();

                    while ($member = $members_result->fetch_assoc()):
                        $is_creator = $member['id'] === $room['created_by'];
                        $is_current_user = $member['id'] === $_SESSION['user_id'];
                        $can_manage = $room['created_by'] === $_SESSION['user_id'] && !$is_creator && $member['is_active'];
                    ?>
                        <div class="list-group-item">
                            <div class="d-flex align-items-center">
                                <div class="user-avatar me-3">
                                    <?php echo get_initials($member['username']); ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0">
                                        <?php echo htmlspecialchars($member['username']); ?>
                                        <?php if ($is_creator): ?>
                                            <span class="badge bg-primary">Creator</span>
                                        <?php endif; ?>
                                        <?php if (!$member['is_active']): ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                <?php if ($can_manage): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-link text-dark p-0" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button class="dropdown-item" onclick="kickUser(<?php echo $member['id']; ?>)">
                                                    <i class="bi bi-person-x"></i> Remove from Group
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item" onclick="makeAdmin(<?php echo $member['id']; ?>)">
                                                    <i class="bi bi-person-check"></i> Make Admin
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>
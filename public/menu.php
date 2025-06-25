<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="index.php">Chat Room</a>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php">Chat</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="contacts.php">Contacts</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#starredModal">
                        <i class="bi bi-star-fill"></i> Starred Messages
                    </a>
                </li>
                <?php if ($_SESSION['is_admin']): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/">Admin Page</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <div>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <span class="nav-link">Hi, <?php echo $_SESSION['username']; ?></span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="auth.php?action=logout">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
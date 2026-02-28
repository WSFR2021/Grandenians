<?php
$dbName = "grandenians_db";
$conn = new mysqli("localhost", "root", "");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if (!$conn->select_db($dbName)) {
    die("Database selection failed: " . $conn->error);
}

$conn->set_charset("utf8mb4");

$conn->query(
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(120) NOT NULL,
        username VARCHAR(60) NOT NULL UNIQUE,
        email VARCHAR(120) NOT NULL UNIQUE,
        email_verified TINYINT(1) NOT NULL DEFAULT 0,
        email_verified_at DATETIME NULL,
        profile_photo VARCHAR(255) NULL,
        password VARCHAR(255) NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        badge ENUM('none','bluebadge','gmc','grandenians') NOT NULL DEFAULT 'none',
        can_publish_news TINYINT(1) NOT NULL DEFAULT 0,
        last_active DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$userColumns = [
    "fullname" => "ALTER TABLE users ADD COLUMN fullname VARCHAR(120) NOT NULL DEFAULT '' AFTER id",
    "email" => "ALTER TABLE users ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER username",
    "email_verified" => "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email",
    "email_verified_at" => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email_verified",
    "profile_photo" => "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER email",
    "is_admin" => "ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password",
    "badge" => "ALTER TABLE users ADD COLUMN badge ENUM('none','bluebadge','gmc','grandenians') NOT NULL DEFAULT 'none' AFTER is_admin",
    "can_publish_news" => "ALTER TABLE users ADD COLUMN can_publish_news TINYINT(1) NOT NULL DEFAULT 0 AFTER badge",
    "last_active" => "ALTER TABLE users ADD COLUMN last_active DATETIME NULL AFTER can_publish_news"
];
foreach ($userColumns as $column => $query) {
    $hasColumn = $conn->query("SHOW COLUMNS FROM users LIKE '$column'");
    if ($hasColumn && $hasColumn->num_rows === 0) {
        $conn->query($query);
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS email_verifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        INDEX idx_email_verifications_user (user_id),
        INDEX idx_email_verifications_expires (expires_at),
        CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL UNIQUE,
        fullname VARCHAR(120) NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$defaultAdminUsername = "AdminJames";
$defaultAdminFullname = "James Estrera";
$defaultAdminPassword = "adminjames";
$adminSeedCheck = $conn->prepare("SELECT id FROM admins WHERE username=? LIMIT 1");
if ($adminSeedCheck) {
    $adminSeedCheck->bind_param("s", $defaultAdminUsername);
    $adminSeedCheck->execute();
    $adminExists = $adminSeedCheck->get_result()->num_rows > 0;
    $adminSeedCheck->close();
    if (!$adminExists) {
        $seedHash = password_hash($defaultAdminPassword, PASSWORD_DEFAULT);
        $adminSeedInsert = $conn->prepare("INSERT INTO admins (username, fullname, password) VALUES (?, ?, ?)");
        if ($adminSeedInsert) {
            $adminSeedInsert->bind_param("sss", $defaultAdminUsername, $defaultAdminFullname, $seedHash);
            $adminSeedInsert->execute();
            $adminSeedInsert->close();
        }
    }
}

$conn->query(
    "INSERT INTO admins (username, fullname, password)
     SELECT u.username, IFNULL(NULLIF(u.fullname,''), u.username), u.password
     FROM users u
     WHERE u.is_admin = 1
       AND NOT EXISTS (SELECT 1 FROM admins a WHERE a.username = u.username)"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        content TEXT NULL,
        hashtag VARCHAR(80) NULL,
        media_path VARCHAR(255) NULL,
        media_type ENUM('image', 'video', 'none') NOT NULL DEFAULT 'none',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_posts_created (created_at),
        INDEX idx_posts_user (user_id),
        CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$postColumns = [
    "user_id" => "ALTER TABLE posts ADD COLUMN user_id INT UNSIGNED NULL",
    "content" => "ALTER TABLE posts ADD COLUMN content TEXT NULL",
    "hashtag" => "ALTER TABLE posts ADD COLUMN hashtag VARCHAR(80) NULL",
    "media_path" => "ALTER TABLE posts ADD COLUMN media_path VARCHAR(255) NULL",
    "media_type" => "ALTER TABLE posts ADD COLUMN media_type ENUM('image','video','none') NOT NULL DEFAULT 'none'",
    "created_at" => "ALTER TABLE posts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
];
foreach ($postColumns as $column => $query) {
    $hasColumn = $conn->query("SHOW COLUMNS FROM posts LIKE '$column'");
    if ($hasColumn && $hasColumn->num_rows === 0) {
        $conn->query($query);
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS post_reactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        reaction ENUM('heart', 'wow', 'sad', 'angry') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reaction_post_user (post_id, user_id),
        INDEX idx_reactions_post (post_id),
        CONSTRAINT fk_reactions_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS post_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        comment_text VARCHAR(250) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_comments_post (post_id),
        CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS post_reposts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_repost_post_user (post_id, user_id),
        INDEX idx_reposts_post (post_id),
        CONSTRAINT fk_reposts_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_reposts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS saved_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        post_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_saved_user_post (user_id, post_id),
        INDEX idx_saved_user (user_id),
        INDEX idx_saved_post (post_id),
        CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_saved_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS follows (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        follower_user_id INT UNSIGNED NOT NULL,
        followed_user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_follow_pair (follower_user_id, followed_user_id),
        INDEX idx_followed_user (followed_user_id),
        CONSTRAINT fk_follows_follower FOREIGN KEY (follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_follows_followed FOREIGN KEY (followed_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS post_tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        tagged_by_user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_post_tag_user (post_id, user_id),
        INDEX idx_tags_user (user_id),
        INDEX idx_tags_post (post_id),
        CONSTRAINT fk_tags_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_tags_by_user FOREIGN KEY (tagged_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS name_change_alerts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        old_username VARCHAR(60) NOT NULL,
        new_username VARCHAR(60) NOT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_name_alerts_read (is_read),
        CONSTRAINT fk_name_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS news_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        image_path VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_news_created (created_at),
        CONSTRAINT fk_news_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$newsColumns = [
    "image_path" => "ALTER TABLE news_posts ADD COLUMN image_path VARCHAR(255) NULL AFTER body"
];
foreach ($newsColumns as $column => $query) {
    $hasColumn = $conn->query("SHOW COLUMNS FROM news_posts LIKE '$column'");
    if ($hasColumn && $hasColumn->num_rows === 0) {
        $conn->query($query);
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS news_reactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        news_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        reaction ENUM('heart', 'wow', 'sad', 'angry') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_news_reaction_user (news_id, user_id),
        INDEX idx_news_reactions_news (news_id),
        CONSTRAINT fk_news_reactions_news FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_news_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_news (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_news_created (created_at),
        CONSTRAINT fk_admin_news_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS trending_hashtags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        hashtag VARCHAR(80) NOT NULL UNIQUE,
        score INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS student_rankings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        score INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_ranking_user (user_id),
        CONSTRAINT fk_student_rank_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS message_conversations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NULL,
        is_group TINYINT(1) NOT NULL DEFAULT 0,
        is_request TINYINT(1) NOT NULL DEFAULT 0,
        request_for_user_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_msg_conv_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_conv_request_for FOREIGN KEY (request_for_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conversationColumns = [
    "is_request" => "ALTER TABLE message_conversations ADD COLUMN is_request TINYINT(1) NOT NULL DEFAULT 0 AFTER is_group",
    "request_for_user_id" => "ALTER TABLE message_conversations ADD COLUMN request_for_user_id INT UNSIGNED NULL AFTER is_request"
];
foreach ($conversationColumns as $column => $query) {
    $hasColumn = $conn->query("SHOW COLUMNS FROM message_conversations LIKE '$column'");
    if ($hasColumn && $hasColumn->num_rows === 0) {
        $conn->query($query);
    }
}
$hasReqFk = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='message_conversations' AND COLUMN_NAME='request_for_user_id' AND REFERENCED_TABLE_NAME='users'");
if ($hasReqFk && $hasReqFk->num_rows === 0) {
    $conn->query("ALTER TABLE message_conversations ADD CONSTRAINT fk_msg_conv_request_for FOREIGN KEY (request_for_user_id) REFERENCES users(id) ON DELETE SET NULL");
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS message_conversation_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_conv_member (conversation_id, user_id),
        INDEX idx_conv_member_user (user_id),
        CONSTRAINT fk_msg_members_conv FOREIGN KEY (conversation_id) REFERENCES message_conversations(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS message_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT UNSIGNED NOT NULL,
        sender_user_id INT UNSIGNED NOT NULL,
        message_text TEXT NULL,
        media_path VARCHAR(255) NULL,
        media_type ENUM('image','video','none') NOT NULL DEFAULT 'none',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_msg_items_conv_created (conversation_id, created_at),
        CONSTRAINT fk_msg_items_conv FOREIGN KEY (conversation_id) REFERENCES message_conversations(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_items_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS user_notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient_user_id INT UNSIGNED NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        type ENUM('follow','like') NOT NULL,
        post_id INT UNSIGNED NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notifications_recipient (recipient_user_id, is_read, created_at),
        CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_notifications_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
?>

<?php
session_start();
include "db.php";
if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) { header("Location: login.php"); exit(); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, "UTF-8"); }

$me = null;
if (isset($_SESSION['user_id'])) {
    $sessionUserId = (int)$_SESSION['user_id'];
    $meStmt = $conn->prepare("SELECT id, username FROM users WHERE id=? LIMIT 1");
    if ($meStmt) {
        $meStmt->bind_param("i", $sessionUserId);
        $meStmt->execute();
        $me = $meStmt->get_result()->fetch_assoc();
        $meStmt->close();
    }
}
if (!$me && isset($_SESSION['username'])) {
    $sessionUsername = (string)$_SESSION['username'];
    $meStmt = $conn->prepare("SELECT id, username FROM users WHERE username=? LIMIT 1");
    if ($meStmt) {
        $meStmt->bind_param("s", $sessionUsername);
        $meStmt->execute();
        $me = $meStmt->get_result()->fetch_assoc();
        $meStmt->close();
    }
}
if (!$me) { session_destroy(); header("Location: login.php"); exit(); }

$uid = (int)$me['id'];
$conn->query("UPDATE users SET last_active=NOW() WHERE id=$uid");

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read=1 WHERE recipient_user_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: notification.php");
    exit();
}

$notificationCount = (int)($conn->query("SELECT COUNT(*) c FROM user_notifications WHERE recipient_user_id=$uid AND is_read=0")->fetch_assoc()['c'] ?? 0);
$messageRequestCount = (int)($conn->query("SELECT COUNT(*) c FROM message_conversations WHERE is_request=1 AND request_for_user_id=$uid")->fetch_assoc()['c'] ?? 0);

$notifications = [];
$nq = $conn->query(
    "SELECT n.id, n.type, n.created_at, n.post_id, n.is_read, u.username actor
     FROM user_notifications n
     INNER JOIN users u ON u.id=n.actor_user_id
     WHERE n.recipient_user_id=$uid
     ORDER BY n.created_at DESC"
);
if ($nq) {
    while ($row = $nq->fetch_assoc()) $notifications[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashcss/style.css">
</head>
<body>
<div class="bg-glow bg-glow-left"></div><div class="bg-glow bg-glow-right"></div>
<div class="dashboard-shell">
<nav class="sidebar glass-card"><div class="brand"><span class="brand-mark"></span><h1>Grandenians</h1></div><ul class="nav-links"><li><a href="dashboard.php">Home</a></li><li><a href="videos.php">Videos</a></li><li><a href="news.php">News</a></li><li class="active"><a href="notification.php">Notification<?php if($notificationCount>0):?> (<?php echo $notificationCount>9?'9+':$notificationCount;?>)<?php endif;?></a></li><li><a href="message.php">Message<?php if($messageRequestCount>0):?> (<?php echo $messageRequestCount>9?'9+':$messageRequestCount;?>)<?php endif;?></a></li><li><a href="profile.php">Profile</a></li><li><a href="logout.php">Logout</a></li></ul></nav>
<main class="content-area">
<header class="topbar glass-card">
<h2>Notifications</h2>
<button type="button" class="theme-toggle-btn" data-theme-toggle>Dark mode</button>
</header>
<section class="glass-card search-results">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
<h3 style="margin:0;">All Notifications</h3>
<form method="POST">
<input type="hidden" name="action" value="mark_all_read">
<button type="submit">Mark all as read</button>
</form>
</div>
<?php if (count($notifications) === 0): ?>
<p>No notifications yet.</p>
<?php else: ?>
<ul>
<?php foreach ($notifications as $n): ?>
<li>
<?php if ($n['type'] === 'follow'): ?>
@<?php echo e($n['actor']); ?> followed you
<?php else: ?>
<a href="dashboard.php"><?php echo '@' . e($n['actor']) . ' liked your post'; ?></a>
<?php endif; ?>
<small> - <?php echo e(date("M j, Y g:i A", strtotime($n['created_at']))); ?><?php echo (int)$n['is_read'] === 0 ? " (new)" : ""; ?></small>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
</main>
<aside class="right-panel glass-card"><h3>Tips</h3><ul><li>Open this page to view all notifications.</li><li>Unread items show as <strong>(new)</strong>.</li><li>Use "Mark all as read" to clear badges.</li></ul></aside>
</div>
<script>
(function () {
    const KEY = "grandenians-theme";
    const btn = document.querySelector("[data-theme-toggle]");
    const root = document.documentElement;
    const saved = localStorage.getItem(KEY);
    const apply = (mode) => {
        root.setAttribute("data-theme", mode);
        if (btn) btn.textContent = mode === "dark" ? "Light mode" : "Dark mode";
    };
    apply(saved === "dark" ? "dark" : "light");
    if (btn) {
        btn.addEventListener("click", function () {
            const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
            localStorage.setItem(KEY, next);
            apply(next);
        });
    }
})();
</script>
</body>
</html>

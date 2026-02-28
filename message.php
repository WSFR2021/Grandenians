<?php
session_start();
include "db.php";
if (!isset($_SESSION['username'])) { header("Location: login.php"); exit(); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, "UTF-8"); }

$meStmt = $conn->prepare("SELECT id, username, profile_photo FROM users WHERE username=? LIMIT 1");
$meStmt->bind_param("s", $_SESSION['username']);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
$meStmt->close();
if (!$me) { session_destroy(); header("Location: login.php"); exit(); }
$uid = (int)$me['id'];
$conn->query("UPDATE users SET last_active=NOW() WHERE id=$uid");
$notificationCount = (int)($conn->query("SELECT COUNT(*) c FROM user_notifications WHERE recipient_user_id=$uid AND is_read=0")->fetch_assoc()['c'] ?? 0);
$messageRequestCount = (int)($conn->query("SELECT COUNT(*) c FROM message_conversations WHERE is_request=1 AND request_for_user_id=$uid")->fetch_assoc()['c'] ?? 0);

$selectedConversation = (int)($_GET['conversation'] ?? 0);
$conversationSearch = trim($_GET['q'] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? "";

    if ($action === "new_message") {
        $to = trim($_POST['to_username'] ?? "");
        if ($to !== "") {
            $find = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
            $find->bind_param("s", $to);
            $find->execute();
            $other = $find->get_result()->fetch_assoc();
            $find->close();

            if ($other && (int)$other['id'] !== $uid) {
                $oid = (int)$other['id'];
                $isRequest = 1;
                $fb = $conn->prepare("SELECT id FROM follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1");
                if ($fb) {
                    $fb->bind_param("ii", $oid, $uid);
                    $fb->execute();
                    if ($fb->get_result()->num_rows > 0) $isRequest = 0;
                    $fb->close();
                }
                $findConv = $conn->prepare(
                    "SELECT c.id
                     FROM message_conversations c
                     JOIN message_conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
                     JOIN message_conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
                     WHERE c.is_group = 0
                     LIMIT 1"
                );
                $findConv->bind_param("ii", $uid, $oid);
                $findConv->execute();
                $existing = $findConv->get_result()->fetch_assoc();
                $findConv->close();

                if ($existing) {
                    $selectedConversation = (int)$existing['id'];
                } else {
                    if ($isRequest === 1) {
                        $create = $conn->prepare("INSERT INTO message_conversations (name, is_group, is_request, request_for_user_id, created_by) VALUES (NULL, 0, 1, ?, ?)");
                        $create->bind_param("ii", $oid, $uid);
                    } else {
                        $create = $conn->prepare("INSERT INTO message_conversations (name, is_group, is_request, request_for_user_id, created_by) VALUES (NULL, 0, 0, NULL, ?)");
                        $create->bind_param("i", $uid);
                    }
                    $create->execute();
                    $cid = (int)$create->insert_id;
                    $create->close();

                    if ($cid > 0) {
                        $member = $conn->prepare("INSERT INTO message_conversation_members (conversation_id, user_id) VALUES (?, ?), (?, ?)");
                        $member->bind_param("iiii", $cid, $uid, $cid, $oid);
                        $member->execute();
                        $member->close();
                        $selectedConversation = $cid;
                    }
                }
            }
        }
        header("Location: message.php?conversation=" . (int)$selectedConversation);
        exit();
    }

    if ($action === "create_group") {
        $groupName = trim($_POST['group_name'] ?? "");
        $membersRaw = trim($_POST['members'] ?? "");
        if ($groupName !== "") {
            $create = $conn->prepare("INSERT INTO message_conversations (name, is_group, created_by) VALUES (?, 1, ?)");
            $create->bind_param("si", $groupName, $uid);
            $create->execute();
            $cid = (int)$create->insert_id;
            $create->close();

            if ($cid > 0) {
                $added = [$uid => true];
                $member = $conn->prepare("INSERT IGNORE INTO message_conversation_members (conversation_id, user_id) VALUES (?, ?)");
                $member->bind_param("ii", $cid, $uid);
                $member->execute();

                $names = array_filter(array_map('trim', explode(',', $membersRaw)));
                foreach ($names as $name) {
                    $find = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
                    $find->bind_param("s", $name);
                    $find->execute();
                    $row = $find->get_result()->fetch_assoc();
                    $find->close();
                    if ($row) {
                        $mid = (int)$row['id'];
                        if (!isset($added[$mid])) {
                            $member->bind_param("ii", $cid, $mid);
                            $member->execute();
                            $added[$mid] = true;
                        }
                    }
                }
                $member->close();
                $selectedConversation = $cid;
            }
        }
        header("Location: message.php?conversation=" . (int)$selectedConversation);
        exit();
    }

    if ($action === "send_message") {
        $cid = (int)($_POST['conversation_id'] ?? 0);
        $text = trim($_POST['message_text'] ?? "");
        $mediaPath = null;
        $mediaType = "none";

        $check = $conn->prepare("SELECT 1 FROM message_conversation_members WHERE conversation_id=? AND user_id=? LIMIT 1");
        $check->bind_param("ii", $cid, $uid);
        $check->execute();
        $allowed = $check->get_result()->num_rows > 0;
        $check->close();

        if ($allowed && isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['media']['tmp_name'];
            $size = (int)$_FILES['media']['size'];
            if ($size <= 20 * 1024 * 1024) {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($f, $tmp);
                finfo_close($f);
                $allow = [
                    "image/jpeg" => "image",
                    "image/png" => "image",
                    "image/gif" => "image",
                    "image/webp" => "image",
                    "video/mp4" => "video",
                    "video/webm" => "video",
                    "video/quicktime" => "video"
                ];
                if (isset($allow[$mime])) {
                    $dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "messages";
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
                    if ($ext === "") $ext = $allow[$mime] === "image" ? "jpg" : "mp4";
                    $name = uniqid("msg_", true) . "." . $ext;
                    $full = $dir . DIRECTORY_SEPARATOR . $name;
                    if (move_uploaded_file($tmp, $full)) {
                        $mediaPath = "uploads/messages/" . $name;
                        $mediaType = $allow[$mime];
                    }
                }
            }
        }

        if ($allowed && ($text !== "" || $mediaPath !== null)) {
            $stmt = $conn->prepare("INSERT INTO message_items (conversation_id, sender_user_id, message_text, media_path, media_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $cid, $uid, $text, $mediaPath, $mediaType);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: message.php?conversation=" . $cid);
        exit();
    }
}

$conversations = [];
$cq = $conn->prepare(
    "SELECT c.id, c.name, c.is_group, c.is_request, c.request_for_user_id, c.created_at,
            (SELECT mi.message_text FROM message_items mi WHERE mi.conversation_id=c.id ORDER BY mi.created_at DESC LIMIT 1) AS last_message,
            (SELECT mi.media_type FROM message_items mi WHERE mi.conversation_id=c.id ORDER BY mi.created_at DESC LIMIT 1) AS last_media_type,
            (SELECT mi.created_at FROM message_items mi WHERE mi.conversation_id=c.id ORDER BY mi.created_at DESC LIMIT 1) AS last_message_at
     FROM message_conversations c
     INNER JOIN message_conversation_members m ON m.conversation_id=c.id
     WHERE m.user_id=?
     ORDER BY COALESCE((SELECT mi.created_at FROM message_items mi WHERE mi.conversation_id=c.id ORDER BY mi.created_at DESC LIMIT 1), c.created_at) DESC"
);
$cq->bind_param("i", $uid);
$cq->execute();
$rs = $cq->get_result();
while ($row = $rs->fetch_assoc()) $conversations[] = $row;
$cq->close();

$titleByConversation = [];
if (count($conversations) > 0) {
    $allConvIds = implode(",", array_map(static function($c) { return (int)$c['id']; }, $conversations));
    $titlesQ = $conn->query(
        "SELECT m.conversation_id, GROUP_CONCAT(u.username ORDER BY u.username SEPARATOR ', ') AS names
         FROM message_conversation_members m
         INNER JOIN users u ON u.id=m.user_id
         WHERE m.conversation_id IN ($allConvIds) AND u.id<>$uid
         GROUP BY m.conversation_id"
    );
    if ($titlesQ) {
        while ($row = $titlesQ->fetch_assoc()) $titleByConversation[(int)$row['conversation_id']] = (string)$row['names'];
    }
}

$inboxConversations = [];
$requestConversations = [];
foreach ($conversations as $conv) {
    $cid = (int)$conv['id'];
    $defaultTitle = (int)$conv['is_group'] === 1 ? ((string)$conv['name'] !== "" ? (string)$conv['name'] : "Group chat") : ((string)($titleByConversation[$cid] ?? "") !== "" ? (string)$titleByConversation[$cid] : ("Chat #" . $cid));
    $last = trim((string)($conv['last_message'] ?? ""));
    if ($last === "" && (string)($conv['last_media_type'] ?? "none") !== "none") $last = "Attachment";
    if ($last === "") $last = "No messages yet";
    $conv['display_title'] = $defaultTitle;
    $conv['display_last'] = $last;

    if ($conversationSearch !== "") {
        $needle = strtolower($conversationSearch);
        if (strpos(strtolower($defaultTitle), $needle) === false && strpos(strtolower($last), $needle) === false) {
            continue;
        }
    }

    $isReq = (int)($conv['is_request'] ?? 0) === 1 && (int)($conv['request_for_user_id'] ?? 0) === $uid;
    if ($isReq) $requestConversations[] = $conv;
    else $inboxConversations[] = $conv;
}

$followerUsers = [];
$fu = $conn->query("SELECT u.id,u.username,u.last_active FROM follows f INNER JOIN users u ON u.id=f.follower_user_id WHERE f.followed_user_id=$uid ORDER BY u.username ASC");
if ($fu) { while ($row = $fu->fetch_assoc()) $followerUsers[] = $row; }

if ($selectedConversation === 0 && count($inboxConversations) > 0) {
    $selectedConversation = (int)$inboxConversations[0]['id'];
} elseif ($selectedConversation === 0 && count($requestConversations) > 0) {
    $selectedConversation = (int)$requestConversations[0]['id'];
}

$members = [];
$messages = [];
$selectedTitle = "";
if ($selectedConversation > 0) {
    $mq = $conn->prepare(
        "SELECT u.id, u.username, u.fullname, u.last_active
         FROM message_conversation_members m
         INNER JOIN users u ON u.id=m.user_id
         WHERE m.conversation_id=?
         ORDER BY u.username ASC"
    );
    $mq->bind_param("i", $selectedConversation);
    $mq->execute();
    $mrs = $mq->get_result();
    while ($row = $mrs->fetch_assoc()) $members[] = $row;
    $mq->close();

    $isGroup = 0;
    foreach ($conversations as $conv) {
        if ((int)$conv['id'] === $selectedConversation) {
            $isGroup = (int)$conv['is_group'];
            break;
        }
    }
    if ($isGroup === 1) {
        $selectedTitle = "";
        foreach ($conversations as $conv) {
            if ((int)$conv['id'] === $selectedConversation) {
                $selectedTitle = $conv['display_title'] ?? "";
                break;
            }
        }
    } else {
        $others = [];
        foreach ($members as $m) {
            if ((int)$m['id'] !== $uid) $others[] = $m['username'];
        }
        $selectedTitle = count($others) > 0 ? implode(", ", $others) : "Direct message";
    }

    $msgq = $conn->prepare(
        "SELECT mi.id, mi.sender_user_id, mi.message_text, mi.media_path, mi.media_type, mi.created_at, u.username
         FROM message_items mi
         INNER JOIN users u ON u.id=mi.sender_user_id
         WHERE mi.conversation_id=?
         ORDER BY mi.created_at ASC"
    );
    $msgq->bind_param("i", $selectedConversation);
    $msgq->execute();
    $mres = $msgq->get_result();
    while ($row = $mres->fetch_assoc()) $messages[] = $row;
    $msgq->close();
}

$notifications = [];
$nq = $conn->query("SELECT n.type,n.created_at,n.post_id,u.username actor FROM user_notifications n INNER JOIN users u ON u.id=n.actor_user_id WHERE n.recipient_user_id=$uid ORDER BY n.created_at DESC LIMIT 8");
if ($nq) { while ($row = $nq->fetch_assoc()) $notifications[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashcss/style.css">
</head>
<body>
<div class="bg-glow bg-glow-left"></div><div class="bg-glow bg-glow-right"></div>
<div class="dashboard-shell">
<nav class="sidebar glass-card"><div class="brand"><span class="brand-mark"></span><h1>Grandenians</h1></div><ul class="nav-links"><li><a href="dashboard.php">Home</a></li><li><a href="videos.php">Videos</a></li><li><a href="news.php">News</a></li><li><a href="notification.php">Notification<?php if($notificationCount>0):?> (<?php echo $notificationCount>9?'9+':$notificationCount;?>)<?php endif;?></a></li><li class="active"><a href="message.php">Message<?php if($messageRequestCount>0):?> (<?php echo $messageRequestCount>9?'9+':$messageRequestCount;?>)<?php endif;?></a></li><li><a href="profile.php">Profile</a></li><li><a href="logout.php">Logout</a></li></ul></nav>
<main class="content-area">
<header class="topbar glass-card">
<h2>Messages</h2>
<button type="button" class="theme-toggle-btn" data-theme-toggle>Dark mode</button>
</header>

<section class="glass-card message-layout message-layout-modern">
<aside class="message-sidebar message-sidebar-modern">
<div class="message-account-line">
<strong>@<?php echo e($me['username']); ?></strong>
<details class="message-plus-menu">
<summary>+</summary>
<div class="message-plus-body">
<form method="POST" class="message-form">
<input type="hidden" name="action" value="new_message">
<input type="text" name="to_username" placeholder="Username" required>
<button type="submit">Start chat</button>
</form>
<form method="POST" class="message-form">
<input type="hidden" name="action" value="create_group">
<input type="text" name="group_name" placeholder="Group name" required>
<input type="text" name="members" placeholder="user1,user2,user3">
<button type="submit">Create group</button>
</form>
</div>
</details>
</div>

<form method="GET" class="message-search-form">
<?php if ($selectedConversation > 0): ?><input type="hidden" name="conversation" value="<?php echo (int)$selectedConversation; ?>"><?php endif; ?>
<input type="search" name="q" value="<?php echo e($conversationSearch); ?>" placeholder="Search conversation">
</form>

<h3>Inbox</h3>
<div class="message-conversations modern-list">
<?php foreach ($inboxConversations as $conv): $cid = (int)$conv['id']; $title = (string)$conv['display_title']; $last = (string)$conv['display_last']; ?>
<a class="message-conversation-item modern-item <?php echo $cid === $selectedConversation ? 'active' : ''; ?>" href="message.php?conversation=<?php echo $cid; ?>">
<span class="message-avatar-badge"><?php echo e(strtoupper(substr($title, 0, 1))); ?></span>
<span class="message-meta">
<strong><?php echo e($title); ?></strong>
<small><?php echo e($last); ?></small>
</span>
</a>
<?php endforeach; ?>
</div>

<h3>Requests</h3>
<div class="message-conversations modern-list">
<?php foreach ($requestConversations as $conv): $cid = (int)$conv['id']; $title = (string)$conv['display_title']; $last = (string)$conv['display_last']; ?>
<a class="message-conversation-item modern-item <?php echo $cid === $selectedConversation ? 'active' : ''; ?>" href="message.php?conversation=<?php echo $cid; ?>">
<span class="message-avatar-badge"><?php echo e(strtoupper(substr($title, 0, 1))); ?></span>
<span class="message-meta">
<strong><?php echo e($title); ?></strong>
<small><?php echo e($last); ?></small>
</span>
</a>
<?php endforeach; ?>
</div>

<details class="message-followers-card">
<summary>Followers (start chat)</summary>
<div class="message-conversations">
<?php foreach ($followerUsers as $fu): $online = !empty($fu['last_active']) && strtotime($fu['last_active']) >= (time() - 300); ?>
<form method="POST" class="inline-message-user"><input type="hidden" name="action" value="new_message"><input type="hidden" name="to_username" value="<?php echo e($fu['username']); ?>"><button type="submit">@<?php echo e($fu['username']); ?> <?php echo $online?'<b class="online-dot">online</b>':'<b class="offline-dot">offline</b>'; ?></button></form>
<?php endforeach; ?>
</div>
</details>

</aside>

<div class="message-main message-main-modern">
<?php if ($selectedConversation <= 0): ?>
<div class="message-empty-state">
<h3>Your messages</h3>
<p>Select or start a conversation.</p>
</div>
<?php else: ?>
<div class="message-chat-header">
<div>
<h3><?php echo e($selectedTitle !== "" ? $selectedTitle : "Conversation"); ?></h3>
<small>
<?php
$onlineCount = 0;
foreach ($members as $member) {
    if (!empty($member['last_active']) && strtotime($member['last_active']) >= (time() - 300)) $onlineCount++;
}
echo e(count($members) . " member(s), " . $onlineCount . " online");
?>
</small>
</div>
<div class="message-chat-actions"><button type="button" disabled>Call</button><button type="button" disabled>Info</button></div>
</div>

<div class="message-thread modern-thread" id="message-thread">
<div class="message-intro-card">
<span class="message-avatar-large"><?php echo e(strtoupper(substr($selectedTitle !== "" ? $selectedTitle : "C", 0, 1))); ?></span>
<h4><?php echo e($selectedTitle !== "" ? $selectedTitle : "Conversation"); ?></h4>
<p>Start chatting now.</p>
</div>
<?php
$lastDate = "";
foreach ($messages as $msg):
    $currentDate = date("Y-m-d", strtotime($msg['created_at']));
    if ($currentDate !== $lastDate):
?>
<div class="message-date-separator"><?php echo e(date("M j, Y", strtotime($msg['created_at']))); ?></div>
<?php
        $lastDate = $currentDate;
    endif;
?>
<div class="message-item modern-bubble <?php echo (int)$msg['sender_user_id'] === $uid ? 'mine' : ''; ?>">
<?php if ((int)$msg['sender_user_id'] !== $uid): ?><strong>@<?php echo e($msg['username']); ?></strong><?php endif; ?>
<?php if ((string)$msg['message_text'] !== ""): ?><p><?php echo nl2br(e((string)$msg['message_text'])); ?></p><?php endif; ?>
<?php if (!empty($msg['media_path'])): ?>
<?php if ($msg['media_type'] === 'video'): ?>
<video controls preload="metadata"><source src="<?php echo e($msg['media_path']); ?>"></video>
<?php else: ?>
<img src="<?php echo e($msg['media_path']); ?>" alt="Message media">
<?php endif; ?>
<?php endif; ?>
<small><?php echo e(date("g:i A", strtotime($msg['created_at']))); ?></small>
</div>
<?php endforeach; ?>
</div>

<form method="POST" enctype="multipart/form-data" class="message-form compose-form modern-compose-form">
<input type="hidden" name="action" value="send_message">
<input type="hidden" name="conversation_id" value="<?php echo (int)$selectedConversation; ?>">
<textarea name="message_text" rows="1" placeholder="Message..."></textarea>
<input type="file" name="media" accept="image/*,video/*">
<button type="submit">Send</button>
</form>
<?php endif; ?>
</div>
</section>
</main>
<aside class="right-panel glass-card"><h3>Messaging Tips</h3><ul><li>Use + to start chats or groups.</li><li>Message requests appear when users do not follow you.</li><li>You can send text, photo, and video.</li></ul></aside>
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
    const thread = document.getElementById("message-thread");
    if (thread) thread.scrollTop = thread.scrollHeight;
})();
</script>
</body>
</html>

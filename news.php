<?php
session_start();
include "db.php";
if (!isset($_SESSION['username'])) { header("Location: login.php"); exit(); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, "UTF-8"); }

$reacts = ["heart", "wow", "sad", "angry"];

$meStmt = $conn->prepare("SELECT id,username,can_publish_news,profile_photo FROM users WHERE username=? LIMIT 1");
$meStmt->bind_param("s", $_SESSION['username']);
$meStmt->execute();
$me = $meStmt->get_result()->fetch_assoc();
$meStmt->close();
if (!$me) { session_destroy(); header("Location: login.php"); exit(); }
$uid = (int)$me['id'];
$conn->query("UPDATE users SET last_active=NOW() WHERE id=$uid");
$notificationCount = (int)($conn->query("SELECT COUNT(*) c FROM user_notifications WHERE recipient_user_id=$uid AND is_read=0")->fetch_assoc()['c'] ?? 0);
$messageRequestCount = (int)($conn->query("SELECT COUNT(*) c FROM message_conversations WHERE is_request=1 AND request_for_user_id=$uid")->fetch_assoc()['c'] ?? 0);
$notifications = [];
$nq = $conn->query("SELECT n.type,n.created_at,n.post_id,u.username actor FROM user_notifications n INNER JOIN users u ON u.id=n.actor_user_id WHERE n.recipient_user_id=$uid ORDER BY n.created_at DESC LIMIT 6");
if ($nq) { while ($row = $nq->fetch_assoc()) $notifications[] = $row; }

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? "";

    if ($action === "react_news") {
        $newsId = (int)($_POST['news_id'] ?? 0);
        $reaction = $_POST['reaction'] ?? "";
        if ($newsId > 0 && in_array($reaction, $reacts, true)) {
            $check = $conn->prepare("SELECT id FROM news_posts WHERE id=? LIMIT 1");
            if ($check) {
                $check->bind_param("i", $newsId);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();
                if ($exists) {
                    $upsert = $conn->prepare(
                        "INSERT INTO news_reactions (news_id, user_id, reaction)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE reaction=VALUES(reaction), created_at=CURRENT_TIMESTAMP"
                    );
                    if ($upsert) {
                        $upsert->bind_param("iis", $newsId, $uid, $reaction);
                        $upsert->execute();
                        $upsert->close();
                    }
                }
            }
        }
        header("Location: news.php#news-" . $newsId);
        exit();
    }
}

$newsError = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($me['can_publish_news'] ?? 0) == 1 && ($_POST['action'] ?? "") === "publish_news") {
    $title = trim($_POST['news_title'] ?? "");
    $body = trim($_POST['news_body'] ?? "");
    $newNewsId = 0;
    $imagePath = null;
    if (isset($_FILES['news_image']) && $_FILES['news_image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['news_image']['tmp_name'];
        $size = (int)$_FILES['news_image']['size'];
        if ($size <= 10 * 1024 * 1024) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($f, $tmp);
            finfo_close($f);
            $allow = ["image/jpeg", "image/png", "image/webp", "image/gif"];
            if (in_array($mime, $allow, true)) {
                $dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "news";
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $ext = strtolower(pathinfo($_FILES['news_image']['name'], PATHINFO_EXTENSION));
                if ($ext === "") $ext = "jpg";
                $name = uniqid("news_", true) . "." . $ext;
                if (move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) $imagePath = "uploads/news/" . $name;
            }
        }
    }
    if ($title !== "" && $body !== "") {
        $ins = $conn->prepare("INSERT INTO news_posts (user_id,title,body,image_path) VALUES (?,?,?,?)");
        if ($ins) {
            $ins->bind_param("isss", $uid, $title, $body, $imagePath);
            $ins->execute();
            $newNewsId = (int)$ins->insert_id;
            $ins->close();
        }
        header("Location: news.php" . (!empty($newNewsId) ? "#news-" . $newNewsId : ""));
        exit();
    } else {
        $newsError = "Title and body are required.";
    }
}

$news = [];
$q = $conn->query(
    "SELECT np.id, np.title, np.body, np.image_path, np.created_at,
            u.username AS author_username, u.fullname AS author_fullname, u.can_publish_news AS monetized
     FROM news_posts np
     INNER JOIN users u ON u.id=np.user_id
     ORDER BY np.created_at DESC"
);
if ($q) { while ($row = $q->fetch_assoc()) $news[] = $row; }

$newsReactionCounts = [];
$myNewsReactions = [];
if (count($news) > 0) {
    $ids = array_map(static function($n) { return (int)$n['id']; }, $news);
    $idSql = implode(",", $ids);

    $rq = $conn->query("SELECT news_id, reaction, COUNT(*) total FROM news_reactions WHERE news_id IN ($idSql) GROUP BY news_id, reaction");
    if ($rq) {
        while ($row = $rq->fetch_assoc()) {
            $nid = (int)$row['news_id'];
            if (!isset($newsReactionCounts[$nid])) $newsReactionCounts[$nid] = ["heart" => 0, "wow" => 0, "sad" => 0, "angry" => 0];
            $newsReactionCounts[$nid][$row['reaction']] = (int)$row['total'];
        }
    }

    $mr = $conn->prepare("SELECT news_id, reaction FROM news_reactions WHERE user_id=? AND news_id IN ($idSql)");
    if ($mr) {
        $mr->bind_param("i", $uid);
        $mr->execute();
        $rr = $mr->get_result();
        while ($rr && ($row = $rr->fetch_assoc())) $myNewsReactions[(int)$row['news_id']] = $row['reaction'];
        $mr->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>News</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashcss/style.css">
</head>
<body>
<div class="bg-glow bg-glow-left"></div><div class="bg-glow bg-glow-right"></div>
<div class="dashboard-shell">
<nav class="sidebar glass-card"><div class="brand"><span class="brand-mark"></span><h1>Grandenians</h1></div><ul class="nav-links"><li><a href="dashboard.php">Home</a></li><li><a href="videos.php">Videos</a></li><li class="active"><a href="news.php">News</a></li><li><a href="notification.php">Notification<?php if($notificationCount>0):?> (<?php echo $notificationCount>9?'9+':$notificationCount;?>)<?php endif;?></a></li><li><a href="message.php">Message<?php if($messageRequestCount>0):?> (<?php echo $messageRequestCount>9?'9+':$messageRequestCount;?>)<?php endif;?></a></li><li><a href="profile.php">Profile</a></li><li><a href="logout.php">Logout</a></li></ul></nav>
<main class="content-area">
<header class="topbar glass-card">
<h2>News</h2>
<div class="topbar-actions">
<p>Published articles by monetized users</p>
<button type="button" class="theme-toggle-btn" data-theme-toggle>Dark mode</button>
</div>
</header>
<?php if((int)$me['can_publish_news']===1):?>
<section class="post-composer glass-card">
<form method="POST" enctype="multipart/form-data" class="composer-form">
<input type="hidden" name="action" value="publish_news">
<h3>Publish News Article</h3>
<?php if($newsError!==""):?><p class="alert-line error"><?php echo e($newsError);?></p><?php endif;?>
<input type="text" name="news_title" placeholder="News title" required>
<textarea name="news_body" rows="4" placeholder="Write article..." required></textarea>
<input type="file" name="news_image" accept="image/*">
<button type="submit">Publish</button>
</form>
</section>
<?php endif;?>
<section class="feed">
<?php if (count($news) === 0): ?>
<article class="post glass-card empty-feed"><p>No news published yet.</p></article>
<?php endif; ?>
<?php foreach ($news as $item): $nid = (int)$item['id']; $counts = $newsReactionCounts[$nid] ?? ["heart" => 0, "wow" => 0, "sad" => 0, "angry" => 0]; $my = $myNewsReactions[$nid] ?? ""; ?>
<article id="news-<?php echo $nid; ?>" class="post glass-card news-article">
<header>
<div>
<h3><?php echo e($item['title']); ?></h3>
<span>by <?php echo e($item['author_fullname']); ?> (@<?php echo e($item['author_username']); ?>) <?php if ((int)$item['monetized'] === 1): ?><b class="monetized-plus">+</b><?php endif; ?></span>
</div>
<span class="post-time"><?php echo e(date("M j, Y g:i A", strtotime($item['created_at']))); ?></span>
</header>
<?php if(!empty($item['image_path'])):?><div class="post-media"><img src="<?php echo e($item['image_path']);?>" alt="News image"></div><?php endif;?>
<p><?php echo nl2br(e($item['body'])); ?></p>
<div class="post-stats"><span><?php echo (int)array_sum($counts); ?> reactions</span></div>
<form method="POST" class="reaction-row news-reaction-row">
<input type="hidden" name="action" value="react_news">
<input type="hidden" name="news_id" value="<?php echo $nid; ?>">
<button class="<?php echo $my==='heart'?'active':'';?>" type="submit" name="reaction" value="heart">Heart (<?php echo (int)$counts['heart'];?>)</button>
<button class="<?php echo $my==='wow'?'active':'';?>" type="submit" name="reaction" value="wow">Wow (<?php echo (int)$counts['wow'];?>)</button>
<button class="<?php echo $my==='sad'?'active':'';?>" type="submit" name="reaction" value="sad">Sad (<?php echo (int)$counts['sad'];?>)</button>
<button class="<?php echo $my==='angry'?'active':'';?>" type="submit" name="reaction" value="angry">Angry (<?php echo (int)$counts['angry'];?>)</button>
</form>
</article>
<?php endforeach; ?>
</section>
</main>
<aside class="right-panel glass-card"><h3>Monetize in News</h3><ul><li>Only users allowed by admin can publish news.</li><li>Allowed users display a <b>+</b> icon next to author.</li><li>All users can react to news articles.</li></ul></aside>
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

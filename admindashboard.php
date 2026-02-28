<?php
session_start();
include "db.php";
if (!isset($_SESSION['admin_id'])) { header("Location: adminlogin.php"); exit(); }

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? "";

    if ($action === "update_user_program") {
        $userId = (int)($_POST['user_id'] ?? 0);
        $badge = $_POST['badge'] ?? "none";
        $canNews = isset($_POST['can_publish_news']) ? 1 : 0;
        $grantAdminAccess = isset($_POST['grant_admin_access']) ? 1 : 0;

        if (!in_array($badge, ["none", "bluebadge", "gmc", "grandenians"], true)) $badge = "none";

        if ($userId > 0) {
            $stmt = $conn->prepare("UPDATE users SET badge=?, can_publish_news=?, is_admin=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("siii", $badge, $canNews, $grantAdminAccess, $userId);
                $stmt->execute();
                $stmt->close();
            }

            $usr = $conn->prepare("SELECT username,fullname,password FROM users WHERE id=? LIMIT 1");
            if ($usr) {
                $usr->bind_param("i", $userId);
                $usr->execute();
                $row = $usr->get_result()->fetch_assoc();
                $usr->close();
                if ($row) {
                    if ($grantAdminAccess === 1) {
                        $ins = $conn->prepare("INSERT INTO admins (username,fullname,password) VALUES (?,?,?) ON DUPLICATE KEY UPDATE fullname=VALUES(fullname), password=VALUES(password)");
                        if ($ins) {
                            $ins->bind_param("sss", $row['username'], $row['fullname'], $row['password']);
                            $ins->execute();
                            $ins->close();
                        }
                    } else {
                        $del = $conn->prepare("DELETE FROM admins WHERE username=?");
                        if ($del) {
                            $del->bind_param("s", $row['username']);
                            $del->execute();
                            $del->close();
                        }
                    }
                }
            }
        }
    }

    if ($action === "mark_alert_read") {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if ($alertId > 0) {
            $stmt = $conn->prepare("UPDATE name_change_alerts SET is_read=1 WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $alertId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    header("Location: admindashboard.php");
    exit();
}

$totalUsers = (int)($conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'] ?? 0);
$activeUsers = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE last_active >= (NOW() - INTERVAL 5 MINUTE)")->fetch_assoc()['c'] ?? 0);

$alerts = [];
$a = $conn->query("SELECT n.id,n.old_username,n.new_username,n.changed_at,n.is_read FROM name_change_alerts n ORDER BY n.changed_at DESC LIMIT 20");
if ($a) { while ($r = $a->fetch_assoc()) $alerts[] = $r; }

$users = [];
$u = $conn->query("SELECT id,username,fullname,badge,can_publish_news,last_active,is_admin FROM users ORDER BY created_at DESC");
if ($u) { while ($r = $u->fetch_assoc()) $users[] = $r; }

$hashtags = [];
$hq = $conn->query("SELECT u.username, COUNT(*) hashtag_posts FROM posts p INNER JOIN users u ON u.id=p.user_id WHERE p.hashtag IS NOT NULL AND p.hashtag<>'' GROUP BY p.user_id ORDER BY hashtag_posts DESC, u.username ASC LIMIT 20");
if ($hq) { while ($r = $hq->fetch_assoc()) $hashtags[] = $r; }

$popularity = [];
$pq = $conn->query(
    "SELECT
        u.username,
        u.fullname,
        (SELECT COUNT(*) FROM follows f WHERE f.followed_user_id=u.id) AS followers,
        ((SELECT COUNT(*) FROM post_reactions pr INNER JOIN posts p ON p.id=pr.post_id WHERE p.user_id=u.id)
         +
         (SELECT COUNT(*) FROM post_comments pc INNER JOIN posts p2 ON p2.id=pc.post_id WHERE p2.user_id=u.id)) AS supporters
     FROM users u
     ORDER BY ((followers*10) + (supporters*5)) DESC, u.username ASC
     LIMIT 10"
);
if ($pq) { while ($r = $pq->fetch_assoc()) $popularity[] = $r; }
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Dashboard</title><link rel="stylesheet" href="dashcss/adminstyle.css"></head>
<body>
<header class="admin-top">
    <h1>Admin Dashboard</h1>
    <a href="logout.php">Logout</a>
</header>
<main class="admin-shell">
    <section class="stats">
        <article><h2><?php echo $totalUsers; ?></h2><p>Registered Users</p></article>
        <article><h2><?php echo $activeUsers; ?></h2><p>Realtime Active (last 5 min)</p></article>
    </section>

    <section class="panel">
        <h3>Name Change Alerts</h3>
        <?php if (count($alerts) === 0): ?><p>No alerts yet.</p><?php endif; ?>
        <?php foreach ($alerts as $al): ?>
            <div class="alert-row <?php echo (int)$al['is_read'] === 1 ? 'read' : ''; ?>">
                <p>@<?php echo htmlspecialchars($al['old_username']); ?> changed to @<?php echo htmlspecialchars($al['new_username']); ?> (<?php echo htmlspecialchars($al['changed_at']); ?>)</p>
                <?php if ((int)$al['is_read'] === 0): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_alert_read">
                        <input type="hidden" name="alert_id" value="<?php echo (int)$al['id']; ?>">
                        <button type="submit">Mark Read</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h3>User Program + Admin Access</h3>
        <table>
            <thead><tr><th>Username</th><th>Fullname</th><th>Badge</th><th>Publish News</th><th>Admin Login</th><th>Last Active</th><th>Save</th></tr></thead>
            <tbody>
            <?php foreach ($users as $usr): ?>
                <tr>
                    <td>@<?php echo htmlspecialchars($usr['username']); ?> <?php if ((int)$usr['is_admin'] === 1): ?><span class="pill">Admin</span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($usr['fullname']); ?></td>
                    <td colspan="5">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="update_user_program">
                            <input type="hidden" name="user_id" value="<?php echo (int)$usr['id']; ?>">
                            <select name="badge">
                                <option value="none" <?php echo $usr['badge']==='none'?'selected':''; ?>>None</option>
                                <option value="bluebadge" <?php echo $usr['badge']==='bluebadge'?'selected':''; ?>>Verified</option>
                                <option value="gmc" <?php echo $usr['badge']==='gmc'?'selected':''; ?>>GMC Badge</option>
                                <option value="grandenians" <?php echo $usr['badge']==='grandenians'?'selected':''; ?>>Grandenians Badge</option>
                            </select>
                            <label><input type="checkbox" name="can_publish_news" value="1" <?php echo (int)$usr['can_publish_news']===1?'checked':''; ?>> Allowed</label>
                            <label><input type="checkbox" name="grant_admin_access" value="1" <?php echo (int)$usr['is_admin']===1?'checked':''; ?>> Admin Access</label>
                            <span class="last-active"><?php echo htmlspecialchars((string)$usr['last_active']); ?></span>
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h3>Top Hashtag Users</h3>
        <table>
            <thead><tr><th>User</th><th>Hashtag Posts</th></tr></thead>
            <tbody>
            <?php if (count($hashtags) === 0): ?>
                <tr><td colspan="2">No hashtags yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($hashtags as $tag): ?>
                <tr>
                    <td>@<?php echo htmlspecialchars($tag['username']); ?></td>
                    <td><?php echo (int)$tag['hashtag_posts']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h3>Student Popularity Ranking (Rank #1 to #10)</h3>
        <table>
            <thead><tr><th>Rank</th><th>User</th><th>Followers</th><th>Supporters</th><th>Points</th></tr></thead>
            <tbody>
            <?php if (count($popularity) === 0): ?>
                <tr><td colspan="5">No users yet.</td></tr>
            <?php endif; ?>
            <?php $rank = 1; foreach ($popularity as $row): ?>
                <tr>
                    <td>#<?php echo $rank; ?></td>
                    <td>@<?php echo htmlspecialchars($row['username']); ?> (<?php echo htmlspecialchars($row['fullname']); ?>)</td>
                    <td><?php echo (int)$row['followers']; ?></td>
                    <td><?php echo (int)$row['supporters']; ?></td>
                    <td><?php echo ((int)$row['followers'] * 10) + ((int)$row['supporters'] * 5); ?></td>
                </tr>
            <?php $rank++; endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body></html>

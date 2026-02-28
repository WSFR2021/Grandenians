<?php
session_start();
include "db.php";
if (!isset($_SESSION['username'])) { header("Location: login.php"); exit(); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, "UTF-8"); }
function badge_html(string $badge): string {
    if ($badge === "bluebadge") return '<span class="badge badge-check">&#9989;</span>';
    if ($badge === "gmc") return '<span class="badge badge-gmc"><span class="g">G</span><span class="m">M</span><span class="c">C</span></span>';
    if ($badge === "grandenians") return '<span class="badge badge-grand"><span class="g">G</span>randenians</span>';
    return "";
}

$viewerStmt = $conn->prepare("SELECT id,username,profile_photo FROM users WHERE username=? LIMIT 1");
$viewerStmt->bind_param("s", $_SESSION['username']);
$viewerStmt->execute();
$viewer = $viewerStmt->get_result()->fetch_assoc();
$viewerStmt->close();
if (!$viewer) { session_destroy(); header("Location: login.php"); exit(); }
$viewerId = (int)$viewer['id'];
$conn->query("UPDATE users SET last_active=NOW() WHERE id=$viewerId");
$notificationCount = (int)($conn->query("SELECT COUNT(*) c FROM user_notifications WHERE recipient_user_id=$viewerId AND is_read=0")->fetch_assoc()['c'] ?? 0);
$messageRequestCount = (int)($conn->query("SELECT COUNT(*) c FROM message_conversations WHERE is_request=1 AND request_for_user_id=$viewerId")->fetch_assoc()['c'] ?? 0);

$profileUsername = trim($_GET['user'] ?? $viewer['username']);
$profileStmt = $conn->prepare("SELECT id,username,fullname,profile_photo,badge FROM users WHERE username=? LIMIT 1");
$profileStmt->bind_param("s", $profileUsername);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();
if (!$profile) { header("Location: profile.php"); exit(); }
$profileId = (int)$profile['id'];
$isOwnProfile = $profileId === $viewerId;
$viewerFollowsProfile = false;
$followerCount = 0;
$followingCount = 0;
$showList = $_GET['list'] ?? "";
$followUsers = [];
$qf = $conn->query("SELECT COUNT(*) c FROM follows WHERE followed_user_id=$profileId");
if ($qf) { $followerCount = (int)($qf->fetch_assoc()['c'] ?? 0); }
$qf = $conn->query("SELECT COUNT(*) c FROM follows WHERE follower_user_id=$profileId");
if ($qf) { $followingCount = (int)($qf->fetch_assoc()['c'] ?? 0); }
if (!$isOwnProfile) {
    $ck = $conn->prepare("SELECT id FROM follows WHERE follower_user_id=? AND followed_user_id=? LIMIT 1");
    if ($ck) {
        $ck->bind_param("ii", $viewerId, $profileId);
        $ck->execute();
        $viewerFollowsProfile = $ck->get_result()->num_rows > 0;
        $ck->close();
    }
}
$listTarget = "";
if ($showList === "followers") {
    $listTarget = "followers";
    $qfu = $conn->query("SELECT u.username,u.fullname,u.profile_photo FROM follows f INNER JOIN users u ON u.id=f.follower_user_id WHERE f.followed_user_id=$profileId ORDER BY f.created_at DESC");
    if ($qfu) { while ($r = $qfu->fetch_assoc()) $followUsers[] = $r; }
} elseif ($showList === "following") {
    $listTarget = "following";
    $qfu = $conn->query("SELECT u.username,u.fullname,u.profile_photo FROM follows f INNER JOIN users u ON u.id=f.followed_user_id WHERE f.follower_user_id=$profileId ORDER BY f.created_at DESC");
    if ($qfu) { while ($r = $qfu->fetch_assoc()) $followUsers[] = $r; }
}
$section = $_GET['section'] ?? "posts";
if (!in_array($section, ["posts","saved","tagged"], true)) $section = "posts";
$settingsMode = $isOwnProfile && isset($_GET['settings']) && $_GET['settings'] === "1";
$profileError = "";
$profileSuccess = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? "";

    if (($action === "follow_user" || $action === "unfollow_user") && !$isOwnProfile) {
        if ($action === "follow_user") {
            $stmt = $conn->prepare("INSERT IGNORE INTO follows (follower_user_id, followed_user_id) VALUES (?, ?)");
            if ($stmt) { $stmt->bind_param("ii", $viewerId, $profileId); $stmt->execute(); $stmt->close(); }
        } else {
            $stmt = $conn->prepare("DELETE FROM follows WHERE follower_user_id=? AND followed_user_id=?");
            if ($stmt) { $stmt->bind_param("ii", $viewerId, $profileId); $stmt->execute(); $stmt->close(); }
        }
        header("Location: profile.php?user=" . urlencode($profile['username']) . "&section=" . urlencode($section));
        exit();
    }

    if ($action === "update_profile" && $isOwnProfile) {
        $newFullname = trim($_POST['fullname'] ?? "");
        $newUsername = trim($_POST['username'] ?? "");
        $newPhoto = $profile['profile_photo'];
        if ($newFullname === "" || $newUsername === "") $profileError = "Full name and username are required.";
        elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $newUsername)) $profileError = "Username must be 3-30 chars using letters/numbers/underscore.";
        else {
            $check = $conn->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
            $check->bind_param("si", $newUsername, $viewerId);
            $check->execute();
            if ($check->get_result()->num_rows > 0) $profileError = "Username already taken.";
            $check->close();
        }

        if ($profileError === "" && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['profile_photo']['tmp_name'];
            $size = (int)$_FILES['profile_photo']['size'];
            if ($size > 8 * 1024 * 1024) $profileError = "Profile photo must be 8MB or less.";
            else {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($f, $tmp);
                finfo_close($f);
                if (!in_array($mime, ["image/jpeg","image/png","image/webp","image/gif"], true)) $profileError = "Invalid image type.";
                else {
                    $dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "profiles";
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                    if ($ext === "") $ext = "jpg";
                    $name = uniqid("profile_", true) . "." . $ext;
                    $full = $dir . DIRECTORY_SEPARATOR . $name;
                    if (move_uploaded_file($tmp, $full)) $newPhoto = "uploads/profiles/" . $name;
                }
            }
        }

        if ($profileError === "") {
            $oldUsername = $profile['username'];
            $up = $conn->prepare("UPDATE users SET fullname=?,username=?,profile_photo=? WHERE id=?");
            $up->bind_param("sssi", $newFullname, $newUsername, $newPhoto, $viewerId);
            $up->execute();
            $up->close();
            if ($oldUsername !== $newUsername) {
                $alert = $conn->prepare("INSERT INTO name_change_alerts (user_id,old_username,new_username) VALUES (?,?,?)");
                $alert->bind_param("iss", $viewerId, $oldUsername, $newUsername);
                $alert->execute();
                $alert->close();
            }
            $_SESSION['username'] = $newUsername;
            $profile['fullname'] = $newFullname;
            $profile['username'] = $newUsername;
            $profile['profile_photo'] = $newPhoto;
            $profileSuccess = "Profile updated successfully.";
        }
    }

    if (($action === "delete_post" || $action === "edit_post") && $isOwnProfile) {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($action === "delete_post") {
            $d = $conn->prepare("DELETE FROM posts WHERE id=? AND user_id=?");
            $d->bind_param("ii", $postId, $viewerId);
            $d->execute();
            $d->close();
        } else {
            $text = trim($_POST['edit_text'] ?? "");
            $hash = trim($_POST['edit_hashtag'] ?? "");
            if ($hash !== "" && strpos($hash, "#") !== 0) $hash = "#" . preg_replace("/\s+/", "", $hash);
            $remove = isset($_POST['remove_media']);
            $mediaPath = null;
            $mediaType = null;

            if (isset($_FILES['edit_media']) && $_FILES['edit_media']['error'] === UPLOAD_ERR_OK) {
                $allow = ["image/jpeg"=>"image","image/png"=>"image","image/gif"=>"image","image/webp"=>"image","video/mp4"=>"video","video/webm"=>"video","video/quicktime"=>"video"];
                $tmp = $_FILES['edit_media']['tmp_name'];
                $f = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($f, $tmp);
                finfo_close($f);
                if (isset($allow[$mime])) {
                    $dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "posts";
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $ext = strtolower(pathinfo($_FILES['edit_media']['name'], PATHINFO_EXTENSION));
                    if ($ext === "") $ext = $allow[$mime] === "image" ? "jpg" : "mp4";
                    $name = uniqid("post_", true) . "." . $ext;
                    if (move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
                        $mediaPath = "uploads/posts/" . $name;
                        $mediaType = $allow[$mime];
                    }
                }
            }

            if ($mediaPath !== null) {
                $u = $conn->prepare("UPDATE posts SET content=?,hashtag=?,media_path=?,media_type=? WHERE id=? AND user_id=?");
                $u->bind_param("ssssii", $text, $hash, $mediaPath, $mediaType, $postId, $viewerId);
            } elseif ($remove) {
                $none = "none";
                $null = null;
                $u = $conn->prepare("UPDATE posts SET content=?,hashtag=?,media_path=?,media_type=? WHERE id=? AND user_id=?");
                $u->bind_param("ssssii", $text, $hash, $null, $none, $postId, $viewerId);
            } else {
                $u = $conn->prepare("UPDATE posts SET content=?,hashtag=? WHERE id=? AND user_id=?");
                $u->bind_param("ssii", $text, $hash, $postId, $viewerId);
            }
            $u->execute();
            $u->close();
        }
        $redir = "profile.php" . ($isOwnProfile ? "" : "?user=" . urlencode($profile['username']));
        $redir .= (strpos($redir, "?") !== false ? "&" : "?") . "section=" . urlencode($section);
        header("Location: $redir");
        exit();
    }
}

$posts = [];
$q = $conn->query("SELECT id,user_id,content,hashtag,media_path,media_type,created_at FROM posts WHERE user_id=$profileId ORDER BY created_at DESC");
if ($q) { while ($r = $q->fetch_assoc()) $posts[] = $r; }
$saved = [];
if ($isOwnProfile) {
    $q = $conn->query("SELECT p.id,p.user_id,p.content,p.hashtag,p.media_path,p.media_type,p.created_at,u.username FROM saved_posts sp INNER JOIN posts p ON p.id=sp.post_id INNER JOIN users u ON u.id=p.user_id WHERE sp.user_id=$viewerId AND p.media_type IN ('image','video') AND p.media_path IS NOT NULL AND p.media_path<>'' ORDER BY sp.created_at DESC");
    if ($q) { while ($r = $q->fetch_assoc()) $saved[] = $r; }
}
$tagged = [];
$q = $conn->query("SELECT p.id,p.user_id,p.content,p.hashtag,p.media_path,p.media_type,p.created_at,u.username FROM post_tags pt INNER JOIN posts p ON p.id=pt.post_id INNER JOIN users u ON u.id=p.user_id WHERE pt.user_id=$profileId ORDER BY pt.created_at DESC");
if ($q) { while ($r = $q->fetch_assoc()) $tagged[] = $r; }
$items = $section === "saved" ? $saved : ($section === "tagged" ? $tagged : $posts);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Profile</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="dashcss/style.css"></head>
<body>
<div class="bg-glow bg-glow-left"></div><div class="bg-glow bg-glow-right"></div>
<div class="dashboard-shell">
<nav class="sidebar glass-card"><div class="brand"><span class="brand-mark"></span><h1>Grandenians</h1></div><ul class="nav-links"><li><a href="dashboard.php">Home</a></li><li><a href="videos.php">Videos</a></li><li><a href="news.php">News</a></li><li><a href="notification.php">Notification<?php if($notificationCount>0):?> (<?php echo $notificationCount>9?'9+':$notificationCount;?>)<?php endif;?></a></li><li><a href="message.php">Message<?php if($messageRequestCount>0):?> (<?php echo $messageRequestCount>9?'9+':$messageRequestCount;?>)<?php endif;?></a></li><li class="active"><a href="profile.php">Profile</a></li><li><a href="logout.php">Logout</a></li></ul></nav>
<main class="content-area">
<section class="profile-header glass-card"><div class="profile-head-main"><div class="profile-avatar-wrap"><?php if(!empty($profile['profile_photo'])):?><img class="profile-avatar" src="<?php echo e($profile['profile_photo']);?>" alt="Profile photo"><?php else:?><div class="profile-avatar profile-avatar-fallback"><?php echo e(strtoupper(substr($profile['username'],0,1)));?></div><?php endif;?></div><div><h2><?php echo e($profile['username']);?> <?php echo badge_html($profile['badge']??'none');?> <?php if($isOwnProfile):?><a class="settings-link" href="profile.php?settings=1&section=<?php echo e($section);?>">&#9881;</a><?php endif;?></h2><p><?php echo e($profile['fullname']!==""?$profile['fullname']:$profile['username']);?></p><p class="profile-follow-stats"><a href="profile.php?<?php echo $isOwnProfile?'':'user='.urlencode($profile['username']).'&';?>section=<?php echo e($section);?>&list=followers"><strong><?php echo $followerCount; ?></strong> Followers</a> · <a href="profile.php?<?php echo $isOwnProfile?'':'user='.urlencode($profile['username']).'&';?>section=<?php echo e($section);?>&list=following"><strong><?php echo $followingCount; ?></strong> Following</a></p><?php if(!$isOwnProfile):?><form method="POST" class="follow-inline-form"><input type="hidden" name="action" value="<?php echo $viewerFollowsProfile?'unfollow_user':'follow_user';?>"><button type="submit"><?php echo $viewerFollowsProfile?'Following':'Follow Back';?></button></form><?php endif;?></div></div></section>
<?php if($settingsMode):?><section class="profile-settings glass-card"><h3>Profile Settings</h3><?php if($profileError!==""):?><p class="alert-line error"><?php echo e($profileError);?></p><?php endif;?><?php if($profileSuccess!==""):?><p class="alert-line success"><?php echo e($profileSuccess);?></p><?php endif;?><form method="POST" enctype="multipart/form-data" class="profile-settings-form"><input type="hidden" name="action" value="update_profile"><input type="text" name="fullname" value="<?php echo e($profile['fullname']);?>" placeholder="Full name" required><input type="text" name="username" value="<?php echo e($profile['username']);?>" placeholder="Username" required><input type="file" name="profile_photo" accept="image/*"><div class="settings-actions"><button type="submit">Save Changes</button><a class="cancel-btn" href="profile.php?section=<?php echo e($section);?>">Cancel</a></div></form></section><?php endif;?>
<?php if($listTarget!==""):?><section class="glass-card search-results"><h3><?php echo $listTarget==="followers"?"Followers":"Following";?></h3><?php if(count($followUsers)===0):?><p>No users found.</p><?php else:?><div class="search-user-grid"><?php foreach($followUsers as $fu):?><a class="search-user-card" href="profile.php?user=<?php echo urlencode($fu['username']);?>"><?php if(!empty($fu['profile_photo'])):?><img src="<?php echo e($fu['profile_photo']);?>" alt="<?php echo e($fu['username']);?> profile"><?php else:?><span class="search-user-avatar-fallback"><?php echo e(strtoupper(substr($fu['username'],0,1)));?></span><?php endif;?><div><strong><?php echo e($fu['fullname']!==''?$fu['fullname']:$fu['username']);?></strong><small>@<?php echo e($fu['username']);?></small></div></a><?php endforeach;?></div><?php endif;?></section><?php endif;?>
<section class="profile-tabs glass-card"><a class="<?php echo $section==='posts'?'active':'';?>" href="profile.php?<?php echo $isOwnProfile?'':'user='.urlencode($profile['username']).'&';?>section=posts">Posts</a><?php if($isOwnProfile):?><a class="<?php echo $section==='saved'?'active':'';?>" href="profile.php?section=saved">Saved</a><?php endif;?><a class="<?php echo $section==='tagged'?'active':'';?>" href="profile.php?<?php echo $isOwnProfile?'':'user='.urlencode($profile['username']).'&';?>section=tagged">Tagged</a></section>
<section class="profile-grid"><?php if(count($items)===0):?><article class="post glass-card empty-feed"><p>No items in this section yet.</p></article><?php endif;?>
<?php foreach($items as $item):?><article class="profile-grid-item glass-card"><?php if($section!=='posts'):?><span class="grid-owner">@<?php echo e($item['username']);?></span><?php endif;?>
<?php if($item['media_path']!==null&&$item['media_path']!==''):?><?php if($item['media_type']==='video'):?><video controls preload="metadata"><source src="<?php echo e($item['media_path']);?>"></video><?php else:?><img src="<?php echo e($item['media_path']);?>" alt="Post media"><?php endif;?><?php else:?><div class="grid-text-only"><?php $txt=(string)($item['content']??''); echo e(strlen($txt)>140?substr($txt,0,140).'...':$txt);?></div><?php endif;?>
<?php if(!empty($item['hashtag'])):?><p class="post-hashtag"><?php echo e($item['hashtag']);?></p><?php endif;?>
<?php if($isOwnProfile && $section==='posts'):?><details class="edit-post-panel"><summary>&#8942;</summary><form method="POST" enctype="multipart/form-data" class="edit-post-form"><input type="hidden" name="action" value="edit_post"><input type="hidden" name="post_id" value="<?php echo (int)$item['id'];?>"><textarea name="edit_text" rows="2"><?php echo e($item['content']??"");?></textarea><input type="text" name="edit_hashtag" value="<?php echo e($item['hashtag']??"");?>" placeholder="#hashtag"><input type="file" name="edit_media" accept="image/*,video/*"><label><input type="checkbox" name="remove_media" value="1"> Remove media</label><button type="submit">Update</button></form><form method="POST" onsubmit="return confirm('Delete this post?');"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?php echo (int)$item['id'];?>"><button type="submit">Delete</button></form></details><?php endif;?>
</article><?php endforeach;?></section>
</main>
<aside class="right-panel glass-card"><h3>Announcement</h3><ul><li>Design trends for 2026 are getting bolder.</li><li>New short-form video updates released this week.</li><li>Community meetup happening this Friday.</li></ul><div class="profile-card"><strong>Profile</strong><p>@<?php echo e($profile['username']);?></p></div></aside>
</div></body></html>

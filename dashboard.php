<?php
session_start();
include "db.php";
if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) { header("Location: login.php"); exit(); }
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, "UTF-8"); }
function badge_html(string $badge): string {
    if ($badge === "bluebadge") return '<span class="badge badge-check">&#9989;</span>';
    if ($badge === "gmc") return '<span class="badge badge-gmc"><span class="g">G</span><span class="m">M</span><span class="c">C</span></span>';
    if ($badge === "grandenians") return '<span class="badge badge-grand"><span class="g">G</span>randenians</span>';
    return "";
}
$tab = $_GET['tab'] ?? "home";
if (!in_array($tab, ["home", "videos"], true)) $tab = "home";
$isVideos = $tab === "videos";
$pageUrl = $isVideos ? "videos.php" : "dashboard.php";
$search = trim($_GET['q'] ?? "");
$me = null;
if (isset($_SESSION['user_id'])) {
    $sessionUserId = (int)$_SESSION['user_id'];
    $meStmt = $conn->prepare("SELECT id,username,badge,profile_photo FROM users WHERE id=? LIMIT 1");
    if ($meStmt) {
        $meStmt->bind_param("i", $sessionUserId);
        $meStmt->execute();
        $me = $meStmt->get_result()->fetch_assoc();
        $meStmt->close();
    }
}
if (!$me && isset($_SESSION['username'])) {
    $sessionUsername = (string)$_SESSION['username'];
    $meStmt = $conn->prepare("SELECT id,username,badge,profile_photo FROM users WHERE username=? LIMIT 1");
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
$allowMime=["image/jpeg"=>"image","image/png"=>"image","image/gif"=>"image","image/webp"=>"image","video/mp4"=>"video","video/webm"=>"video","video/quicktime"=>"video"];
$reacts=["heart","wow","sad","angry"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? "";
    $ptab = $_POST['tab'] ?? $tab;
    if (!in_array($ptab, ["home", "videos"], true)) $ptab = "home";
    $back = trim($_POST['redirect'] ?? ($ptab === "videos" ? "videos.php" : "dashboard.php"));
    if ($back === "") $back = $ptab === "videos" ? "videos.php" : "dashboard.php";
    $isAjax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    );

    if ($action === "follow_user" || $action === "unfollow_user") {
        $tid = (int)($_POST['target_user_id'] ?? 0);
        if ($tid > 0 && $tid !== $uid) {
            if ($action === "follow_user") {
                $s = $conn->prepare("INSERT IGNORE INTO follows (follower_user_id,followed_user_id) VALUES (?,?)");
                if ($s) {
                    $s->bind_param("ii", $uid, $tid);
                    $s->execute();
                    $added = $s->affected_rows > 0;
                    $s->close();
                    if ($added) {
                        $n = $conn->prepare("INSERT INTO user_notifications (recipient_user_id,actor_user_id,type,post_id) VALUES (?,?,'follow',NULL)");
                        if ($n) {
                            $n->bind_param("ii", $tid, $uid);
                            $n->execute();
                            $n->close();
                        }
                    }
                }
            } else {
                $s = $conn->prepare("DELETE FROM follows WHERE follower_user_id=? AND followed_user_id=?");
                if ($s) {
                    $s->bind_param("ii", $uid, $tid);
                    $s->execute();
                    $s->close();
                }
            }
        }
        header("Location: $back");
        exit();
    }

    if ($action === "create_post" && $ptab !== "videos") {
        $text = trim($_POST['post_text'] ?? "");
        $tag = trim($_POST['hashtag'] ?? "");
        if ($tag !== "" && strpos($tag, "#") !== 0) $tag = "#" . preg_replace("/\\s+/", "", $tag);
        $mPath = null;
        $mType = "none";
        if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['media']['tmp_name'];
            $size = (int)$_FILES['media']['size'];
            if ($size <= 20 * 1024 * 1024) {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($f, $tmp);
                finfo_close($f);
                if (isset($allowMime[$mime])) {
                    $dir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "posts";
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
                    if ($ext === "") $ext = $allowMime[$mime] === "image" ? "jpg" : "mp4";
                    $name = uniqid("post_", true) . "." . $ext;
                    if (move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
                        $mPath = "uploads/posts/" . $name;
                        $mType = $allowMime[$mime];
                    }
                }
            }
        }
        if ($text !== "" || $tag !== "" || $mPath !== null) {
            $ins = $conn->prepare("INSERT INTO posts (user_id,content,hashtag,media_path,media_type) VALUES (?,?,?,?,?)");
            if ($ins) {
                $ins->bind_param("issss", $uid, $text, $tag, $mPath, $mType);
                $ins->execute();
                $ins->close();
            }
        }
        header("Location: $back");
        exit();
    }

    if ($action === "react") {
        $pid = (int)($_POST['post_id'] ?? 0);
        $r = $_POST['reaction'] ?? "";
        $myReaction = "";
        if ($pid > 0 && in_array($r, $reacts, true)) {
            $owner = (int)($conn->query("SELECT user_id FROM posts WHERE id=$pid LIMIT 1")->fetch_assoc()['user_id'] ?? 0);
            $currQ = $conn->prepare("SELECT reaction FROM post_reactions WHERE post_id=? AND user_id=? LIMIT 1");
            $currentReaction = "";
            if ($currQ) {
                $currQ->bind_param("ii", $pid, $uid);
                $currQ->execute();
                $row = $currQ->get_result()->fetch_assoc();
                $currentReaction = (string)($row['reaction'] ?? "");
                $currQ->close();
            }

            if ($currentReaction === $r) {
                $del = $conn->prepare("DELETE FROM post_reactions WHERE post_id=? AND user_id=? LIMIT 1");
                if ($del) {
                    $del->bind_param("ii", $pid, $uid);
                    $del->execute();
                    $del->close();
                }
                $myReaction = "";
            } else {
                $s = $conn->prepare("INSERT INTO post_reactions (post_id,user_id,reaction) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reaction=VALUES(reaction),created_at=CURRENT_TIMESTAMP");
                if ($s) {
                    $s->bind_param("iis", $pid, $uid, $r);
                    $s->execute();
                    $s->close();
                    $myReaction = $r;
                    if ($owner > 0 && $owner !== $uid) {
                        $n = $conn->prepare("INSERT INTO user_notifications (recipient_user_id,actor_user_id,type,post_id) VALUES (?,?,'like',?)");
                        if ($n) {
                            $n->bind_param("iii", $owner, $uid, $pid);
                            $n->execute();
                            $n->close();
                        }
                    }
                }
            }
        }

        if ($isAjax) {
            $counts = ["heart" => 0, "wow" => 0, "sad" => 0, "angry" => 0];
            $cq = $conn->query("SELECT reaction,COUNT(*) total FROM post_reactions WHERE post_id=$pid GROUP BY reaction");
            if ($cq) {
                while ($row = $cq->fetch_assoc()) {
                    if (isset($counts[$row['reaction']])) $counts[$row['reaction']] = (int)$row['total'];
                }
            }
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "ok" => true,
                "post_id" => $pid,
                "counts" => $counts,
                "reaction_total" => array_sum($counts),
                "my_reaction" => $myReaction
            ]);
            exit();
        }
        header("Location: $back");
        exit();
    }

    if ($action === "comment") {
        $pid = (int)($_POST['post_id'] ?? 0);
        $ct = trim($_POST['comment_text'] ?? "");
        $inserted = false;
        if ($pid > 0 && $ct !== "") {
            $s = $conn->prepare("INSERT INTO post_comments (post_id,user_id,comment_text) VALUES (?,?,?)");
            if ($s) {
                $s->bind_param("iis", $pid, $uid, $ct);
                $inserted = $s->execute();
                $s->close();
            }
        }

        if ($isAjax) {
            $commentTotal = 0;
            $q = $conn->query("SELECT COUNT(*) c FROM post_comments WHERE post_id=$pid");
            if ($q) $commentTotal = (int)($q->fetch_assoc()['c'] ?? 0);
            header("Content-Type: application/json; charset=UTF-8");
            echo json_encode([
                "ok" => $inserted,
                "post_id" => $pid,
                "comment_total" => $commentTotal,
                "comment" => [
                    "username" => (string)$me['username'],
                    "profile_photo" => (string)($me['profile_photo'] ?? ""),
                    "comment_text" => $ct
                ]
            ]);
            exit();
        }
        header("Location: $back");
        exit();
    }
}
$searchUsers=[];
if($search!==""){$like="%".$search."%";$s=$conn->prepare("SELECT id,username,fullname,badge,profile_photo FROM users WHERE username LIKE ? OR fullname LIKE ? LIMIT 12");if($s){$s->bind_param("ss",$like,$like);$s->execute();$rs=$s->get_result();while($row=$rs->fetch_assoc())$searchUsers[]=$row;$s->close();}}
$filter=$isVideos?"WHERE p.media_type='video' AND p.media_path IS NOT NULL AND p.media_path<>''":"";
$posts=[];$ids=[];
$pq=$conn->query("SELECT p.id,p.user_id,p.content,p.hashtag,p.media_path,p.media_type,p.created_at,u.username,u.badge,u.profile_photo,(SELECT COUNT(*) FROM post_reactions r WHERE r.post_id=p.id) reaction_total,(SELECT COUNT(*) FROM post_comments c WHERE c.post_id=p.id) comment_total,(SELECT COUNT(*) FROM post_reposts rp WHERE rp.post_id=p.id) repost_total FROM posts p INNER JOIN users u ON u.id=p.user_id $filter ORDER BY p.created_at DESC LIMIT 25");
if($pq){while($r=$pq->fetch_assoc()){$posts[]=$r;$ids[]=(int)$r['id'];}}
$rc=[];$mr=[];$cm=[];$reactors=[];
if(count($ids)>0){$idSql=implode(",",array_map("intval",$ids));
$q=$conn->query("SELECT post_id,reaction,COUNT(*) total FROM post_reactions WHERE post_id IN ($idSql) GROUP BY post_id,reaction");if($q){while($r=$q->fetch_assoc()){$pid=(int)$r['post_id'];if(!isset($rc[$pid]))$rc[$pid]=["heart"=>0,"wow"=>0,"sad"=>0,"angry"=>0];$rc[$pid][$r['reaction']]=(int)$r['total'];}}
$q=$conn->prepare("SELECT post_id,reaction FROM post_reactions WHERE user_id=? AND post_id IN ($idSql)");if($q){$q->bind_param("i",$uid);$q->execute();$rq=$q->get_result();while($rq&&($r=$rq->fetch_assoc()))$mr[(int)$r['post_id']]=$r['reaction'];$q->close();}
$q=$conn->query("SELECT pr.post_id,u.username FROM post_reactions pr INNER JOIN users u ON u.id=pr.user_id WHERE pr.post_id IN ($idSql) ORDER BY pr.created_at DESC");if($q){while($r=$q->fetch_assoc()){$pid=(int)$r['post_id'];if(!isset($reactors[$pid]))$reactors[$pid]=[];if(count($reactors[$pid])<8)$reactors[$pid][]=$r['username'];}}
$q=$conn->query("SELECT pc.post_id,pc.comment_text,u.username,u.profile_photo FROM post_comments pc INNER JOIN users u ON u.id=pc.user_id WHERE pc.post_id IN ($idSql) ORDER BY pc.created_at DESC");if($q){while($r=$q->fetch_assoc()){$pid=(int)$r['post_id'];if(!isset($cm[$pid]))$cm[$pid]=[];if(count($cm[$pid])<4)$cm[$pid][]=$r;}}
}
$topHashtags=[];$hq=$conn->query("SELECT u.username,COUNT(*) hashtag_posts FROM posts p INNER JOIN users u ON u.id=p.user_id WHERE p.hashtag IS NOT NULL AND p.hashtag<>'' GROUP BY p.user_id ORDER BY hashtag_posts DESC,u.username ASC LIMIT 8");if($hq){while($row=$hq->fetch_assoc())$topHashtags[]=$row;}
$topStudents=[];$sq=$conn->query("SELECT u.username,u.badge,(SELECT COUNT(*) FROM follows f WHERE f.followed_user_id=u.id) followers,((SELECT COUNT(*) FROM post_reactions pr INNER JOIN posts p ON p.id=pr.post_id WHERE p.user_id=u.id)+(SELECT COUNT(*) FROM post_comments pc INNER JOIN posts p2 ON p2.id=pc.post_id WHERE p2.user_id=u.id)) supporters FROM users u ORDER BY ((followers*10)+(supporters*5)) DESC,u.username ASC LIMIT 10");if($sq){while($row=$sq->fetch_assoc())$topStudents[]=$row;}
$suggested=[];$sug=$conn->query("SELECT u.id,u.username,u.fullname,u.badge,u.profile_photo FROM users u WHERE u.id<>$uid AND u.id NOT IN (SELECT followed_user_id FROM follows WHERE follower_user_id=$uid) ORDER BY (SELECT COUNT(*) FROM follows f WHERE f.followed_user_id=u.id) DESC,u.created_at DESC LIMIT 6");if($sug){while($row=$sug->fetch_assoc())$suggested[]=$row;}
$ann=[];$aq=$conn->query("SELECT np.id,np.title FROM news_posts np INNER JOIN users u ON u.id=np.user_id WHERE u.can_publish_news=1 ORDER BY np.created_at DESC LIMIT 5");if($aq){while($row=$aq->fetch_assoc())$ann[]=$row;}
$mentions=[];$mq=$conn->query("SELECT username FROM users ORDER BY username ASC LIMIT 100");if($mq){while($row=$mq->fetch_assoc())$mentions[]=$row['username'];}
$notifications=[];$nq=$conn->query("SELECT n.type,n.created_at,n.post_id,u.username actor FROM user_notifications n INNER JOIN users u ON u.id=n.actor_user_id WHERE n.recipient_user_id=$uid ORDER BY n.created_at DESC LIMIT 8");if($nq){while($row=$nq->fetch_assoc())$notifications[]=$row;}
$notificationCount=(int)($conn->query("SELECT COUNT(*) c FROM user_notifications WHERE recipient_user_id=$uid AND is_read=0")->fetch_assoc()['c']??0);
$messageRequestCount=(int)($conn->query("SELECT COUNT(*) c FROM message_conversations WHERE is_request=1 AND request_for_user_id=$uid")->fetch_assoc()['c']??0);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Dashboard</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="dashcss/style.css"></head>
<body>
<div class="bg-glow bg-glow-left"></div><div class="bg-glow bg-glow-right"></div>
<div class="dashboard-shell">
<nav class="sidebar glass-card"><div class="brand"><span class="brand-mark"></span><h1>Grandenians</h1></div><ul class="nav-links"><li class="<?php echo !$isVideos?'active':'';?>"><a href="dashboard.php">Home</a></li><li class="<?php echo $isVideos?'active':'';?>"><a href="videos.php">Videos</a></li><li><a href="news.php">News</a></li><li><a href="notification.php">Notification<?php if($notificationCount>0):?> (<?php echo $notificationCount>9?'9+':$notificationCount;?>)<?php endif;?></a></li><li><a href="message.php">Message<?php if($messageRequestCount>0):?> (<?php echo $messageRequestCount>9?'9+':$messageRequestCount;?>)<?php endif;?></a></li><li><a href="profile.php">Profile</a></li><li><a href="logout.php">Logout</a></li></ul></nav>
<main class="content-area">
<header class="topbar glass-card"><h2><?php echo $isVideos?'Videos':'Home';?></h2><form method="GET" action="<?php echo e($pageUrl);?>" class="top-search-form"><input type="search" name="q" value="<?php echo e($search);?>" placeholder="Search user by name or username"></form><button type="button" class="theme-toggle-btn" data-theme-toggle>Dark mode</button></header>
<?php if($search!==""):?><section class="search-results glass-card"><h3>Search Results</h3><?php if(count($searchUsers)===0):?><p>No users found.</p><?php else:?><div class="search-user-grid"><?php foreach($searchUsers as $u):?><a class="search-user-card" href="profile.php?user=<?php echo urlencode($u['username']);?>"><?php if(!empty($u['profile_photo'])):?><img src="<?php echo e($u['profile_photo']);?>" alt="<?php echo e($u['username']);?> profile"><?php else:?><span class="search-user-avatar-fallback"><?php echo e(strtoupper(substr($u['username'],0,1)));?></span><?php endif;?><div><strong><?php echo e($u['fullname']!==''?$u['fullname']:$u['username']);?></strong><small>@<?php echo e($u['username']);?> <?php echo badge_html($u['badge']??'none');?></small></div></a><?php endforeach;?></div><?php endif;?></section><?php endif;?>
<?php if(!$isVideos):?><section class="post-composer glass-card"><form method="POST" enctype="multipart/form-data" class="composer-form"><input type="hidden" name="action" value="create_post"><input type="hidden" name="tab" value="<?php echo e($tab);?>"><input type="hidden" name="redirect" value="<?php echo e($pageUrl.($search!==''?('?q='.urlencode($search)):''));?>"><div class="composer-head"><h3>Create Post</h3><div class="composer-tools"><label for="media-upload" class="plus-upload">+</label><button type="button" id="clear-media-btn" class="clear-upload">Clear</button></div><input id="media-upload" class="upload-input" type="file" name="media" accept="image/*,video/*"></div><div id="upload-preview" class="upload-preview hidden"></div><textarea name="post_text" rows="3" placeholder="What do you want to share?" maxlength="500"></textarea><input list="mention-users" type="text" name="mention_user" placeholder="Type name to mention..." maxlength="60"><datalist id="mention-users"><?php foreach($mentions as $mu):?><option value="<?php echo e($mu);?>"><?php endforeach;?></datalist><input type="text" name="hashtag" placeholder="#AddHashtag (optional)" maxlength="80"><button type="submit">Post</button></form></section><?php endif;?>
<?php if(!$isVideos):?><section class="home-insights"><article class="insight-card glass-card"><h3>Top Hashtag Users</h3><?php if(count($topHashtags)===0):?><p>No hashtag users yet.</p><?php else:?><ul class="hashtag-list"><?php foreach($topHashtags as $h):?><li><a href="profile.php?user=<?php echo urlencode($h['username']);?>">@<?php echo e($h['username']);?></a><span><?php echo (int)$h['hashtag_posts'];?> hashtag posts</span></li><?php endforeach;?></ul><?php endif;?></article><article class="insight-card glass-card"><h3>Student Popularity Rank</h3><?php if(count($topStudents)===0):?><p>No popularity data yet.</p><?php else:?><ul class="student-list"><?php $rk=1; foreach($topStudents as $st):?><li><span>Rank #<?php echo $rk;?> @<?php echo e($st['username']);?> <?php echo badge_html($st['badge']??'none');?></span><span><?php echo ((int)$st['followers']*10)+((int)$st['supporters']*5);?> pts</span></li><?php $rk++; endforeach;?></ul><?php endif;?></article></section><?php endif;?>
<section class="feed"><?php if(count($posts)===0):?><article class="post glass-card empty-feed"><p><?php echo $isVideos?'No video posts yet.':'No posts yet.';?></p></article><?php endif;?>
<?php foreach($posts as $p): $pid=(int)$p['id'];$counts=$rc[$pid]??["heart"=>0,"wow"=>0,"sad"=>0,"angry"=>0];$my=$mr[$pid]??"";$comments=$cm[$pid]??[];$postReactors=$reactors[$pid]??[]; ?>
<article id="post-<?php echo $pid; ?>" class="post glass-card" data-post-id="<?php echo $pid; ?>"><header><div class="post-user-line"><?php if(!empty($p['profile_photo'])):?><img class="comment-avatar" src="<?php echo e($p['profile_photo']);?>" alt="<?php echo e($p['username']);?> avatar"><?php else:?><span class="comment-avatar avatar-fallback"><?php echo e(strtoupper(substr($p['username'],0,1)));?></span><?php endif;?><h3>@<?php echo e($p['username']);?> <?php echo badge_html($p['badge']??'none');?></h3></div><span class="post-time"><?php echo e(date("M j, Y g:i A", strtotime($p['created_at'])));?></span></header>
<?php if($p['content']!==""):?><p><?php echo nl2br(e($p['content']));?></p><?php endif;?><?php if($p['hashtag']!==""):?><p class="post-hashtag"><?php echo e($p['hashtag']);?></p><?php endif;?>
<?php if($p['media_path']!==null&&$p['media_path']!==""):?><div class="post-media"><?php if($p['media_type']==="video"):?><video controls preload="metadata"><source src="<?php echo e($p['media_path']);?>"></video><?php else:?><img src="<?php echo e($p['media_path']);?>" alt="Post media"><?php endif;?></div><?php endif;?>
<div class="post-stats"><span><?php echo (int)$p['reaction_total'];?> reactions</span><span><?php echo (int)$p['comment_total'];?> comments</span><span><?php echo (int)$p['repost_total'];?> reposts</span></div>
<?php if(count($postReactors)>0):?><div class="reactor-list"><strong>Reacted by:</strong><?php foreach($postReactors as $rx):?><span>@<?php echo e($rx);?></span><?php endforeach;?></div><?php endif;?>
<div class="post-actions"><form method="POST" class="reaction-row modern-reaction-row"><input type="hidden" name="action" value="react"><input type="hidden" name="post_id" value="<?php echo $pid;?>"><input type="hidden" name="tab" value="<?php echo e($tab);?>"><input type="hidden" name="redirect" value="<?php echo e($pageUrl.($search!==''?('?q='.urlencode($search)):''));?>"><button class="icon-btn reaction-btn <?php echo $my==='heart'?'active':'';?>" type="submit" name="reaction" value="heart" title="Heart"><span class="action-icon">&#10084;</span><span class="action-count" data-reaction-count="heart"><?php echo (int)$counts['heart'];?></span></button><button class="icon-btn reaction-btn <?php echo $my==='wow'?'active':'';?>" type="submit" name="reaction" value="wow" title="Wow"><span class="action-icon">&#128562;</span><span class="action-count" data-reaction-count="wow"><?php echo (int)$counts['wow'];?></span></button><button class="icon-btn reaction-btn <?php echo $my==='sad'?'active':'';?>" type="submit" name="reaction" value="sad" title="Sad"><span class="action-icon">&#128546;</span><span class="action-count" data-reaction-count="sad"><?php echo (int)$counts['sad'];?></span></button><button class="icon-btn reaction-btn <?php echo $my==='angry'?'active':'';?>" type="submit" name="reaction" value="angry" title="Angry"><span class="action-icon">&#128545;</span><span class="action-count" data-reaction-count="angry"><?php echo (int)$counts['angry'];?></span></button></form><button type="button" class="icon-btn comment-trigger" title="Comment"><span class="action-icon">&#128172;</span><span class="action-count" data-comment-count><?php echo (int)$p['comment_total'];?></span></button><button type="button" class="icon-btn share-post-btn" data-post-id="<?php echo $pid; ?>" title="Share"><span class="action-icon">&#10150;</span></button></div>
<div class="comments-section"><?php foreach($comments as $c):?><div class="comment-item"><?php if(!empty($c['profile_photo'])):?><img class="comment-avatar" src="<?php echo e($c['profile_photo']);?>" alt="<?php echo e($c['username']);?> avatar"><?php else:?><span class="comment-avatar avatar-fallback"><?php echo e(strtoupper(substr($c['username'],0,1)));?></span><?php endif;?><p><strong>@<?php echo e($c['username']);?></strong> <?php echo e($c['comment_text']);?></p></div><?php endforeach;?><form method="POST" class="comment-form"><input type="hidden" name="action" value="comment"><input type="hidden" name="post_id" value="<?php echo $pid;?>"><input type="hidden" name="tab" value="<?php echo e($tab);?>"><input type="hidden" name="redirect" value="<?php echo e($pageUrl.($search!==''?('?q='.urlencode($search)):''));?>"><input type="text" name="comment_text" maxlength="250" placeholder="Add a comment..." required><button type="submit" title="Send comment">&#10148;</button></form></div></article>
<?php endforeach;?></section></main>
<aside class="right-panel glass-card"><h3>Announcement</h3><ul><?php if(count($ann)===0):?><li><a href="news.php">No announcements yet.</a></li><?php endif;?><?php foreach($ann as $a):?><li><a href="news.php#news-<?php echo (int)$a['id'];?>"><?php echo e($a['title']);?></a></li><?php endforeach;?></ul>
<div class="suggested-follows"><h3>Suggested Users</h3><?php if(count($suggested)===0):?><p>No suggested users right now.</p><?php else:?><ul><?php foreach($suggested as $su):?><li><div class="suggest-user"><?php if(!empty($su['profile_photo'])):?><img class="avatar-photo" src="<?php echo e($su['profile_photo']);?>" alt="<?php echo e($su['username']);?> avatar"><?php else:?><span class="avatar avatar-fallback"><?php echo e(strtoupper(substr($su['username'],0,1)));?></span><?php endif;?><div><strong>@<?php echo e($su['username']);?></strong><small><?php echo e($su['fullname']);?> <?php echo badge_html($su['badge']??'none');?></small></div></div><form method="POST"><input type="hidden" name="action" value="follow_user"><input type="hidden" name="target_user_id" value="<?php echo (int)$su['id'];?>"><input type="hidden" name="tab" value="<?php echo e($tab);?>"><input type="hidden" name="redirect" value="<?php echo e($pageUrl.($search!==''?('?q='.urlencode($search)):''));?>"><button type="submit">Follow</button></form></li><?php endforeach;?></ul><?php endif;?></div>
<div class="profile-card"><strong>Profile</strong><p>Welcome back, <?php echo e($me['username']);?> <?php echo badge_html($me['badge']??"none");?></p></div></aside></div>
<script>
(function () {
    const KEY = 'grandenians-theme';
    const btn = document.querySelector('[data-theme-toggle]');
    const root = document.documentElement;
    const apply = (mode) => {
        root.setAttribute('data-theme', mode);
        if (btn) btn.textContent = mode === 'dark' ? 'Light mode' : 'Dark mode';
    };
    apply(localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light');
    if (btn) {
        btn.addEventListener('click', function () {
            const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            localStorage.setItem(KEY, next);
            apply(next);
        });
    }
})();

(function () {
    const input = document.getElementById('media-upload');
    const preview = document.getElementById('upload-preview');
    const clearBtn = document.getElementById('clear-media-btn');
    if (!input || !preview || !clearBtn) return;
    function clear() {
        input.value = '';
        preview.innerHTML = '';
        preview.classList.add('hidden');
    }
    clearBtn.addEventListener('click', clear);
    input.addEventListener('change', function () {
        preview.innerHTML = '';
        preview.classList.add('hidden');
        const file = input.files && input.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        let el = null;
        if (file.type.startsWith('video/')) {
            el = document.createElement('video');
            el.controls = true;
            el.preload = 'metadata';
        } else if (file.type.startsWith('image/')) {
            el = document.createElement('img');
        }
        if (!el) return;
        el.src = url;
        preview.appendChild(el);
        preview.classList.remove('hidden');
    });
})();

(function () {
    function setReactionButtonCount(button, count) {
        const target = button.querySelector('[data-reaction-count]');
        if (target) target.textContent = String(count);
    }

    document.querySelectorAll('.reaction-row button[name="reaction"]').forEach(function (btn) {
        btn.addEventListener('click', async function (ev) {
            ev.preventDefault();
            const form = btn.closest('form.reaction-row');
            if (!form) return;

            const fd = new FormData(form);
            fd.set('reaction', btn.value);
            fd.set('ajax', '1');

            try {
                const res = await fetch('post_actions.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: fd
                });
                const data = await res.json();
                if (!data || !data.ok) return;

                const article = form.closest('article.post');
                if (!article) return;

                form.querySelectorAll('button[name="reaction"]').forEach(function (b) {
                    const key = b.value;
                    b.classList.toggle('active', data.my_reaction === key);
                    setReactionButtonCount(b, (data.counts && data.counts[key]) ? data.counts[key] : 0);
                });

                const statsSpans = article.querySelectorAll('.post-stats span');
                if (statsSpans.length >= 2) {
                    statsSpans[0].textContent = (data.reaction_total || 0) + ' reactions';
                }
            } catch (err) {
                form.submit();
            }
        });
    });

    document.querySelectorAll('.comment-form').forEach(function (form) {
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const input = form.querySelector('input[name="comment_text"]');
            if (!input) return;
            const text = input.value.trim();
            if (!text) return;

            const fd = new FormData(form);
            fd.set('comment_text', text);
            fd.set('ajax', '1');

                const res = await fetch('post_actions.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: fd
            });
            const data = await res.json();
            if (!data || !data.ok) return;

            const article = form.closest('article.post');
            if (!article) return;
            const commentsSection = form.closest('.comments-section');
            if (!commentsSection) return;

            const item = document.createElement('div');
            item.className = 'comment-item';

            if (data.comment && data.comment.profile_photo) {
                const img = document.createElement('img');
                img.className = 'comment-avatar';
                img.src = data.comment.profile_photo;
                img.alt = data.comment.username + ' avatar';
                item.appendChild(img);
            } else {
                const span = document.createElement('span');
                span.className = 'comment-avatar avatar-fallback';
                span.textContent = (data.comment && data.comment.username ? data.comment.username : 'U').charAt(0).toUpperCase();
                item.appendChild(span);
            }

            const p = document.createElement('p');
            const strong = document.createElement('strong');
            strong.textContent = '@' + (data.comment ? data.comment.username : 'user');
            p.appendChild(strong);
            p.appendChild(document.createTextNode(' ' + (data.comment ? data.comment.comment_text : text)));
            item.appendChild(p);

            commentsSection.insertBefore(item, form);
            input.value = '';

            const statsSpans = article.querySelectorAll('.post-stats span');
            if (statsSpans.length >= 2) {
                statsSpans[1].textContent = (data.comment_total || 0) + ' comments';
            }
            const commentCount = article.querySelector('[data-comment-count]');
            if (commentCount) commentCount.textContent = String(data.comment_total || 0);
        });
    });

    document.querySelectorAll('.comment-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const article = btn.closest('article.post');
            if (!article) return;
            const input = article.querySelector('.comment-form input[name="comment_text"]');
            if (!input) return;
            input.focus();
            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    document.querySelectorAll('.share-post-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const article = btn.closest('article.post');
            if (!article) return;
            const postId = article.getAttribute('data-post-id');
            if (!postId) return;

            const toUsername = prompt('Share to username:');
            if (!toUsername) return;

            const fd = new FormData();
            fd.set('action', 'share_post');
            fd.set('post_id', postId);
            fd.set('to_username', toUsername.trim());

            try {
                const res = await fetch('post_actions.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: fd
                });
                const data = await res.json();
                if (data && data.ok) {
                    alert('Post shared to @' + toUsername.trim());
                } else {
                    alert('Could not share post. Check username.');
                }
            } catch (err) {
                alert('Could not share post right now.');
            }
        });
    });
})();
</script>
</body></html>

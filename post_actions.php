<?php
session_start();
include "db.php";

header("Content-Type: application/json; charset=UTF-8");

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_out(["ok" => false, "error" => "method_not_allowed"], 405);
}

$uid = 0;
$username = "";
$profilePhoto = "";

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, profile_photo FROM users WHERE id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $me = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($me) {
            $username = (string)($me['username'] ?? "");
            $profilePhoto = (string)($me['profile_photo'] ?? "");
        }
    }
}

if ($uid <= 0 && isset($_SESSION['username'])) {
    $sessionUsername = (string)$_SESSION['username'];
    $stmt = $conn->prepare("SELECT id, username, profile_photo FROM users WHERE username=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $sessionUsername);
        $stmt->execute();
        $me = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($me) {
            $uid = (int)$me['id'];
            $username = (string)($me['username'] ?? "");
            $profilePhoto = (string)($me['profile_photo'] ?? "");
        }
    }
}

if ($uid <= 0) {
    json_out(["ok" => false, "error" => "unauthorized"], 401);
}

$action = (string)($_POST['action'] ?? "");

if ($action === "react") {
    $reacts = ["heart", "wow", "sad", "angry"];
    $pid = (int)($_POST['post_id'] ?? 0);
    $r = (string)($_POST['reaction'] ?? "");
    if ($pid <= 0 || !in_array($r, $reacts, true)) {
        json_out(["ok" => false, "error" => "invalid_reaction_request"], 422);
    }

    $owner = 0;
    $ownerQ = $conn->query("SELECT user_id FROM posts WHERE id=$pid LIMIT 1");
    if ($ownerQ) $owner = (int)($ownerQ->fetch_assoc()['user_id'] ?? 0);

    $currQ = $conn->prepare("SELECT reaction FROM post_reactions WHERE post_id=? AND user_id=? LIMIT 1");
    $currentReaction = "";
    if ($currQ) {
        $currQ->bind_param("ii", $pid, $uid);
        $currQ->execute();
        $row = $currQ->get_result()->fetch_assoc();
        $currentReaction = (string)($row['reaction'] ?? "");
        $currQ->close();
    }

    $myReaction = "";
    if ($currentReaction === $r) {
        $del = $conn->prepare("DELETE FROM post_reactions WHERE post_id=? AND user_id=? LIMIT 1");
        if ($del) {
            $del->bind_param("ii", $pid, $uid);
            $del->execute();
            $del->close();
        }
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

    $counts = ["heart" => 0, "wow" => 0, "sad" => 0, "angry" => 0];
    $cq = $conn->query("SELECT reaction,COUNT(*) total FROM post_reactions WHERE post_id=$pid GROUP BY reaction");
    if ($cq) {
        while ($row = $cq->fetch_assoc()) {
            if (isset($counts[$row['reaction']])) $counts[$row['reaction']] = (int)$row['total'];
        }
    }

    json_out([
        "ok" => true,
        "post_id" => $pid,
        "counts" => $counts,
        "reaction_total" => array_sum($counts),
        "my_reaction" => $myReaction
    ]);
}

if ($action === "comment") {
    $pid = (int)($_POST['post_id'] ?? 0);
    $ct = trim((string)($_POST['comment_text'] ?? ""));
    if ($pid <= 0 || $ct === "") {
        json_out(["ok" => false, "error" => "invalid_comment_request"], 422);
    }

    $inserted = false;
    $s = $conn->prepare("INSERT INTO post_comments (post_id,user_id,comment_text) VALUES (?,?,?)");
    if ($s) {
        $s->bind_param("iis", $pid, $uid, $ct);
        $inserted = $s->execute();
        $s->close();
    }
    if (!$inserted) {
        json_out(["ok" => false, "error" => "comment_insert_failed"], 500);
    }

    $commentTotal = 0;
    $q = $conn->query("SELECT COUNT(*) c FROM post_comments WHERE post_id=$pid");
    if ($q) $commentTotal = (int)($q->fetch_assoc()['c'] ?? 0);

    json_out([
        "ok" => true,
        "post_id" => $pid,
        "comment_total" => $commentTotal,
        "comment" => [
            "username" => $username,
            "profile_photo" => $profilePhoto,
            "comment_text" => $ct
        ]
    ]);
}

if ($action === "share_post") {
    $pid = (int)($_POST['post_id'] ?? 0);
    $toUsername = trim((string)($_POST['to_username'] ?? ""));
    if ($pid <= 0 || $toUsername === "") {
        json_out(["ok" => false, "error" => "invalid_share_request"], 422);
    }

    $postExists = $conn->query("SELECT id FROM posts WHERE id=$pid LIMIT 1");
    if (!$postExists || $postExists->num_rows === 0) {
        json_out(["ok" => false, "error" => "post_not_found"], 404);
    }

    $find = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    if (!$find) {
        json_out(["ok" => false, "error" => "db_error"], 500);
    }
    $find->bind_param("s", $toUsername);
    $find->execute();
    $other = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$other) {
        json_out(["ok" => false, "error" => "recipient_not_found"], 404);
    }
    $oid = (int)$other['id'];
    if ($oid === $uid) {
        json_out(["ok" => false, "error" => "cannot_share_to_self"], 422);
    }

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
    if (!$findConv) {
        json_out(["ok" => false, "error" => "db_error"], 500);
    }
    $findConv->bind_param("ii", $uid, $oid);
    $findConv->execute();
    $existing = $findConv->get_result()->fetch_assoc();
    $findConv->close();

    $cid = 0;
    if ($existing) {
        $cid = (int)$existing['id'];
    } else {
        if ($isRequest === 1) {
            $create = $conn->prepare("INSERT INTO message_conversations (name, is_group, is_request, request_for_user_id, created_by) VALUES (NULL, 0, 1, ?, ?)");
            if (!$create) json_out(["ok" => false, "error" => "db_error"], 500);
            $create->bind_param("ii", $oid, $uid);
        } else {
            $create = $conn->prepare("INSERT INTO message_conversations (name, is_group, is_request, request_for_user_id, created_by) VALUES (NULL, 0, 0, NULL, ?)");
            if (!$create) json_out(["ok" => false, "error" => "db_error"], 500);
            $create->bind_param("i", $uid);
        }
        $create->execute();
        $cid = (int)$create->insert_id;
        $create->close();

        if ($cid > 0) {
            $member = $conn->prepare("INSERT INTO message_conversation_members (conversation_id, user_id) VALUES (?, ?), (?, ?)");
            if (!$member) json_out(["ok" => false, "error" => "db_error"], 500);
            $member->bind_param("iiii", $cid, $uid, $cid, $oid);
            $member->execute();
            $member->close();
        }
    }

    if ($cid <= 0) {
        json_out(["ok" => false, "error" => "conversation_create_failed"], 500);
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($basePath === '.') $basePath = '';
    $url = "http://" . $host . $basePath . "/dashboard.php#post-" . $pid;
    $shareText = "Shared a post: " . $url;

    $ins = $conn->prepare("INSERT INTO message_items (conversation_id, sender_user_id, message_text, media_path, media_type) VALUES (?, ?, ?, NULL, 'none')");
    if (!$ins) {
        json_out(["ok" => false, "error" => "share_insert_failed"], 500);
    }
    $ins->bind_param("iis", $cid, $uid, $shareText);
    $ok = $ins->execute();
    $ins->close();

    if (!$ok) {
        json_out(["ok" => false, "error" => "share_insert_failed"], 500);
    }

    json_out(["ok" => true, "conversation_id" => $cid, "to_username" => $toUsername]);
}

json_out(["ok" => false, "error" => "unsupported_action"], 400);
?>

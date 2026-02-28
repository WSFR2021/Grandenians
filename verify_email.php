<?php
include "db.php";

$token = trim((string)($_GET["token"] ?? ""));
$error = "";
$success = "";

if ($token === "" || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = "Invalid verification link.";
} else {
    $tokenHash = hash("sha256", $token);
    $stmt = $conn->prepare(
        "SELECT id, user_id
         FROM email_verifications
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at >= NOW()
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $error = "This verification link is expired or already used.";
        } else {
            $verificationId = (int)$row["id"];
            $userId = (int)$row["user_id"];

            $upUser = $conn->prepare("UPDATE users SET email_verified = 1, email_verified_at = NOW() WHERE id = ?");
            if ($upUser) {
                $upUser->bind_param("i", $userId);
                $upUser->execute();
                $upUser->close();
            }

            $upToken = $conn->prepare("UPDATE email_verifications SET used_at = NOW() WHERE id = ?");
            if ($upToken) {
                $upToken->bind_param("i", $verificationId);
                $upToken->execute();
                $upToken->close();
            }

            header("Location: login.php?verified=1");
            exit();
        }
    } else {
        $error = "Database error. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grandenians - Verify Email</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashcss/auth.css">
</head>
<body>
<div class="bg-glow bg-glow-left"></div>
<div class="bg-glow bg-glow-right"></div>

<main class="auth-shell">
    <section class="auth-card glass-card">
        <img src="logo.png" width="70" alt="Grandenians logo">
        <div class="app-name">Grandenians</div>
        <div class="tagline">Bridging Gap, Fortifying Careers.</div>
        <h1>Email Verification</h1>

        <?php if ($error !== ""): ?>
            <p class="alert error"><?php echo htmlspecialchars($error); ?></p>
            <div class="switch"><a href="login.php">Back to Login</a></div>
        <?php else: ?>
            <p class="alert success">Email verified successfully. Redirecting to login...</p>
            <div class="switch"><a href="login.php?verified=1">Continue to Login</a></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

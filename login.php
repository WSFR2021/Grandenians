<?php
session_start();
include "db.php";

if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$success = "";

if (isset($_GET["registered"]) && $_GET["registered"] === "1") {
    $success = "Account created. Please log in.";
}
if (isset($_GET["reset"]) && $_GET["reset"] === "1") {
    $success = "Password changed successfully. Please log in.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim((string)($_POST['username'] ?? ''), " \t\n\r\0\x0B'\"");
    $password = rtrim((string)($_POST['password'] ?? ''), "\r\n");

    if ($username === "" || $password === "") {
        $error = "Please enter username/email and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, password
             FROM users
             WHERE username = ?
                OR email = ?
                OR LOWER(username) = LOWER(?)
                OR LOWER(email) = LOWER(?)
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param("ssss", $username, $username, $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($user) {
                $storedPassword = trim((string)$user['password']);
                $inputPassword = (string)$password;
                $validPassword = password_verify($inputPassword, $storedPassword);
                $isLegacyPlain = !$validPassword && hash_equals($storedPassword, $inputPassword);
                $isLegacyMd5 = !$validPassword && !$isLegacyPlain && hash_equals($storedPassword, md5($inputPassword));
                $isLegacySha1 = !$validPassword && !$isLegacyPlain && !$isLegacyMd5 && hash_equals($storedPassword, sha1($inputPassword));
                $validPassword = $validPassword || $isLegacyPlain || $isLegacyMd5 || $isLegacySha1;

                if ($validPassword) {
                    if ($isLegacyPlain || $isLegacyMd5 || $isLegacySha1 || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                        $newHash = password_hash($inputPassword, PASSWORD_DEFAULT);
                        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        if ($update) {
                            $update->bind_param("si", $newHash, $user['id']);
                            $update->execute();
                            $update->close();
                        }
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    header("Location: dashboard.php");
                    exit();
                }
            }

            if ($error === "") {
                $error = "Invalid username/email or password.";
            }
        } else {
            $error = "Database error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grandenians - Login</title>
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

        <h1>Login</h1>

        <?php if ($error !== ""): ?>
            <p class="alert error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <p class="alert success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <input type="text" name="username" placeholder="Username or Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log In</button>
        </form>

        <div class="switch">
            Forgot your password?
            <a href="forgot_password.php">Reset password</a>
        </div>
        <div class="switch">
            Don't have an account?
            <a href="signup.php">Sign up</a>
        </div>
    </section>
</main>
</body>
</html>


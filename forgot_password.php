<?php
include "db.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($email === "" || $password === "" || $confirmPassword === "") {
        $error = "Please complete all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Password confirmation does not match.";
    } else {
        $find = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($find) {
            $find->bind_param("s", $email);
            $find->execute();
            $result = $find->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $find->close();

            if (!$user) {
                $error = "No account found for that email.";
            } else {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($update) {
                    $userId = (int) $user["id"];
                    $update->bind_param("si", $newHash, $userId);
                    if ($update->execute()) {
                        $update->close();
                        header("Location: login.php?reset=1");
                        exit();
                    } else {
                        $error = "Could not update password. Please try again.";
                    }
                    $update->close();
                } else {
                    $error = "Database error. Please try again.";
                }
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
    <title>Grandenians - Forgot Password</title>
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

        <h1>Reset Password</h1>

        <?php if ($error !== ""): ?>
            <p class="alert error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <p class="alert success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <input type="email" name="email" placeholder="Account Email" value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>" required>
            <input type="password" name="password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
            <button type="submit">Update Password</button>
        </form>

        <div class="switch">
            Back to login?
            <a href="login.php">Log in</a>
        </div>
    </section>
</main>
</body>
</html>


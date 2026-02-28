<?php
include "db.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullname === "" || $username === "" || $email === "" || $password === "") {
        $error = "Please complete all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Password confirmation does not match.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");

        if ($check) {
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $checkResult = $check->get_result();
            $exists = $checkResult && $checkResult->num_rows > 0;
            $check->close();

            if ($exists) {
                $error = "Username or email already exists.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO users (fullname, username, email, password) VALUES (?, ?, ?, ?)");

                if ($insert) {
                    $insert->bind_param("ssss", $fullname, $username, $email, $hashedPassword);
                    if ($insert->execute()) {
                        $newUserId = (int)$insert->insert_id;
                        $insert->close();

                        if ($newUserId > 0) {
                            $gmcStmt = $conn->prepare("SELECT id FROM users WHERE username = 'GMC_Official' LIMIT 1");
                            if ($gmcStmt) {
                                $gmcStmt->execute();
                                $gmcRow = $gmcStmt->get_result()->fetch_assoc();
                                $gmcStmt->close();

                                $gmcId = (int)($gmcRow['id'] ?? 0);
                                if ($gmcId > 0 && $gmcId !== $newUserId) {
                                    $followStmt = $conn->prepare("INSERT IGNORE INTO follows (follower_user_id, followed_user_id) VALUES (?, ?)");
                                    if ($followStmt) {
                                        $followStmt->bind_param("ii", $newUserId, $gmcId);
                                        $followStmt->execute();
                                        $followStmt->close();
                                    }
                                }
                            }
                        }

                        header("Location: login.php?registered=1");
                        exit();
                    } else {
                        $insert->close();
                    }
                }

                if ($error === "") {
                    $error = "Could not create account. Please try again.";
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
    <title>Grandenians - Sign Up</title>
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

        <h1>Create Account</h1>

        <?php if ($error !== ""): ?>
            <p class="alert error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success !== ""): ?>
            <p class="alert success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <input type="text" name="fullname" placeholder="Full Name" value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" required>
            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit">Sign Up</button>
        </form>

        <div class="switch">
            Already have an account?
            <a href="login.php">Log in</a>
        </div>
    </section>
</main>
</body>
</html>


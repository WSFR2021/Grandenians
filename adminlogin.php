<?php
session_start();
include "db.php";
if (isset($_SESSION['admin_id'])) { header("Location: admindashboard.php"); exit(); }
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user = trim($_POST['username'] ?? "");
    $pass = $_POST['password'] ?? "";
    $stmt = $conn->prepare("SELECT id, fullname, password FROM admins WHERE username=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $dbPass = $row['password'];
            $hashed = password_get_info($dbPass)['algo'] !== 0;
            $ok = $hashed ? password_verify($pass, $dbPass) : hash_equals($dbPass, $pass);
            if ($ok) {
                if (!$hashed) {
                    $newHash = password_hash($pass, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE admins SET password=? WHERE id=?");
                    if ($up) {
                        $aid = (int)$row['id'];
                        $up->bind_param("si", $newHash, $aid);
                        $up->execute();
                        $up->close();
                    }
                }
                $_SESSION['admin_id'] = (int)$row['id'];
                $_SESSION['admin_username'] = $user;
                $_SESSION['admin_fullname'] = $row['fullname'];
                header("Location: admindashboard.php");
                exit();
            }
        }
    }
    $error = "Invalid admin credentials.";
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title><link rel="stylesheet" href="dashcss/adminstyle.css"></head>
<body>
<main class="admin-login-wrap">
    <section class="admin-login-card">
        <h1>Admin Login</h1>
        <?php if ($error !== ""): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Admin username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        <a href="login.php">Back to User Login</a>
    </section>
</main>
</body></html>

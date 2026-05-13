<?php
require_once "config.php";

$message = "";

if (isset($_GET["registered"])) {
    $message = "Kayıt başarılı. Şimdi giriş yapabilirsin.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($email === "" || $password === "") {
        $message = "Lütfen tüm alanları doldur.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user["password"])) {
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];
            $_SESSION["user_email"] = $user["email"];

            header("Location: home.php");
            exit;
        } else {
            $message = "E-posta veya şifre yanlış.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <link rel="icon" type="image/png" href="favicon.png" />
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FORMIX - Giriş Yap</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body>
    <div class="center-page">
        <div class="form-card">
            <h2>Giriş Yap</h2>
            <p>Hesabına giriş yaparak FORMIX hesabına ulaş.</p>

            <?php if ($message): ?>
                <div class="result-box"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" placeholder="E-postanı gir" required />
                </div>

                <div class="form-group">
                    <label>Şifre</label>
                    <input type="password" name="password" placeholder="Şifreni gir" required />
                </div>

                <button class="btn primary full" type="submit">Giriş Yap</button>
            </form>

            <p class="switch-text">
                Hesabın yok mu? <a href="register.php">Kayıt Ol</a>
            </p>
        </div>
    </div>
</body>
</html>
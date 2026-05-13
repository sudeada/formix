<?php
require_once "config.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $height = trim($_POST["height"] ?? "");
    $weight = trim($_POST["weight"] ?? "");

    if ($name === "" || $email === "" || $password === "" || $height === "" || $weight === "") {
        $message = "Lütfen tüm alanları doldur.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Geçerli bir e-posta adresi gir.";
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $message = "Bu e-posta zaten kayıtlı.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password, height, weight)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name,
                $email,
                $hashedPassword,
                (int)$height,
                (float)$weight
            ]);

            header("Location: login.php?registered=1");
            exit;
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
    <title>FORMIX - Kayıt Ol</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body>
    <div class="center-page">
        <div class="form-card">
            <h2>Kayıt Ol</h2>
            <p>FORMIX hesabını oluştur ve takibe başla.</p>

            <?php if ($message): ?>
                <div class="result-box"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Ad Soyad</label>
                    <input type="text" name="name" placeholder="Adını gir" required />
                </div>

                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" placeholder="E-posta gir" required />
                </div>

                <div class="form-group">
                    <label>Şifre</label>
                    <input type="password" name="password" placeholder="Şifre oluştur" required />
                </div>

                <div class="form-group">
                    <label>Boy (cm)</label>
                    <input type="number" name="height" placeholder="Örn: 175" required />
                </div>

                <div class="form-group">
                    <label>Kilo (kg)</label>
                    <input type="number" step="0.1" name="weight" placeholder="Örn: 72" required />
                </div>

                <button class="btn primary full" type="submit">Kayıt Ol</button>
            </form>

            <p class="switch-text">
                Zaten hesabın var mı? <a href="login.php">Giriş Yap</a>
            </p>
        </div>
    </div>
</body>
</html>
<?php
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_diet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            goal VARCHAR(30) DEFAULT NULL,
            daily_calorie INT DEFAULT NULL,
            diet_mode VARCHAR(50) DEFAULT NULL,
            bmi DECIMAL(4,1) DEFAULT NULL,
            bmi_text VARCHAR(30) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Tablo oluşturma hatası: " . $e->getMessage());
}

$stmt = $pdo->prepare("
    SELECT u.height, u.weight, d.bmi, d.bmi_text
    FROM users u
    LEFT JOIN user_diet d ON u.id = d.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$height = $user["height"] ?? "";
$weight = $user["weight"] ?? "";
$bmi = $user["bmi"] ?? "";
$status = $user["bmi_text"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $height = (int)($_POST["height"] ?? 0);
    $weight = (float)($_POST["weight"] ?? 0);

    if (!$height || !$weight) {
        $message = "Boy ve kilo gir.";
        $messageType = "error";
    } else {
        $bmi = round($weight / (($height / 100) * ($height / 100)), 1);

        if ($bmi < 18.5) {
            $status = "Zayıf";
        } elseif ($bmi < 25) {
            $status = "Normal";
        } elseif ($bmi < 30) {
            $status = "Fazla Kilolu";
        } else {
            $status = "Obez";
        }

        try {
            $pdo->beginTransaction();

            $updateUser = $pdo->prepare("
                UPDATE users
                SET height = ?, weight = ?
                WHERE id = ?
            ");
            $updateUser->execute([$height, $weight, $user_id]);

            $checkDiet = $pdo->prepare("SELECT id FROM user_diet WHERE user_id = ?");
            $checkDiet->execute([$user_id]);

            if ($checkDiet->fetch()) {
                $updateDiet = $pdo->prepare("
                    UPDATE user_diet
                    SET bmi = ?, bmi_text = ?
                    WHERE user_id = ?
                ");
                $updateDiet->execute([$bmi, $status, $user_id]);
            } else {
                $insertDiet = $pdo->prepare("
                    INSERT INTO user_diet (user_id, bmi, bmi_text)
                    VALUES (?, ?, ?)
                ");
                $insertDiet->execute([$user_id, $bmi, $status]);
            }

            $pdo->commit();
            $message = "BMI hesaplandı ve kaydedildi.";
            $messageType = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Kayıt hatası: " . $e->getMessage();
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - VKİ Hesaplama</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="icon" type="image/png" href="favicon.png" />

  <style>
    .logo-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 26px;
      font-weight: bold;
      color: #22c55e;
      text-decoration: none;
      flex-shrink: 0;
    }

    .logo-brand img {
      width: 42px;
      height: 42px;
      object-fit: contain;
      border-radius: 12px;
      background: rgba(255,255,255,0.08);
      padding: 4px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.18);
      display: block;
    }

    .bmi-container {
      max-width: 700px;
      margin: 40px auto;
    }

    .bmi-card {
      background: rgba(255,255,255,0.07);
      border-radius: 24px;
      padding: 30px;
      box-shadow: 0 20px 45px rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .bmi-card h1 {
      font-size: 32px;
      margin-bottom: 10px;
    }

    .bmi-card p {
      color: #cbd5e1;
      margin-bottom: 20px;
    }

    .result-box {
      margin-top: 20px;
      padding: 18px;
      border-radius: 16px;
      background: rgba(15,23,42,0.55);
      color: #dbeafe;
      line-height: 1.7;
      border: 1px solid rgba(255,255,255,0.06);
    }

    .result-box.success {
      background: rgba(34,197,94,0.12);
      color: #bbf7d0;
    }

    .result-box.error {
      background: rgba(239,68,68,0.12);
      color: #fecaca;
    }

    .big-result {
      font-size: 38px;
      font-weight: bold;
      color: #4ade80;
      margin-top: 10px;
    }

    .status-badge {
      display: inline-block;
      margin-top: 10px;
      padding: 8px 14px;
      border-radius: 999px;
      background: rgba(255,255,255,0.08);
      color: #e2e8f0;
      font-size: 14px;
    }

    @media (max-width: 700px) {
      .bmi-container {
        margin: 20px auto;
      }

      .bmi-card {
        padding: 22px;
      }

      .logo-brand {
        font-size: 22px;
      }
    }
  </style>
</head>
<body>

  <div class="topbar">
    <a href="home.php" class="logo-brand">
      <img src="favicon.png" alt="FORMIX" />
      <span>FORMIX</span>
    </a>

    <div class="top-links">
      <a href="home.php">Ana Sayfa</a>
      <a href="diet.php">Diyet</a>
      <a href="bmi.php" class="active">VKİ</a>
                        <a href="goal.php">Hedef Sistemi</a>

      <a href="profile.php">Profil</a>
      <a href="calorie.php">Kalori</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container bmi-container">
    <div class="bmi-card">
      <h1>VKİ Hesaplama</h1>
      <p>Boy ve kilo bilgine göre vücut kitle indeksini öğren.</p>

      <?php if ($message): ?>
        <div class="result-box <?php echo $messageType; ?>">
          <?php echo htmlspecialchars($message); ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Boy (cm)</label>
          <input type="number" name="height" placeholder="Örn: 175" value="<?php echo htmlspecialchars((string)$height); ?>">
        </div>

        <div class="form-group">
          <label>Kilo (kg)</label>
          <input type="number" step="0.1" name="weight" placeholder="Örn: 72" value="<?php echo htmlspecialchars((string)$weight); ?>">
        </div>

        <button class="btn primary full" type="submit">Hesapla</button>
      </form>

      <div class="result-box">
        <?php if ($bmi !== "" && $status !== ""): ?>
          <div>VKİ Sonucun:</div>
          <div class="big-result"><?php echo htmlspecialchars((string)$bmi); ?></div>
          <div>Durum: <strong><?php echo htmlspecialchars($status); ?></strong></div>
          <div class="status-badge">Sonuç veritabanına kaydedildi</div>
        <?php else: ?>
          Sonuç burada görünecek.
        <?php endif; ?>
      </div>
    </div>
  </div>

</body>
</html>
<?php
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "";

$today = date("Y-m-d");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS progress_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        weight DECIMAL(5,2) NOT NULL,
        log_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $weight = (float)($_POST["weight"] ?? 0);

    if ($weight <= 0) {
        $message = "Geçerli kilo gir.";
        $messageType = "error";
    } else {

        try {
            $pdo->beginTransaction();

            /* users güncelle */
            $pdo->prepare("
                UPDATE users 
                SET weight = ? 
                WHERE id = ?
            ")->execute([$weight, $user_id]);

            /* log ekle */
            $pdo->prepare("
                INSERT INTO progress_logs (user_id, weight, log_date)
                VALUES (?, ?, ?)
            ")->execute([$user_id, $weight, $today]);

            $pdo->commit();

            $message = "Kilo kaydedildi.";
            $messageType = "success";

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Hata: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

$stmt = $pdo->prepare("
    SELECT weight 
    FROM progress_logs
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([$user_id]);
$lastWeight = $stmt->fetchColumn();

$history = $pdo->prepare("
    SELECT weight, log_date 
    FROM progress_logs
    WHERE user_id = ?
    ORDER BY log_date DESC
    LIMIT 10
");
$history->execute([$user_id]);
$rows = $history->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8" />
<title>FORMIX - İlerleme</title>
<link rel="stylesheet" href="style.css" />
<link rel="icon" href="favicon.png" />

<style>
.logo-brand{
display:flex;align-items:center;gap:12px;
font-size:26px;font-weight:bold;color:#22c55e;
text-decoration:none;
}

.card{
background:rgba(255,255,255,0.06);
border-radius:20px;
padding:24px;
margin-bottom:20px;
}

.history-item{
padding:10px 0;
border-bottom:1px solid rgba(255,255,255,0.08);
color:#dbeafe;
}
</style>
</head>

<body>

<div class="topbar">
  <a href="home.php" class="logo-brand">
    <img src="favicon.png" width="40">
    FORMIX
  </a>

  <div class="top-links">
    <a href="home.php">Ana Sayfa</a>
    <a href="diet.php">Diyet</a>
    <a href="profile.php">Profil</a>
    <a href="progress.php" class="active">İlerleme</a>
                            <a href="goal.php">Hedef Sistemi</a>

    <a href="calorie.php">Kalori</a>
    <a href="logout.php">Çıkış</a>
  </div>
</div>

<div class="container">

  <div class="card">
    <h2>Kilo Takibi</h2>

    <?php if($message): ?>
      <div class="result-box <?php echo $messageType; ?>">
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Yeni Kilo (kg)</label>
        <input type="number" step="0.1" name="weight" placeholder="Örn: 70">
      </div>

      <button class="btn primary full">Kaydet</button>
    </form>

    <div class="result-box mt-20">
      Son kayıtlı kilo: 
      <strong><?php echo $lastWeight ? $lastWeight : "-"; ?></strong> kg
    </div>
  </div>

  <div class="card">
    <h3>Geçmiş Kayıtlar</h3>

    <?php if($rows): ?>
      <?php foreach($rows as $r): ?>
        <div class="history-item">
          <?php echo $r["log_date"]; ?> → 
          <strong><?php echo $r["weight"]; ?> kg</strong>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="history-item">Kayıt yok</div>
    <?php endif; ?>

  </div>

</div>

</body>
</html>
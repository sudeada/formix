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
$DAILY_GOAL = 2500;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS water_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            entry_date DATE NOT NULL,
            amount_ml INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_water_day (user_id, entry_date)
        )
    ");
} catch (PDOException $e) {
    die("Tablo oluşturma hatası: " . $e->getMessage());
}

function getWaterAmount(PDO $pdo, int $user_id, string $date): int {
    $stmt = $pdo->prepare("
        SELECT amount_ml
        FROM water_entries
        WHERE user_id = ? AND entry_date = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row["amount_ml"] : 0;
}

function saveWaterAmount(PDO $pdo, int $user_id, string $date, int $amount): void {
    $check = $pdo->prepare("
        SELECT id
        FROM water_entries
        WHERE user_id = ? AND entry_date = ?
        LIMIT 1
    ");
    $check->execute([$user_id, $date]);
    $exists = $check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $update = $pdo->prepare("
            UPDATE water_entries
            SET amount_ml = ?
            WHERE user_id = ? AND entry_date = ?
        ");
        $update->execute([$amount, $user_id, $date]);
    } else {
        $insert = $pdo->prepare("
            INSERT INTO water_entries (user_id, entry_date, amount_ml)
            VALUES (?, ?, ?)
        ");
        $insert->execute([$user_id, $date, $amount]);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "quick_add") {
        $amount = (int)($_POST["amount"] ?? 0);
        $current = getWaterAmount($pdo, $user_id, $today);
        $newAmount = max(0, $current + $amount);

        saveWaterAmount($pdo, $user_id, $today, $newAmount);
        $message = "Bugünkü su kaydı güncellendi.";
        $messageType = "success";
    }

    if ($action === "reset_today") {
        saveWaterAmount($pdo, $user_id, $today, 0);
        $message = "Bugünkü su kaydı sıfırlandı.";
        $messageType = "success";
    }

    if ($action === "manual_save") {
        $entryDate = trim($_POST["entry_date"] ?? "");
        $entryAmount = (int)($_POST["entry_amount"] ?? -1);

        if ($entryDate === "") {
            $message = "Lütfen tarih seç.";
            $messageType = "error";
        } elseif ($entryAmount < 0) {
            $message = "Lütfen geçerli su miktarı gir.";
            $messageType = "error";
        } else {
            saveWaterAmount($pdo, $user_id, $entryDate, $entryAmount);
            $message = "Su kaydı kaydedildi.";
            $messageType = "success";
        }
    }
}

$todayAmount = getWaterAmount($pdo, $user_id, $today);

$weekStart = date("Y-m-d", strtotime("-6 days"));
$weekStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount_ml), 0) AS total
    FROM water_entries
    WHERE user_id = ? AND entry_date BETWEEN ? AND ?
");
$weekStmt->execute([$user_id, $weekStart, $today]);
$weeklyTotal = (int)($weekStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

$monthStart = date("Y-m-01");
$monthStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount_ml), 0) AS total
    FROM water_entries
    WHERE user_id = ? AND entry_date BETWEEN ? AND ?
");
$monthStmt->execute([$user_id, $monthStart, $today]);
$monthlyTotal = (int)($monthStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

$historyStmt = $pdo->prepare("
    SELECT entry_date, amount_ml
    FROM water_entries
    WHERE user_id = ?
    ORDER BY entry_date DESC
    LIMIT 12
");
$historyStmt->execute([$user_id]);
$historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$progress = min(($todayAmount / $DAILY_GOAL) * 100, 100);

function toGlass($ml) {
    return round(($ml / 200), 1);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - Su Takibi</title>
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
    }

    .logo-brand img {
      width: 42px;
      height: 42px;
      object-fit: contain;
      border-radius: 12px;
      background: rgba(255,255,255,0.08);
      padding: 4px;
      box-shadow: 0 8px 18px rgba(0,0,0,0.18);
    }

    .water-hero {
      padding: 32px;
      border-radius: 26px;
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: 0 20px 45px rgba(0,0,0,0.22);
      margin-bottom: 24px;
    }

    .water-hero h1 {
      font-size: 36px;
      margin-bottom: 10px;
    }

    .water-hero p {
      color: #cbd5e1;
      line-height: 1.7;
      max-width: 850px;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
      margin-top: 22px;
    }

    .stat-card {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 18px;
      padding: 20px;
    }

    .stat-card h4 {
      color: #cbd5e1;
      font-size: 14px;
      font-weight: normal;
      margin-bottom: 8px;
    }

    .stat-card strong {
      font-size: 28px;
      color: #4ade80;
    }

    .water-grid {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 22px;
      margin-top: 24px;
    }

    .panel {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      box-shadow: 0 16px 35px rgba(0,0,0,0.20);
      padding: 24px;
    }

    .panel h2 {
      margin-bottom: 16px;
      font-size: 24px;
    }

    .today-box {
      background: rgba(15,23,42,0.5);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 20px;
      padding: 20px;
      margin-bottom: 18px;
    }

    .today-amount {
      font-size: 40px;
      font-weight: bold;
      color: #4ade80;
      margin: 8px 0 10px;
    }

    .sub-text {
      color: #cbd5e1;
      line-height: 1.6;
    }

    .action-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 16px;
    }

    .action-row form {
      display: inline-block;
    }

    .quick-btn {
      border: none;
      padding: 12px 18px;
      border-radius: 12px;
      cursor: pointer;
      color: white;
      font-weight: bold;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      transition: 0.2s;
    }

    .quick-btn:hover {
      transform: translateY(-2px);
    }

    .secondary-btn {
      background: rgba(255,255,255,0.12);
    }

    .danger-btn {
      background: #ef4444;
    }

    .progress-wrap {
      width: 100%;
      height: 18px;
      background: rgba(255,255,255,0.08);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 14px;
    }

    .progress-bar {
      height: 100%;
      width: <?php echo $progress; ?>%;
      background: linear-gradient(135deg, #22c55e, #4ade80);
      transition: 0.3s ease;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #e2e8f0;
    }

    .form-group input {
      width: 100%;
      padding: 13px 14px;
      border-radius: 12px;
      border: none;
      outline: none;
      background: rgba(255,255,255,0.12);
      color: white;
      font-size: 15px;
    }

    .history-list {
      display: grid;
      gap: 12px;
      margin-top: 16px;
    }

    .history-item {
      background: rgba(15,23,42,0.48);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 16px;
      padding: 14px 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }

    .history-date {
      color: #cbd5e1;
      font-size: 14px;
    }

    .history-value {
      color: #4ade80;
      font-weight: bold;
      font-size: 18px;
    }

    .empty-box {
      margin-top: 16px;
      padding: 16px;
      border-radius: 16px;
      background: rgba(255,255,255,0.05);
      color: #cbd5e1;
      line-height: 1.6;
    }

    .result-box {
      margin-bottom: 18px;
      padding: 14px 16px;
      border-radius: 14px;
      font-size: 14px;
      border: 1px solid rgba(255,255,255,0.08);
    }

    .result-box.success {
      background: rgba(34,197,94,0.12);
      color: #bbf7d0;
    }

    .result-box.error {
      background: rgba(239,68,68,0.12);
      color: #fecaca;
    }

    @media (max-width: 1100px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .water-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 650px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .water-hero h1 {
        font-size: 28px;
      }

      .today-amount {
        font-size: 32px;
      }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <a href="home.php" class="logo-brand">
      <img src="favicon.png" alt="FORMIX Logo" />
      <span>FORMIX</span>
    </a>

    <div class="top-links">
      <a href="home.php">Ana Sayfa</a>
      <a href="diet.php">Diyet</a>
      <a href="water.php" class="active">Su Takibi</a>
      <a href="workout.php">Antrenman</a>
                              <a href="goal.php">Hedef Sistemi</a>

      <a href="profile.php">Profil</a>
      <a href="progress.php">İlerleme</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container">
    <section class="water-hero">
      <h1>Su Takibi</h1>
      <p>
        Günlük su tüketimini kaydet, haftalık ve aylık toplamını gör.
        FORMIX su içme düzenini takip ederek daha sağlıklı ve disiplinli kalmana yardımcı olur.
      </p>

      <div class="stats-grid">
        <div class="stat-card">
          <h4>Bugün</h4>
          <strong><?php echo $todayAmount; ?> ml</strong>
        </div>
        <div class="stat-card">
          <h4>Bugünkü Bardak</h4>
          <strong><?php echo toGlass($todayAmount); ?></strong>
        </div>
        <div class="stat-card">
          <h4>Haftalık Toplam</h4>
          <strong><?php echo $weeklyTotal; ?> ml</strong>
        </div>
        <div class="stat-card">
          <h4>Aylık Toplam</h4>
          <strong><?php echo $monthlyTotal; ?> ml</strong>
        </div>
      </div>
    </section>

    <div class="water-grid">
      <div class="panel">
        <h2>Bugünkü Kayıt</h2>

        <?php if ($message): ?>
          <div class="result-box <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
          </div>
        <?php endif; ?>

        <div class="today-box">
          <div class="sub-text">Bugün içilen toplam su</div>
          <div class="today-amount"><?php echo $todayAmount; ?> ml</div>
          <div class="sub-text">Hedef: <?php echo $DAILY_GOAL; ?> ml • <?php echo toGlass($DAILY_GOAL); ?> bardak</div>

          <div class="progress-wrap">
            <div class="progress-bar"></div>
          </div>

          <div class="action-row">
            <form method="POST">
              <input type="hidden" name="action" value="quick_add">
              <input type="hidden" name="amount" value="200">
              <button class="quick-btn" type="submit">+1 Bardak (200 ml)</button>
            </form>

            <form method="POST">
              <input type="hidden" name="action" value="quick_add">
              <input type="hidden" name="amount" value="500">
              <button class="quick-btn" type="submit">+500 ml</button>
            </form>

            <form method="POST">
              <input type="hidden" name="action" value="quick_add">
              <input type="hidden" name="amount" value="1000">
              <button class="quick-btn secondary-btn" type="submit">+1 Litre</button>
            </form>

            <form method="POST">
              <input type="hidden" name="action" value="reset_today">
              <button class="quick-btn danger-btn" type="submit">Bugünü Sıfırla</button>
            </form>
          </div>
        </div>

        <h2>Veri Girişi</h2>

        <form method="POST">
          <input type="hidden" name="action" value="manual_save">

          <div class="form-group">
            <label>Tarih</label>
            <input type="date" name="entry_date" value="<?php echo htmlspecialchars($today); ?>" />
          </div>

          <div class="form-group">
            <label>Su Miktarı (ml)</label>
            <input type="number" name="entry_amount" placeholder="Örn: 1800" />
          </div>

          <div class="action-row">
            <button class="quick-btn" type="submit">Kaydı Ekle / Güncelle</button>
          </div>
        </form>

        <div class="empty-box">
          Günlük veri girişi yapabilirsin. Aynı tarihe tekrar değer girersen o günün verisi güncellenir.
        </div>
      </div>

      <div class="panel">
        <h2>Son Kayıtlar</h2>
        <div class="history-list">
          <?php if ($historyRows): ?>
            <?php foreach ($historyRows as $row): ?>
              <div class="history-item">
                <div>
                  <div class="history-date"><?php echo htmlspecialchars($row["entry_date"]); ?></div>
                  <div class="sub-text"><?php echo toGlass((int)$row["amount_ml"]); ?> bardak</div>
                </div>
                <div class="history-value"><?php echo (int)$row["amount_ml"]; ?> ml</div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-box">
              Henüz su kaydı yok. İlk kaydını ekleyerek başla.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
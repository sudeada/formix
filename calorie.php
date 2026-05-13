<?php
require_once "config.php";

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calorie_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            log_date DATE NOT NULL,
            meal_type VARCHAR(30) DEFAULT NULL,
            calories INT NOT NULL DEFAULT 0,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Tablo oluşturma hatası: " . $e->getMessage());
}

$today = date("Y-m-d");
$weekStart = date("Y-m-d", strtotime("-6 days"));
$monthStart = date("Y-m-01");

$userDietExists = false;
$userDietHasDailyCalorie = false;

try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_diet'");
    $userDietExists = (bool)$checkTable->fetch();

    if ($userDietExists) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM user_diet LIKE 'daily_calorie'");
        $userDietHasDailyCalorie = (bool)$checkColumn->fetch();
    }
} catch (PDOException $e) {
    $userDietExists = false;
    $userDietHasDailyCalorie = false;
}

$targetCalorie = 2000;

if ($userDietExists && $userDietHasDailyCalorie) {
    try {
        $targetStmt = $pdo->prepare("
            SELECT daily_calorie
            FROM user_diet
            WHERE user_id = ?
            LIMIT 1
        ");
        $targetStmt->execute([$user_id]);
        $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetRow && !empty($targetRow["daily_calorie"])) {
            $targetCalorie = (int)$targetRow["daily_calorie"];
        }
    } catch (PDOException $e) {
        $targetCalorie = 2000;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "add_calorie") {
        $logDate = trim($_POST["date"] ?? "");
        $mealType = trim($_POST["meal_type"] ?? "");
        $calories = (int)($_POST["calorie"] ?? 0);
        $note = trim($_POST["note"] ?? "");

        if ($logDate === "" || $calories <= 0) {
            $message = "Tarih ve kalori alanını doğru doldur.";
            $messageType = "error";
        } else {
            try {
                $insertStmt = $pdo->prepare("
                    INSERT INTO calorie_logs (user_id, log_date, meal_type, calories, note)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $user_id,
                    $logDate,
                    $mealType !== "" ? $mealType : null,
                    $calories,
                    $note !== "" ? $note : null
                ]);

                $message = "Kalori kaydı eklendi.";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Kayıt hatası: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }

    if ($action === "delete_calorie") {
        $deleteId = (int)($_POST["delete_id"] ?? 0);

        if ($deleteId > 0) {
            try {
                $deleteStmt = $pdo->prepare("
                    DELETE FROM calorie_logs
                    WHERE id = ? AND user_id = ?
                ");
                $deleteStmt->execute([$deleteId, $user_id]);

                $message = "Kayıt silindi.";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Silme hatası: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

$todayCalories = 0;
try {
    $todayStmt = $pdo->prepare("
        SELECT COALESCE(SUM(calories), 0) AS total
        FROM calorie_logs
        WHERE user_id = ? AND log_date = ?
    ");
    $todayStmt->execute([$user_id, $today]);
    $todayCalories = (int)($todayStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);
} catch (PDOException $e) {
    $todayCalories = 0;
}

$weekCalories = 0;
try {
    $weekStmt = $pdo->prepare("
        SELECT COALESCE(SUM(calories), 0) AS total
        FROM calorie_logs
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
    ");
    $weekStmt->execute([$user_id, $weekStart, $today]);
    $weekCalories = (int)($weekStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);
} catch (PDOException $e) {
    $weekCalories = 0;
}

$monthCalories = 0;
try {
    $monthStmt = $pdo->prepare("
        SELECT COALESCE(SUM(calories), 0) AS total
        FROM calorie_logs
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
    ");
    $monthStmt->execute([$user_id, $monthStart, $today]);
    $monthCalories = (int)($monthStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);
} catch (PDOException $e) {
    $monthCalories = 0;
}

$mealBreakdown = [];
try {
    $mealBreakdownStmt = $pdo->prepare("
        SELECT 
            COALESCE(meal_type, 'Diğer') AS meal_type,
            SUM(calories) AS total
        FROM calorie_logs
        WHERE user_id = ? AND log_date = ?
        GROUP BY meal_type
        ORDER BY total DESC
    ");
    $mealBreakdownStmt->execute([$user_id, $today]);
    $mealBreakdown = $mealBreakdownStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mealBreakdown = [];
}

$historyRows = [];
try {
    $historyStmt = $pdo->prepare("
        SELECT id, log_date, meal_type, calories, note
        FROM calorie_logs
        WHERE user_id = ?
        ORDER BY log_date DESC, id DESC
        LIMIT 12
    ");
    $historyStmt->execute([$user_id]);
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historyRows = [];
}

$remaining = $targetCalorie - $todayCalories;
$progressPercent = $targetCalorie > 0 ? min(100, max(0, ($todayCalories / $targetCalorie) * 100)) : 0;
$overTarget = $todayCalories > $targetCalorie;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FORMIX - Kalori Takibi</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="icon" href="favicon.png" />

  <style>
    .logo-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 24px;
      color: #22c55e;
      font-weight: bold;
    }

    .logo-brand img {
      width: 40px;
      border-radius: 10px;
    }

    .page-hero {
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      padding: 28px;
      margin-bottom: 22px;
    }

    .page-hero h1 {
      font-size: 34px;
      margin-bottom: 8px;
    }

    .page-hero p {
      color: #cbd5e1;
      line-height: 1.7;
      max-width: 850px;
    }

    .card {
      background: rgba(255,255,255,0.06);
      border-radius: 20px;
      padding: 24px;
      margin-bottom: 20px;
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: 0 16px 35px rgba(0,0,0,0.18);
    }

    .big {
      font-size: 36px;
      color: #4ade80;
      font-weight: bold;
      margin-top: 10px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(4,1fr);
      gap: 15px;
      margin-top: 20px;
    }

    .stat {
      background: rgba(255,255,255,0.06);
      padding: 16px;
      border-radius: 15px;
      border: 1px solid rgba(255,255,255,0.06);
    }

    .stat p {
      color: #cbd5e1;
      margin-bottom: 8px;
    }

    .stat strong {
      font-size: 22px;
      color: #4ade80;
    }

    .two-col {
      display: grid;
      grid-template-columns: 1.1fr 1fr;
      gap: 20px;
    }

    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #e2e8f0;
      font-size: 14px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 13px 14px;
      border-radius: 14px;
      border: none;
      outline: none;
      background: rgba(255,255,255,0.1);
      color: white;
      font-size: 15px;
    }

    .form-group textarea {
      resize: vertical;
      min-height: 90px;
    }

    .btn {
      width: 100%;
      padding: 14px;
      border: none;
      border-radius: 14px;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: white;
      font-size: 15px;
      font-weight: bold;
      cursor: pointer;
      transition: 0.25s;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(34, 197, 94, 0.28);
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

    .progress-wrap {
      width: 100%;
      height: 18px;
      background: rgba(255,255,255,0.08);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 16px;
    }

    .progress-bar {
      height: 100%;
      width: <?php echo $progressPercent; ?>%;
      background: linear-gradient(135deg, #22c55e, #4ade80);
    }

    .progress-label {
      margin-top: 10px;
      color: #cbd5e1;
      line-height: 1.6;
    }

    .meal-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      color: #dbeafe;
    }

    .history-item {
      padding: 14px 0;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: center;
    }

    .history-left {
      display: flex;
      flex-direction: column;
      gap: 4px;
      color: #dbeafe;
    }

    .history-right {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .mini-delete {
      border: none;
      background: rgba(239,68,68,0.15);
      color: #fecaca;
      padding: 8px 12px;
      border-radius: 10px;
      cursor: pointer;
      font-weight: bold;
    }

    .badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,0.08);
      color: #e2e8f0;
      font-size: 12px;
    }

    @media (max-width: 1100px) {
      .grid {
        grid-template-columns: repeat(2,1fr);
      }

      .two-col {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 650px) {
      .grid {
        grid-template-columns: 1fr;
      }

      .history-item {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="logo-brand">
    <img src="favicon.png" alt="FORMIX">
    FORMIX
  </div>

  <div class="top-links">
    <a href="home.php">Ana Sayfa</a>
    <a href="diet.php">Diyet</a>
          <a href="workout.php">Antrenman</a>
                                  <a href="goal.php">Hedef Sistemi</a>

    <a href="profile.php">Profil</a>
    <a href="calorie.php" class="active">Kalori</a>
    <a href="logout.php">Çıkış</a>
  </div>
</div>

<div class="container">

  <div class="page-hero">
    <h1>Kalori Takibi</h1>
    <p>Günlük aldığın kalorileri öğün bazlı kaydet, hedefini izle ve beslenme düzenini daha kontrollü şekilde yönet.</p>
  </div>

  <div class="card">
    <h2>Bugünkü Durum</h2>
    <div class="big"><?php echo $todayCalories; ?> kcal</div>
    <p class="progress-label">
      <?php if ($overTarget): ?>
        Hedefi <?php echo abs($remaining); ?> kcal aştın.
      <?php else: ?>
        Kalan: <?php echo $remaining; ?> kcal
      <?php endif; ?>
    </p>

    <div class="progress-wrap">
      <div class="progress-bar"></div>
    </div>
  </div>

  <div class="grid">
    <div class="stat">
      <p>Bugün</p>
      <strong><?php echo $todayCalories; ?></strong>
    </div>

    <div class="stat">
      <p>Hafta</p>
      <strong><?php echo $weekCalories; ?></strong>
    </div>

    <div class="stat">
      <p>Ay</p>
      <strong><?php echo $monthCalories; ?></strong>
    </div>

    <div class="stat">
      <p>Hedef</p>
      <strong><?php echo $targetCalorie; ?></strong>
    </div>
  </div>

  <div class="two-col" style="margin-top:20px;">
    <div>
      <div class="card">
        <h2>Kalori Ekle</h2>

        <?php if ($message): ?>
          <div class="result-box <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="action" value="add_calorie">

          <div class="form-group">
            <label>Tarih</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($today); ?>">
          </div>

          <div class="form-group">
            <label>Öğün Türü</label>
            <select name="meal_type">
              <option value="Kahvaltı">Kahvaltı</option>
              <option value="Ara Öğün">Ara Öğün</option>
              <option value="Öğle">Öğle</option>
              <option value="Akşam">Akşam</option>
              <option value="Gece">Gece</option>
              <option value="Diğer">Diğer</option>
            </select>
          </div>

          <div class="form-group">
            <label>Kalori</label>
            <input type="number" name="calorie" placeholder="Örn: 500">
          </div>

          <div class="form-group">
            <label>Not</label>
            <textarea name="note" placeholder="Örn: Tavuk pilav, ayran..."></textarea>
          </div>

          <button class="btn primary" type="submit">Kaydet</button>
        </form>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Bugünkü Öğün Dağılımı</h2>

        <?php if ($mealBreakdown): ?>
          <?php foreach ($mealBreakdown as $meal): ?>
            <div class="meal-row">
              <span><?php echo htmlspecialchars($meal["meal_type"]); ?></span>
              <strong><?php echo (int)$meal["total"]; ?> kcal</strong>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="meal-row">
            <span>Bugün henüz kayıt yok.</span>
            <strong>0 kcal</strong>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Hedef Özeti</h2>
        <div class="meal-row">
          <span>Günlük hedef</span>
          <strong><?php echo $targetCalorie; ?> kcal</strong>
        </div>
        <div class="meal-row">
          <span>Bugün alınan</span>
          <strong><?php echo $todayCalories; ?> kcal</strong>
        </div>
        <div class="meal-row">
          <span>Durum</span>
          <strong><?php echo $overTarget ? "Aşıldı" : "Kontrollü"; ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Son Kayıtlar</h2>
    <div id="history">
      <?php if ($historyRows): ?>
        <?php foreach ($historyRows as $row): ?>
          <div class="history-item">
            <div class="history-left">
              <div><strong><?php echo htmlspecialchars($row["log_date"]); ?></strong></div>
              <div>
                <span class="badge"><?php echo htmlspecialchars($row["meal_type"] ?: "Diğer"); ?></span>
                <?php if (!empty($row["note"])): ?>
                  <span style="margin-left:8px;"><?php echo htmlspecialchars($row["note"]); ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="history-right">
              <strong style="color:#4ade80;"><?php echo (int)$row["calories"]; ?> kcal</strong>
              <form method="POST" onsubmit="return confirm('Bu kayıt silinsin mi?');">
                <input type="hidden" name="action" value="delete_calorie">
                <input type="hidden" name="delete_id" value="<?php echo (int)$row["id"]; ?>">
                <button type="submit" class="mini-delete">Sil</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="history-item">
          <div class="history-left">Henüz kayıt yok.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

</body>
</html>
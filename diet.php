<?php
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "info";

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS water_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            log_date DATE NOT NULL,
            glass_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_day (user_id, log_date)
        )
    ");
} catch (PDOException $e) {
    die("Tablo oluşturma hatası: " . $e->getMessage());
}

function calculateDietData($height, $weight, $age, $gender, $goal) {
    if ($height <= 0 || $weight <= 0 || $age <= 0) {
        return null;
    }

    if ($gender === "female") {
        $bmr = 10 * $weight + 6.25 * $height - 5 * $age - 161;
    } else {
        $bmr = 10 * $weight + 6.25 * $height - 5 * $age + 5;
    }

    $dailyCalories = (int) round($bmr * 1.45);
    $modeText = "Dengeli";

    if ($goal === "lose") {
        $dailyCalories -= 400;
        $modeText = "Yağ Yakımı";
    } elseif ($goal === "gain") {
        $dailyCalories += 300;
        $modeText = "Kas Kazanımı";
    }

    $bmi = round($weight / (($height / 100) * ($height / 100)), 1);

    if ($bmi < 18.5) {
        $bmiText = "Zayıf";
    } elseif ($bmi < 25) {
        $bmiText = "Normal";
    } elseif ($bmi < 30) {
        $bmiText = "Fazla kilolu";
    } else {
        $bmiText = "Obezite düzeyi";
    }

    if ($goal === "lose") {
        $advice = "Şekerli gıdaları azalt, protein ve su tüketimini artır.";
    } elseif ($goal === "gain") {
        $advice = "Düzenli protein, kompleks karbonhidrat ve kuvvet antrenmanı ekle.";
    } else {
        $advice = "Dengeli öğünler ve düzenli hareket ile mevcut formunu koru.";
    }

    return [
        "daily_calorie" => $dailyCalories,
        "diet_mode" => $modeText,
        "bmi" => $bmi,
        "bmi_text" => $bmiText,
        "advice" => $advice
    ];
}

function columnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

$hasAge = columnExists($pdo, "users", "age");
$hasGender = columnExists($pdo, "users", "gender");

try {
    $selectFields = "u.name, u.email, u.height, u.weight";
    $selectFields .= $hasAge ? ", u.age" : ", NULL AS age";
    $selectFields .= $hasGender ? ", u.gender" : ", NULL AS gender";
    $selectFields .= ", dp.goal, dp.daily_calorie, dp.diet_mode, dp.bmi, dp.bmi_text";

    $stmt = $pdo->prepare("
        SELECT $selectFields
        FROM users u
        LEFT JOIN user_diet dp ON u.id = dp.user_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Diyet veri okuma hatası: " . $e->getMessage());
}

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$height = $user["height"] !== null ? (int)$user["height"] : "";
$weight = $user["weight"] !== null ? (float)$user["weight"] : "";
$age = isset($user["age"]) && $user["age"] !== null ? (int)$user["age"] : "";
$genderValue = $user["gender"] ?? "Erkek";
$gender = $genderValue === "Kadın" ? "female" : "male";
$goal = $user["goal"] ?: "maintain";

$dailyCalorie = $user["daily_calorie"] ?: 2200;
$dietMode = $user["diet_mode"] ?: "Dengeli";
$bmi = $user["bmi"] ?: "";
$bmiText = $user["bmi_text"] ?: "";

$today = date("Y-m-d");

$waterStmt = $pdo->prepare("SELECT glass_count FROM water_logs WHERE user_id = ? AND log_date = ?");
$waterStmt->execute([$user_id, $today]);
$waterRow = $waterStmt->fetch(PDO::FETCH_ASSOC);
$waterCount = $waterRow ? (int)$waterRow["glass_count"] : 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "save_diet") {
        $height = (int)($_POST["height"] ?? 0);
        $weight = (float)($_POST["weight"] ?? 0);
        $age = (int)($_POST["age"] ?? 0);
        $gender = $_POST["gender"] ?? "male";
        $goal = $_POST["goal"] ?? "maintain";

        $dietData = calculateDietData($height, $weight, $age, $gender, $goal);

        if (!$dietData) {
            $message = "Lütfen boy, kilo ve yaş bilgilerini eksiksiz gir.";
            $messageType = "error";
        } else {
            try {
                $pdo->beginTransaction();

                if ($hasAge && $hasGender) {
                    $dbGender = $gender === "female" ? "Kadın" : "Erkek";

                    $updateUser = $pdo->prepare("
                        UPDATE users
                        SET height = ?, weight = ?, age = ?, gender = ?
                        WHERE id = ?
                    ");
                    $updateUser->execute([
                        $height,
                        $weight,
                        $age,
                        $dbGender,
                        $user_id
                    ]);
                } elseif ($hasAge) {
                    $updateUser = $pdo->prepare("
                        UPDATE users
                        SET height = ?, weight = ?, age = ?
                        WHERE id = ?
                    ");
                    $updateUser->execute([
                        $height,
                        $weight,
                        $age,
                        $user_id
                    ]);
                } elseif ($hasGender) {
                    $dbGender = $gender === "female" ? "Kadın" : "Erkek";

                    $updateUser = $pdo->prepare("
                        UPDATE users
                        SET height = ?, weight = ?, gender = ?
                        WHERE id = ?
                    ");
                    $updateUser->execute([
                        $height,
                        $weight,
                        $dbGender,
                        $user_id
                    ]);
                } else {
                    $updateUser = $pdo->prepare("
                        UPDATE users
                        SET height = ?, weight = ?
                        WHERE id = ?
                    ");
                    $updateUser->execute([
                        $height,
                        $weight,
                        $user_id
                    ]);
                }

                $checkDiet = $pdo->prepare("SELECT id FROM user_diet WHERE user_id = ?");
                $checkDiet->execute([$user_id]);
                $dietExists = $checkDiet->fetch(PDO::FETCH_ASSOC);

                if ($dietExists) {
                    $updateDiet = $pdo->prepare("
                        UPDATE user_diet
                        SET goal = ?, daily_calorie = ?, diet_mode = ?, bmi = ?, bmi_text = ?
                        WHERE user_id = ?
                    ");
                    $updateDiet->execute([
                        $goal,
                        $dietData["daily_calorie"],
                        $dietData["diet_mode"],
                        $dietData["bmi"],
                        $dietData["bmi_text"],
                        $user_id
                    ]);
                } else {
                    $insertDiet = $pdo->prepare("
                        INSERT INTO user_diet (user_id, goal, daily_calorie, diet_mode, bmi, bmi_text)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insertDiet->execute([
                        $user_id,
                        $goal,
                        $dietData["daily_calorie"],
                        $dietData["diet_mode"],
                        $dietData["bmi"],
                        $dietData["bmi_text"]
                    ]);
                }

                $pdo->commit();

                $dailyCalorie = $dietData["daily_calorie"];
                $dietMode = $dietData["diet_mode"];
                $bmi = $dietData["bmi"];
                $bmiText = $dietData["bmi_text"];

                $message = "Diyet bilgileri başarıyla kaydedildi.";
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

    if ($action === "water_change") {
        $change = (int)($_POST["change"] ?? 0);

        $waterStmt = $pdo->prepare("SELECT glass_count FROM water_logs WHERE user_id = ? AND log_date = ?");
        $waterStmt->execute([$user_id, $today]);
        $waterRow = $waterStmt->fetch(PDO::FETCH_ASSOC);
        $currentWater = $waterRow ? (int)$waterRow["glass_count"] : 0;

        if ($change === 999) {
            $newWater = 0;
        } else {
            $newWater = $currentWater + $change;
            if ($newWater < 0) $newWater = 0;
            if ($newWater > 8) $newWater = 8;
        }

        $checkWater = $pdo->prepare("SELECT id FROM water_logs WHERE user_id = ? AND log_date = ?");
        $checkWater->execute([$user_id, $today]);
        $waterExists = $checkWater->fetch(PDO::FETCH_ASSOC);

        if ($waterExists) {
            $updateWater = $pdo->prepare("
                UPDATE water_logs
                SET glass_count = ?
                WHERE user_id = ? AND log_date = ?
            ");
            $updateWater->execute([$newWater, $user_id, $today]);
        } else {
            $insertWater = $pdo->prepare("
                INSERT INTO water_logs (user_id, log_date, glass_count)
                VALUES (?, ?, ?)
            ");
            $insertWater->execute([$user_id, $today, $newWater]);
        }

        $waterCount = $newWater;
        $message = "Su takibi güncellendi.";
        $messageType = "success";
    }
}

$adviceText = "Bilgilerini gir ve sana uygun günlük kalori tahminini görelim.";
if ($height !== "" && $weight !== "" && $age !== "") {
    $dietData = calculateDietData((int)$height, (float)$weight, (int)$age, $gender, $goal);
    if ($dietData) {
        $adviceText = "<strong>Günlük önerilen kalori:</strong> " . $dietData["daily_calorie"] . " kcal<br>
        <strong>Beslenme modu:</strong> " . htmlspecialchars($dietData["diet_mode"]) . "<br>
        <strong>Öneri:</strong> " . htmlspecialchars($dietData["advice"]);
        $dailyCalorie = $dietData["daily_calorie"];
        $dietMode = $dietData["diet_mode"];
        $bmi = $dietData["bmi"];
        $bmiText = $dietData["bmi_text"];
    }
}

$bmiBoxText = "Boy ve kilo bilgisi girildiğinde burada BMI sonucu da gösterilir.";
if ($bmi !== "" && $bmiText !== "") {
    $bmiBoxText = "<strong>BMI:</strong> " . htmlspecialchars((string)$bmi) . "<br><strong>Durum:</strong> " . htmlspecialchars($bmiText);
}

$waterPercent = ($waterCount / 8) * 100;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - Diyet Planı</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: Arial, Helvetica, sans-serif;
    }

    body {
      background: linear-gradient(135deg, #0f172a, #1e293b);
      color: #fff;
      min-height: 100vh;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    .topbar {
      width: 100%;
      background: rgba(15, 23, 42, 0.95);
      border-bottom: 1px solid rgba(255,255,255,0.08);
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 18px 32px;
      position: sticky;
      top: 0;
      z-index: 100;
      backdrop-filter: blur(12px);
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 24px;
      font-weight: bold;
      color: #22c55e;
    }

    .logo-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      color: white;
      box-shadow: 0 10px 24px rgba(34, 197, 94, 0.25);
    }

    .nav-links {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .nav-links a {
      padding: 10px 16px;
      border-radius: 12px;
      background: rgba(255,255,255,0.06);
      transition: 0.25s ease;
      font-size: 14px;
    }

    .nav-links a:hover,
    .nav-links a.active {
      background: #22c55e;
      color: #0f172a;
      font-weight: bold;
    }

    .container {
      max-width: 1250px;
      margin: 32px auto;
      padding: 0 20px 40px;
    }

    .hero {
      background: linear-gradient(135deg, rgba(34,197,94,0.18), rgba(15,23,42,0.85));
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      padding: 32px;
      box-shadow: 0 20px 45px rgba(0,0,0,0.28);
      margin-bottom: 26px;
    }

    .hero h1 {
      font-size: 34px;
      margin-bottom: 10px;
    }

    .hero p {
      color: #cbd5e1;
      font-size: 16px;
      line-height: 1.6;
      max-width: 800px;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-top: 24px;
    }

    .stat-box {
      background: rgba(255,255,255,0.06);
      border-radius: 18px;
      padding: 20px;
      border: 1px solid rgba(255,255,255,0.06);
    }

    .stat-box h3 {
      font-size: 14px;
      color: #cbd5e1;
      margin-bottom: 8px;
      font-weight: normal;
    }

    .stat-box strong {
      font-size: 28px;
      color: #22c55e;
    }

    .grid {
      display: grid;
      grid-template-columns: 1.3fr 1fr;
      gap: 22px;
      margin-top: 22px;
    }

    .card {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 22px;
      padding: 24px;
      box-shadow: 0 16px 35px rgba(0,0,0,0.18);
    }

    .card h2 {
      font-size: 22px;
      margin-bottom: 18px;
      color: #22c55e;
    }

    .meal-list {
      display: grid;
      gap: 16px;
    }

    .meal-item {
      background: rgba(15,23,42,0.6);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 18px;
      padding: 18px;
    }

    .meal-item h3 {
      font-size: 18px;
      margin-bottom: 10px;
    }

    .meal-item ul {
      padding-left: 18px;
      color: #dbeafe;
      line-height: 1.8;
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
    .form-group select {
      width: 100%;
      padding: 13px 14px;
      border-radius: 14px;
      border: none;
      outline: none;
      background: rgba(255,255,255,0.1);
      color: white;
      font-size: 15px;
    }

    .form-group input::placeholder {
      color: #cbd5e1;
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
      margin-top: 6px;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(34, 197, 94, 0.28);
    }

    .result-box {
      margin-top: 18px;
      background: rgba(15,23,42,0.65);
      border-radius: 16px;
      padding: 16px;
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

    .water-box {
      margin-top: 22px;
    }

    .water-actions {
      display: flex;
      gap: 10px;
      margin-top: 12px;
    }

    .water-actions form {
      flex: 1;
    }

    .water-actions button {
      width: 100%;
      padding: 12px;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      font-weight: bold;
      background: rgba(255,255,255,0.1);
      color: white;
      transition: 0.2s;
    }

    .water-actions button:hover {
      background: #22c55e;
      color: #0f172a;
    }

    .progress-wrap {
      width: 100%;
      height: 18px;
      background: rgba(255,255,255,0.08);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 12px;
    }

    .progress-bar {
      height: 100%;
      width: <?php echo $waterPercent; ?>%;
      background: linear-gradient(135deg, #22c55e, #4ade80);
      transition: width 0.35s ease;
    }

    .tips {
      display: grid;
      gap: 14px;
      margin-top: 20px;
    }

    .tip {
      background: rgba(15,23,42,0.55);
      padding: 16px;
      border-radius: 16px;
      border-left: 4px solid #22c55e;
      color: #dbeafe;
      line-height: 1.6;
    }

    .footer-note {
      text-align: center;
      color: #94a3b8;
      margin-top: 28px;
      font-size: 14px;
    }

    @media (max-width: 980px) {
      .grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .topbar {
        flex-direction: column;
        gap: 14px;
        align-items: flex-start;
      }

      .hero h1 {
        font-size: 28px;
      }

      .nav-links {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="logo">
      <div class="logo-icon">🥗</div>
      <span>FORMIX</span>
    </div>

    <div class="nav-links">
      <a href="home.php">Ana Sayfa</a>
            <a href="diet.php" class="active">Diyet</a>
                        <a href="workout.php">Antrenman</a>
                                                <a href="goal.php">Hedef Sistemi</a>



      <a href="profile.php">Profil</a>

      <a href="calorie.php">Kalori</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container">
    <section class="hero">
      <h1>Kişisel Diyet ve Beslenme Planın</h1>
      <p>
        FORMIX ile günlük kalorini kontrol et, su takibini yap, öğünlerini düzenle
        ve hedeflerine daha hızlı ulaş. Sağlıklı kilo verme, kas kazanımı ve dengeli
        beslenme için burada tüm temel araçların hazır.
      </p>

      <div class="stats">
        <div class="stat-box">
          <h3>Günlük Hedef Kalori</h3>
          <strong><?php echo htmlspecialchars((string)$dailyCalorie); ?></strong>
        </div>
        <div class="stat-box">
          <h3>Su Hedefi</h3>
          <strong>2.5L</strong>
        </div>
        <div class="stat-box">
          <h3>Öğün Sayısı</h3>
          <strong>4</strong>
        </div>
        <div class="stat-box">
          <h3>Beslenme Modu</h3>
          <strong><?php echo htmlspecialchars($dietMode); ?></strong>
        </div>
      </div>
    </section>

    <div class="grid">
      <div>
        <div class="card">
          <h2>Örnek Günlük Öğün Planı</h2>

          <div class="meal-list">
            <div class="meal-item">
              <h3>🌅 Kahvaltı</h3>
              <ul>
                <li>2 haşlanmış yumurta</li>
                <li>1 dilim tam buğday ekmeği</li>
                <li>Domates, salatalık, yeşillik</li>
                <li>Şekersiz çay veya kahve</li>
              </ul>
            </div>

            <div class="meal-item">
              <h3>🍎 Ara Öğün</h3>
              <ul>
                <li>1 elma veya muz</li>
                <li>10 adet çiğ badem</li>
              </ul>
            </div>

            <div class="meal-item">
              <h3>🍗 Öğle Yemeği</h3>
              <ul>
                <li>150-200 gr tavuk / hindi / et</li>
                <li>4-5 kaşık bulgur veya pirinç</li>
                <li>Büyük yeşil salata</li>
              </ul>
            </div>

            <div class="meal-item">
              <h3>🌙 Akşam Yemeği</h3>
              <ul>
                <li>Izgara protein kaynağı</li>
                <li>Haşlanmış sebze veya zeytinyağlı</li>
                <li>Yoğurt veya ayran</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top: 22px;">
          <h2>Beslenme Tavsiyeleri</h2>
          <div class="tips">
            <div class="tip">Şekerli içecekleri azaltıp su tüketimini artırmak yağ yakımını destekler.</div>
            <div class="tip">Her öğünde protein bulundurmak daha uzun süre tok kalmana yardım eder.</div>
            <div class="tip">Gece geç saatlerde yüksek kalorili atıştırmalıkları azaltmak önemlidir.</div>
            <div class="tip">Haftalık ilerleme takibi yaparak kalori ve kilo değişimini gözlemleyebilirsin.</div>
          </div>
        </div>
      </div>

      <div>
        <div class="card">
          <h2>Kalori Hesaplayıcı</h2>

          <?php if ($message): ?>
            <div class="result-box <?php echo $messageType === "success" ? "success" : ($messageType === "error" ? "error" : ""); ?>">
              <?php echo htmlspecialchars($message); ?>
            </div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="action" value="save_diet">

            <div class="form-group">
              <label>Boy (cm)</label>
              <input type="number" name="height" value="<?php echo htmlspecialchars((string)$height); ?>" placeholder="Örn: 178" />
            </div>

            <div class="form-group">
              <label>Kilo (kg)</label>
              <input type="number" step="0.1" name="weight" value="<?php echo htmlspecialchars((string)$weight); ?>" placeholder="Örn: 78" />
            </div>

            <div class="form-group">
              <label>Yaş</label>
              <input type="number" name="age" value="<?php echo htmlspecialchars((string)$age); ?>" placeholder="Örn: 24" />
            </div>

            <div class="form-group">
              <label>Cinsiyet</label>
              <select name="gender">
                <option value="male" <?php echo $gender === "male" ? "selected" : ""; ?>>Erkek</option>
                <option value="female" <?php echo $gender === "female" ? "selected" : ""; ?>>Kadın</option>
              </select>
            </div>

            <div class="form-group">
              <label>Hedef</label>
              <select name="goal">
                <option value="lose" <?php echo $goal === "lose" ? "selected" : ""; ?>>Kilo Vermek</option>
                <option value="maintain" <?php echo $goal === "maintain" ? "selected" : ""; ?>>Kiloyu Korumak</option>
                <option value="gain" <?php echo $goal === "gain" ? "selected" : ""; ?>>Kilo Almak / Kas Kazanmak</option>
              </select>
            </div>

            <button class="btn" type="submit">Hesapla ve Kaydet</button>
          </form>

          <div class="result-box">
            <?php echo $adviceText; ?>
          </div>

          <div class="water-box">
            <h2 style="margin-top: 22px;">Su Takibi</h2>
            <div class="result-box">
              Bugün içilen su: <strong><?php echo (int)$waterCount; ?></strong> / 8 bardak
              <div class="progress-wrap">
                <div class="progress-bar"></div>
              </div>

              <div class="water-actions">
                <form method="POST">
                  <input type="hidden" name="action" value="water_change">
                  <input type="hidden" name="change" value="1">
                  <button type="submit">+1 Bardak</button>
                </form>

                <form method="POST">
                  <input type="hidden" name="action" value="water_change">
                  <input type="hidden" name="change" value="-1">
                  <button type="submit">-1 Bardak</button>
                </form>

                <form method="POST">
                  <input type="hidden" name="action" value="water_change">
                  <input type="hidden" name="change" value="999">
                  <button type="submit">Sıfırla</button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top: 22px;">
          <h2>Vücut Kitle İndeksi</h2>
          <div class="result-box">
            <?php echo $bmiBoxText; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="footer-note">
      FORMIX •
    </div>
  </div>
</body>
</html>
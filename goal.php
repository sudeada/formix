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
    $checkCol = $pdo->prepare("SHOW COLUMNS FROM user_profiles LIKE 'goal_duration'");
    $checkCol->execute();
    if (!$checkCol->fetch()) {
        $pdo->exec("ALTER TABLE user_profiles ADD COLUMN goal_duration VARCHAR(30) DEFAULT NULL");
    }
} catch (PDOException $e) {
}

function getActivityMultiplier(string $activity): float {
    if ($activity === "Düşük") return 1.2;
    if ($activity === "Orta") return 1.45;
    if ($activity === "Yüksek") return 1.7;
    return 1.3;
}

function calculateBMR($weight, $height, $age, $gender): float {
    if (!$weight || !$height || !$age || !$gender) return 0;

    if ($gender === "Erkek") {
        return 10 * $weight + 6.25 * $height - 5 * $age + 5;
    }
    return 10 * $weight + 6.25 * $height - 5 * $age - 161;
}

function calculateSmartRecommendations($goalType, $weight, $targetWeight, $height, $age, $gender, $activity): array {
    $bmr = calculateBMR($weight, $height, $age, $gender);
    $multiplier = getActivityMultiplier($activity);
    $maintainCalories = $bmr > 0 ? round($bmr * $multiplier) : 0;

    $suggestedCalories = $maintainCalories;
    $suggestedWater = $weight ? round($weight * 35) : 0;
    $weeklyTempo = "-";
    $recommendationType = "-";
    $advice = "Daha net öneri için tüm bilgileri doldur.";

    if (!$goalType || !$weight || !$height || !$age || !$gender || !$activity) {
        return [
            "calories_value" => null,
            "calories" => "-",
            "water" => "-",
            "tempo" => "-",
            "type" => "-",
            "advice" => $advice
        ];
    }

    if ($goalType === "Kilo Vermek") {
        $suggestedCalories = max(1200, $maintainCalories - 400);
        $suggestedWater += 300;
        $weeklyTempo = "Haftada 0.3 - 0.8 kg";
        $recommendationType = "Yağ Yakımı";
        $diff = $weight - $targetWeight;
        $advice = $diff > 0
            ? "Hedef kilona ulaşmak için günlük yaklaşık {$suggestedCalories} kcal ve en az {$suggestedWater} ml su iyi bir başlangıç olur. Dengeli açık ver, aşırı düşme."
            : "Zaten hedef kiloda veya altındasın. Form koruma moduna geçmen daha mantıklı olabilir.";
    } elseif ($goalType === "Kas Kazanmak") {
        $suggestedCalories = $maintainCalories + 250;
        $suggestedWater += 500;
        $weeklyTempo = "Haftada 0.2 - 0.5 kg";
        $recommendationType = "Kas Gelişimi";
        $diff = $targetWeight - $weight;
        $advice = $diff > 0
            ? "Kas kazanımı için günlük yaklaşık {$suggestedCalories} kcal ve {$suggestedWater} ml su uygundur. Protein ve kuvvet antrenmanı ile destekle."
            : "Hedef kiloya ulaşmış görünüyorsun. Şimdi form koruma veya kalite artırma dönemine geçebilirsin.";
    } else {
        $suggestedCalories = $maintainCalories;
        $suggestedWater += 200;
        $weeklyTempo = "Kilo dengesi korunur";
        $recommendationType = "Koruma";
        $advice = "Mevcut formunu korumak için günlük yaklaşık {$suggestedCalories} kcal ve {$suggestedWater} ml su yeterli olur. Düzenli uyku ve hareketi aksatma.";
    }

    return [
        "calories_value" => $suggestedCalories,
        "calories" => $suggestedCalories . " kcal",
        "water" => $suggestedWater . " ml",
        "tempo" => $weeklyTempo,
        "type" => $recommendationType,
        "advice" => $advice
    ];
}

$stmt = $pdo->prepare("
    SELECT 
        u.name,
        u.height,
        u.weight,
        u.age,
        u.gender,
        u.activity,
        up.goal_type,
        up.goal_duration,
        up.target_weight,
        up.note,
        d.daily_calorie
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN user_diet d ON u.id = d.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$goalType = $data["goal_type"] ?? "";
$goalDuration = $data["goal_duration"] ?? "";
$currentWeight = $data["weight"] ?? "";
$targetWeight = $data["target_weight"] ?? "";
$height = $data["height"] ?? "";
$age = $data["age"] ?? "";
$gender = $data["gender"] ?? "";
$activity = $data["activity"] ?? "";
$goalNote = $data["note"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $goalType = trim($_POST["goal_type"] ?? "");
    $goalDuration = trim($_POST["goal_duration"] ?? "");
    $currentWeight = trim($_POST["current_weight"] ?? "");
    $targetWeight = trim($_POST["target_weight"] ?? "");
    $height = trim($_POST["height"] ?? "");
    $age = trim($_POST["age"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $activity = trim($_POST["activity"] ?? "");
    $goalNote = trim($_POST["goal_note"] ?? "");

    if ($goalType === "" || $currentWeight === "" || $targetWeight === "") {
        $message = "Lütfen hedef türü, mevcut kilo ve hedef kilo alanlarını doldur.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $updateUser = $pdo->prepare("
                UPDATE users
                SET weight = ?, height = ?, age = ?, gender = ?, activity = ?
                WHERE id = ?
            ");
            $updateUser->execute([
                $currentWeight !== "" ? (float)$currentWeight : null,
                $height !== "" ? (int)$height : null,
                $age !== "" ? (int)$age : null,
                $gender !== "" ? $gender : null,
                $activity !== "" ? $activity : null,
                $user_id
            ]);

            $checkProfile = $pdo->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
            $checkProfile->execute([$user_id]);
            $profileExists = $checkProfile->fetch(PDO::FETCH_ASSOC);

            if ($profileExists) {
                $updateProfile = $pdo->prepare("
                    UPDATE user_profiles
                    SET goal_type = ?, goal_duration = ?, target_weight = ?, note = ?, phone = phone
                    WHERE user_id = ?
                ");
                $updateProfile->execute([
                    $goalType !== "" ? $goalType : null,
                    $goalDuration !== "" ? $goalDuration : null,
                    $targetWeight !== "" ? (float)$targetWeight : null,
                    $goalNote !== "" ? $goalNote : null,
                    $user_id
                ]);
            } else {
                $insertProfile = $pdo->prepare("
                    INSERT INTO user_profiles (user_id, target_weight, goal_type, phone, note, goal_duration)
                    VALUES (?, ?, ?, NULL, ?, ?)
                ");
                $insertProfile->execute([
                    $user_id,
                    $targetWeight !== "" ? (float)$targetWeight : null,
                    $goalType !== "" ? $goalType : null,
                    $goalNote !== "" ? $goalNote : null,
                    $goalDuration !== "" ? $goalDuration : null
                ]);
            }

            $smart = calculateSmartRecommendations(
                $goalType,
                (float)$currentWeight,
                (float)$targetWeight,
                (int)$height,
                (int)$age,
                $gender,
                $activity
            );

            if (!empty($smart["calories_value"])) {
                $checkDiet = $pdo->prepare("SELECT id FROM user_diet WHERE user_id = ?");
                $checkDiet->execute([$user_id]);
                $dietExists = $checkDiet->fetch(PDO::FETCH_ASSOC);

                if ($dietExists) {
                    $updateDiet = $pdo->prepare("
                        UPDATE user_diet
                        SET daily_calorie = ?
                        WHERE user_id = ?
                    ");
                    $updateDiet->execute([$smart["calories_value"], $user_id]);
                } else {
                    $insertDiet = $pdo->prepare("
                        INSERT INTO user_diet (user_id, daily_calorie)
                        VALUES (?, ?)
                    ");
                    $insertDiet->execute([$user_id, $smart["calories_value"]]);
                }
            }

            $pdo->commit();
            $message = "Hedefin kaydedildi.";
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

$stmt = $pdo->prepare("
    SELECT 
        u.name,
        u.height,
        u.weight,
        u.age,
        u.gender,
        u.activity,
        up.goal_type,
        up.goal_duration,
        up.target_weight,
        up.note,
        d.daily_calorie
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN user_diet d ON u.id = d.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

$goalType = $data["goal_type"] ?? "";
$goalDuration = $data["goal_duration"] ?? "";
$currentWeight = $data["weight"] ?? "";
$targetWeight = $data["target_weight"] ?? "";
$height = $data["height"] ?? "";
$age = $data["age"] ?? "";
$gender = $data["gender"] ?? "";
$activity = $data["activity"] ?? "";
$goalNote = $data["note"] ?? "";

$current = (float)($currentWeight ?: 0);
$target = (float)($targetWeight ?: 0);

$remainingText = "-";
$progressText = "Hedef oluşturduğunda burada ilerleme bilgisi görünecek.";
$progress = 0;
$tipsHtml = '
  <div class="tip-item">Hedefini net belirlemek motivasyonu artırır.</div>
  <div class="tip-item">Haftalık küçük ilerlemeler uzun vadede büyük fark yaratır.</div>
  <div class="tip-item">Hedefine uygun su ve kalori düzeni çok önemlidir.</div>
';

if ($current && $target && $goalType) {
    if ($goalType === "Kilo Vermek") {
        $diff = $current - $target;
        $remainingText = $diff > 0 ? number_format($diff, 1) . " kg" : "Tamam";
        if ($diff <= 0) {
            $progress = 100;
            $progressText = "Tebrikler, hedef kilona ulaştın.";
        } else {
            $progress = max(10, min(90, 100 - $diff * 8));
            $progressText = "{$target} kg hedefine ulaşmak için yaklaşık " . number_format($diff, 1) . " kg kaldı. Süre hedefin: {$goalDuration}.";
        }

        $tipsHtml = '
          <div class="tip-item">Kilo verme hedefinde su ve kalori takibini düzenli yap.</div>
          <div class="tip-item">Haftalık küçük düşüşler sağlıklı ilerleme sağlar.</div>
          <div class="tip-item">Cardio ve dengeli beslenme planı hedefini hızlandırabilir.</div>
        ';
    } elseif ($goalType === "Kas Kazanmak") {
        $diff = $target - $current;
        $remainingText = $diff > 0 ? number_format($diff, 1) . " kg" : "Tamam";
        if ($diff <= 0) {
            $progress = 100;
            $progressText = "Hedef seviyeye ulaştın veya geçtin.";
        } else {
            $progress = max(10, min(90, 100 - $diff * 8));
            $progressText = "{$target} kg hedefine ulaşmak için yaklaşık " . number_format($diff, 1) . " kg artış gerekiyor. Süre hedefin: {$goalDuration}.";
        }

        $tipsHtml = '
          <div class="tip-item">Kas kazanımında ağırlık antrenmanı ve protein çok önemlidir.</div>
          <div class="tip-item">Yeterli uyku ve toparlanma hedefe yaklaşmayı hızlandırır.</div>
          <div class="tip-item">Düzenli ama kontrollü kalori fazlası tercih et.</div>
        ';
    } else {
        $remainingText = "Dengede";
        $progress = 100;
        $progressText = "Form koruma hedefi aktif. Mevcut kilonu korumaya odaklan. Süre hedefin: {$goalDuration}.";
        $tipsHtml = '
          <div class="tip-item">Form koruma hedefinde düzenli su, uyku ve antrenman önemli.</div>
          <div class="tip-item">Kilo dalgalanmalarını haftalık takip etmek yeterlidir.</div>
          <div class="tip-item">Sürdürülebilir bir düzen kurmak en iyi sonuçtur.</div>
        ';
    }
}

$smart = calculateSmartRecommendations(
    $goalType,
    $current,
    $target,
    (int)($height ?: 0),
    (int)($age ?: 0),
    $gender,
    $activity
);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - Hedef Sistemi</title>
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

    .goal-hero {
      padding: 32px;
      border-radius: 26px;
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: 0 20px 45px rgba(0,0,0,0.22);
      margin-bottom: 24px;
    }

    .goal-hero h1 {
      font-size: 36px;
      margin-bottom: 10px;
    }

    .goal-hero p {
      color: #cbd5e1;
      line-height: 1.7;
      max-width: 850px;
    }

    .goal-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 22px;
      margin-top: 24px;
    }

    .goal-card {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      box-shadow: 0 16px 35px rgba(0,0,0,0.20);
      padding: 24px;
    }

    .goal-card h2 {
      margin-bottom: 16px;
      font-size: 24px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .form-group {
      margin-bottom: 14px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #e2e8f0;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 13px 14px;
      border-radius: 12px;
      border: none;
      outline: none;
      background: rgba(255,255,255,0.12);
      color: white;
      font-size: 15px;
    }

    .form-group textarea {
      min-height: 110px;
      resize: vertical;
    }

    .form-group option {
      color: black;
    }

    .action-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 8px;
    }

    .summary-box {
      margin-top: 18px;
      padding: 18px;
      border-radius: 18px;
      background: rgba(15,23,42,0.55);
      border: 1px solid rgba(255,255,255,0.06);
      color: #dbeafe;
      line-height: 1.8;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .mini-stat {
      background: rgba(15,23,42,0.48);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 18px;
      padding: 18px;
    }

    .mini-stat h4 {
      color: #cbd5e1;
      font-size: 14px;
      font-weight: normal;
      margin-bottom: 8px;
    }

    .mini-stat strong {
      color: #4ade80;
      font-size: 26px;
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
      transition: width 0.35s ease;
    }

    .goal-tips {
      display: grid;
      gap: 12px;
      margin-top: 18px;
    }

    .tip-item {
      background: rgba(255,255,255,0.05);
      border-left: 4px solid #22c55e;
      border-radius: 14px;
      padding: 14px 16px;
      color: #dbeafe;
      line-height: 1.6;
    }

    .recommend-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 16px;
    }

    .recommend-card {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 18px;
      padding: 16px;
    }

    .recommend-card h4 {
      color: #cbd5e1;
      font-size: 14px;
      font-weight: normal;
      margin-bottom: 8px;
    }

    .recommend-card strong {
      font-size: 24px;
      color: #4ade80;
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

    @media (max-width: 950px) {
      .goal-grid {
        grid-template-columns: 1fr;
      }

      .form-grid,
      .recommend-grid {
        grid-template-columns: 1fr;
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
      <a href="workout.php">Antrenman</a>
      <a href="goal.php" class="active">Hedef Sistemi</a>
      <a href="profile.php">Profil</a>
      <a href="calorie.php">Kalori</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container">
    <section class="goal-hero">
      <h1>Hedef Sistemi</h1>
      <p>
        Hedefini belirle, sana uygun kalori ve su önerisini otomatik hesapla,
        sağlıklı ve sürdürülebilir bir plan oluştur.
      </p>
    </section>

    <?php if ($message): ?>
      <div class="result-box <?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <div class="goal-grid">
      <div class="goal-card">
        <h2>Hedefini Oluştur</h2>

        <form method="POST">
          <div class="form-grid">
            <div class="form-group">
              <label>Hedef Türü</label>
              <select name="goal_type">
                <option value="">Seçiniz</option>
                <option value="Kilo Vermek" <?php echo $goalType === "Kilo Vermek" ? "selected" : ""; ?>>Kilo Vermek</option>
                <option value="Formu Korumak" <?php echo $goalType === "Formu Korumak" ? "selected" : ""; ?>>Formu Korumak</option>
                <option value="Kas Kazanmak" <?php echo $goalType === "Kas Kazanmak" ? "selected" : ""; ?>>Kas Kazanmak</option>
              </select>
            </div>

            <div class="form-group">
              <label>Hedef Süre</label>
              <select name="goal_duration">
                <option value="">Seçiniz</option>
                <option value="2 Hafta" <?php echo $goalDuration === "2 Hafta" ? "selected" : ""; ?>>2 Hafta</option>
                <option value="1 Ay" <?php echo $goalDuration === "1 Ay" ? "selected" : ""; ?>>1 Ay</option>
                <option value="2 Ay" <?php echo $goalDuration === "2 Ay" ? "selected" : ""; ?>>2 Ay</option>
                <option value="3 Ay" <?php echo $goalDuration === "3 Ay" ? "selected" : ""; ?>>3 Ay</option>
                <option value="6 Ay" <?php echo $goalDuration === "6 Ay" ? "selected" : ""; ?>>6 Ay</option>
              </select>
            </div>

            <div class="form-group">
              <label>Mevcut Kilo (kg)</label>
              <input type="number" step="0.1" name="current_weight" value="<?php echo htmlspecialchars((string)$currentWeight); ?>" placeholder="Örn: 78" />
            </div>

            <div class="form-group">
              <label>Hedef Kilo (kg)</label>
              <input type="number" step="0.1" name="target_weight" value="<?php echo htmlspecialchars((string)$targetWeight); ?>" placeholder="Örn: 72" />
            </div>

            <div class="form-group">
              <label>Boy (cm)</label>
              <input type="number" name="height" value="<?php echo htmlspecialchars((string)$height); ?>" placeholder="Örn: 178" />
            </div>

            <div class="form-group">
              <label>Yaş</label>
              <input type="number" name="age" value="<?php echo htmlspecialchars((string)$age); ?>" placeholder="Örn: 24" />
            </div>

            <div class="form-group">
              <label>Cinsiyet</label>
              <select name="gender">
                <option value="">Seçiniz</option>
                <option value="Erkek" <?php echo $gender === "Erkek" ? "selected" : ""; ?>>Erkek</option>
                <option value="Kadın" <?php echo $gender === "Kadın" ? "selected" : ""; ?>>Kadın</option>
              </select>
            </div>

            <div class="form-group">
              <label>Aktivite Seviyesi</label>
              <select name="activity">
                <option value="">Seçiniz</option>
                <option value="Düşük" <?php echo $activity === "Düşük" ? "selected" : ""; ?>>Düşük</option>
                <option value="Orta" <?php echo $activity === "Orta" ? "selected" : ""; ?>>Orta</option>
                <option value="Yüksek" <?php echo $activity === "Yüksek" ? "selected" : ""; ?>>Yüksek</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Motivasyon Notu</label>
            <textarea name="goal_note" placeholder="Örn: Yaz gelmeden daha fit olmak istiyorum."><?php echo htmlspecialchars($goalNote); ?></textarea>
          </div>

          <div class="action-row">
            <button class="btn primary" type="submit">Hedefi Kaydet</button>
          </div>
        </form>

        <div class="summary-box">
          <strong>Hedef Özeti</strong><br>
          Hedef Türü: <?php echo htmlspecialchars($goalType ?: "-"); ?><br>
          Mevcut Kilo: <?php echo $current ? htmlspecialchars((string)$current) . " kg" : "-"; ?><br>
          Hedef Kilo: <?php echo $target ? htmlspecialchars((string)$target) . " kg" : "-"; ?><br>
          Boy / Yaş: <?php echo $height ? htmlspecialchars((string)$height) . " cm" : "-"; ?> / <?php echo $age ? htmlspecialchars((string)$age) : "-"; ?><br>
          Cinsiyet: <?php echo htmlspecialchars($gender ?: "-"); ?><br>
          Aktivite: <?php echo htmlspecialchars($activity ?: "-"); ?><br>
          Hedef Süre: <?php echo htmlspecialchars($goalDuration ?: "-"); ?><br>
          Motivasyon Notu: <?php echo htmlspecialchars($goalNote ?: "Henüz not eklenmedi."); ?>
        </div>
      </div>

      <div class="goal-card">
        <h2>Hedef Özeti</h2>

        <div class="stats-grid">
          <div class="mini-stat">
            <h4>Mevcut Kilo</h4>
            <strong><?php echo $current ? htmlspecialchars((string)$current) . " kg" : "-"; ?></strong>
          </div>
          <div class="mini-stat">
            <h4>Hedef Kilo</h4>
            <strong><?php echo $target ? htmlspecialchars((string)$target) . " kg" : "-"; ?></strong>
          </div>
          <div class="mini-stat">
            <h4>Kalan Fark</h4>
            <strong><?php echo htmlspecialchars($remainingText); ?></strong>
          </div>
          <div class="mini-stat">
            <h4>Hedef Türü</h4>
            <strong><?php echo htmlspecialchars($goalType ?: "-"); ?></strong>
          </div>
        </div>

        <div class="summary-box">
          <strong>İlerleme Durumu</strong>
          <div class="progress-wrap">
            <div class="progress-bar"></div>
          </div>
          <div style="margin-top: 12px;">
            <?php echo htmlspecialchars($progressText); ?>
          </div>
        </div>

        <div class="summary-box">
          <strong>Akıllı Öneri Sistemi</strong>

          <div class="recommend-grid">
            <div class="recommend-card">
              <h4>Önerilen Günlük Kalori</h4>
              <strong><?php echo htmlspecialchars($smart["calories"]); ?></strong>
            </div>

            <div class="recommend-card">
              <h4>Önerilen Günlük Su</h4>
              <strong><?php echo htmlspecialchars($smart["water"]); ?></strong>
            </div>

            <div class="recommend-card">
              <h4>Haftalık Tempo</h4>
              <strong><?php echo htmlspecialchars($smart["tempo"]); ?></strong>
            </div>

            <div class="recommend-card">
              <h4>Öneri Tipi</h4>
              <strong><?php echo htmlspecialchars($smart["type"]); ?></strong>
            </div>
          </div>

          <div style="margin-top: 14px;">
            <?php echo htmlspecialchars($smart["advice"]); ?>
          </div>
        </div>

        <div class="goal-tips">
          <?php echo $tipsHtml; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
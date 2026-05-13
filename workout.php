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

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS workout_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            training_type VARCHAR(50) DEFAULT 'Fitness',
            level_name VARCHAR(30) DEFAULT 'Başlangıç',
            weekly_goal INT DEFAULT 4,
            duration_min INT DEFAULT 45,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS workout_checklists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            task_date DATE NOT NULL,
            isinma TINYINT(1) DEFAULT 0,
            anaantrenman TINYINT(1) DEFAULT 0,
            kardiyo TINYINT(1) DEFAULT 0,
            esneme TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_task_day (user_id, task_date)
        )
    ");
} catch (PDOException $e) {
    die("Tablo oluşturma hatası: " . $e->getMessage());
}

function getWorkoutDays(int $count): array {
    $map = [
        2 => ["Salı", "Cuma"],
        3 => ["Pazartesi", "Çarşamba", "Cuma"],
        4 => ["Pazartesi", "Salı", "Perşembe", "Cumartesi"],
        5 => ["Pazartesi", "Salı", "Çarşamba", "Cuma", "Cumartesi"],
        6 => ["Pazartesi", "Salı", "Çarşamba", "Perşembe", "Cuma", "Cumartesi"]
    ];
    return $map[$count] ?? ["Pazartesi", "Çarşamba", "Cuma"];
}

function getRecommendation(string $type, string $level, int $duration): string {
    if ($type === "Yağ Yakımı") {
        return "{$level} seviyede haftada düzenli kardiyo + temel direnç antrenmanı önerilir. Günlük {$duration} dakikada tempolu yürüyüş, bisiklet ve full body çalışmalar iyi gider.";
    }
    if ($type === "Kas Gelişimi") {
        return "{$level} seviyede split program daha uygundur. Günlük {$duration} dakikalık ağırlık antrenmanı, yeterli protein ve düzenli uyku ile daha iyi sonuç alırsın.";
    }
    if ($type === "Evde Egzersiz") {
        return "{$level} seviyede şınav, squat, plank, lunge ve direnç bandı egzersizleriyle güçlü bir ev planı oluşturabilirsin. Günlük {$duration} dakikalık plan yeterli olur.";
    }
    return "{$level} seviyede haftalık dengeli fitness planı önerilir. Günlük {$duration} dakika ile kuvvet, kardiyo ve mobiliteyi birlikte götürebilirsin.";
}

$motivationMessages = [
    "Disiplinli kal. Az ama düzenli çalışma, düzensiz büyük efordan daha değerlidir.",
    "Bugün yaptığın antrenman, yarınki görünümünü oluşturur.",
    "Mükemmel olmak zorunda değilsin, devamlı olmak zorundasın.",
    "Her tekrar seni hedefe biraz daha yaklaştırır."
];

$plan = [
    "training_type" => "Fitness",
    "level_name" => "Başlangıç",
    "weekly_goal" => 4,
    "duration_min" => 45
];

$planStmt = $pdo->prepare("
    SELECT training_type, level_name, weekly_goal, duration_min
    FROM workout_plans
    WHERE user_id = ?
    LIMIT 1
");
$planStmt->execute([$user_id]);
$savedPlan = $planStmt->fetch(PDO::FETCH_ASSOC);

if ($savedPlan) {
    $plan = [
        "training_type" => $savedPlan["training_type"] ?: "Fitness",
        "level_name" => $savedPlan["level_name"] ?: "Başlangıç",
        "weekly_goal" => (int)($savedPlan["weekly_goal"] ?: 4),
        "duration_min" => (int)($savedPlan["duration_min"] ?: 45)
    ];
}

$taskStmt = $pdo->prepare("
    SELECT isinma, anaantrenman, kardiyo, esneme
    FROM workout_checklists
    WHERE user_id = ? AND task_date = ?
    LIMIT 1
");
$taskStmt->execute([$user_id, $today]);
$taskRow = $taskStmt->fetch(PDO::FETCH_ASSOC);

$tasks = [
    "isinma" => $taskRow ? (int)$taskRow["isinma"] : 0,
    "anaantrenman" => $taskRow ? (int)$taskRow["anaantrenman"] : 0,
    "kardiyo" => $taskRow ? (int)$taskRow["kardiyo"] : 0,
    "esneme" => $taskRow ? (int)$taskRow["esneme"] : 0
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "save_plan") {
        $trainingType = trim($_POST["training_type"] ?? "Fitness");
        $levelName = trim($_POST["level_name"] ?? "Başlangıç");
        $weeklyGoal = (int)($_POST["weekly_goal"] ?? 4);
        $durationMin = (int)($_POST["duration_min"] ?? 45);

        if ($weeklyGoal < 2 || $weeklyGoal > 6) {
            $weeklyGoal = 4;
        }

        if (!in_array($durationMin, [30, 45, 60, 75, 90], true)) {
            $durationMin = 45;
        }

        $checkStmt = $pdo->prepare("SELECT id FROM workout_plans WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $updateStmt = $pdo->prepare("
                UPDATE workout_plans
                SET training_type = ?, level_name = ?, weekly_goal = ?, duration_min = ?
                WHERE user_id = ?
            ");
            $updateStmt->execute([$trainingType, $levelName, $weeklyGoal, $durationMin, $user_id]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO workout_plans (user_id, training_type, level_name, weekly_goal, duration_min)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$user_id, $trainingType, $levelName, $weeklyGoal, $durationMin]);
        }

        $plan = [
            "training_type" => $trainingType,
            "level_name" => $levelName,
            "weekly_goal" => $weeklyGoal,
            "duration_min" => $durationMin
        ];

        $message = "Antrenman planı kaydedildi.";
        $messageType = "success";
    }

    if ($action === "load_profile_goal") {
        $goalStmt = $pdo->prepare("
            SELECT goal_type
            FROM user_profiles
            WHERE user_id = ?
            LIMIT 1
        ");
        $goalStmt->execute([$user_id]);
        $goalRow = $goalStmt->fetch(PDO::FETCH_ASSOC);
        $goalType = $goalRow["goal_type"] ?? "";

        if ($goalType === "Kilo Vermek") {
            $plan["training_type"] = "Yağ Yakımı";
        } elseif ($goalType === "Kas Kazanmak") {
            $plan["training_type"] = "Kas Gelişimi";
        } else {
            $plan["training_type"] = "Fitness";
        }

        $checkStmt = $pdo->prepare("SELECT id FROM workout_plans WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $updateStmt = $pdo->prepare("
                UPDATE workout_plans
                SET training_type = ?, level_name = ?, weekly_goal = ?, duration_min = ?
                WHERE user_id = ?
            ");
            $updateStmt->execute([
                $plan["training_type"],
                $plan["level_name"],
                $plan["weekly_goal"],
                $plan["duration_min"],
                $user_id
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO workout_plans (user_id, training_type, level_name, weekly_goal, duration_min)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $user_id,
                $plan["training_type"],
                $plan["level_name"],
                $plan["weekly_goal"],
                $plan["duration_min"]
            ]);
        }

        $message = "Profil hedefi yüklendi.";
        $messageType = "success";
    }

    if ($action === "save_checklist") {
        $tasks = [
            "isinma" => isset($_POST["isinma"]) ? 1 : 0,
            "anaantrenman" => isset($_POST["anaantrenman"]) ? 1 : 0,
            "kardiyo" => isset($_POST["kardiyo"]) ? 1 : 0,
            "esneme" => isset($_POST["esneme"]) ? 1 : 0
        ];

        $checkStmt = $pdo->prepare("
            SELECT id
            FROM workout_checklists
            WHERE user_id = ? AND task_date = ?
            LIMIT 1
        ");
        $checkStmt->execute([$user_id, $today]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $updateStmt = $pdo->prepare("
                UPDATE workout_checklists
                SET isinma = ?, anaantrenman = ?, kardiyo = ?, esneme = ?
                WHERE user_id = ? AND task_date = ?
            ");
            $updateStmt->execute([
                $tasks["isinma"],
                $tasks["anaantrenman"],
                $tasks["kardiyo"],
                $tasks["esneme"],
                $user_id,
                $today
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO workout_checklists (user_id, task_date, isinma, anaantrenman, kardiyo, esneme)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $user_id,
                $today,
                $tasks["isinma"],
                $tasks["anaantrenman"],
                $tasks["kardiyo"],
                $tasks["esneme"]
            ]);
        }

        $message = "Bugünkü antrenman kontrolü kaydedildi.";
        $messageType = "success";
    }

    if ($action === "reset_checklist") {
        $tasks = [
            "isinma" => 0,
            "anaantrenman" => 0,
            "kardiyo" => 0,
            "esneme" => 0
        ];

        $checkStmt = $pdo->prepare("
            SELECT id
            FROM workout_checklists
            WHERE user_id = ? AND task_date = ?
            LIMIT 1
        ");
        $checkStmt->execute([$user_id, $today]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $updateStmt = $pdo->prepare("
                UPDATE workout_checklists
                SET isinma = 0, anaantrenman = 0, kardiyo = 0, esneme = 0
                WHERE user_id = ? AND task_date = ?
            ");
            $updateStmt->execute([$user_id, $today]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO workout_checklists (user_id, task_date, isinma, anaantrenman, kardiyo, esneme)
                VALUES (?, ?, 0, 0, 0, 0)
            ");
            $insertStmt->execute([$user_id, $today]);
        }

        $message = "Checklist sıfırlandı.";
        $messageType = "success";
    }
}

$days = getWorkoutDays((int)$plan["weekly_goal"]);
$completedCount = (int)$tasks["isinma"] + (int)$tasks["anaantrenman"] + (int)$tasks["kardiyo"] + (int)$tasks["esneme"];
$motivationText = $motivationMessages[min($completedCount, count($motivationMessages) - 1)];
$recommendationText = getRecommendation($plan["training_type"], $plan["level_name"], (int)$plan["duration_min"]);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - Antrenman</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="icon" type="image/png" href="favicon.png" />
  <style>
    .workout-hero {
      padding: 30px;
      border-radius: 24px;
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: 0 18px 40px rgba(0,0,0,0.22);
      margin-bottom: 24px;
    }

    .workout-hero h1 {
      font-size: 34px;
      margin-bottom: 10px;
    }

    .workout-hero p {
      color: #cbd5e1;
      line-height: 1.7;
      max-width: 850px;
    }

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

    .stats-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 18px;
      margin-top: 24px;
    }

    .stat-card {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 20px;
      padding: 20px;
      box-shadow: 0 14px 28px rgba(0,0,0,0.16);
    }

    .stat-card h3 {
      color: #cbd5e1;
      font-size: 14px;
      margin-bottom: 10px;
      font-weight: normal;
    }

    .stat-card strong {
      font-size: 28px;
      color: #4ade80;
    }

    .workout-grid {
      display: grid;
      grid-template-columns: 1.2fr 0.9fr;
      gap: 22px;
      margin-top: 22px;
    }

    .workout-card {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      padding: 24px;
      box-shadow: 0 18px 35px rgba(0,0,0,0.20);
    }

    .workout-card h2 {
      margin-bottom: 16px;
      font-size: 24px;
    }

    .program-list {
      display: grid;
      gap: 16px;
    }

    .program-item {
      background: rgba(15,23,42,0.55);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 18px;
      padding: 18px;
    }

    .program-item h3 {
      margin-bottom: 10px;
      color: #4ade80;
      font-size: 18px;
    }

    .program-item ul {
      padding-left: 18px;
      color: #dbeafe;
      line-height: 1.8;
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
    .form-group select {
      width: 100%;
      padding: 13px 14px;
      border-radius: 12px;
      border: none;
      outline: none;
      background: rgba(255,255,255,0.12);
      color: white;
      font-size: 15px;
    }

    .form-group option {
      color: black;
    }

    .full {
      width: 100%;
    }

    .summary-box {
      margin-top: 18px;
      padding: 16px;
      border-radius: 16px;
      background: rgba(15,23,42,0.58);
      border: 1px solid rgba(255,255,255,0.06);
      color: #dbeafe;
      line-height: 1.8;
    }

    .action-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 14px;
    }

    .action-row form {
      display: inline-block;
    }

    .day-tags {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 16px;
    }

    .day-tag {
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(255,255,255,0.08);
      color: #e2e8f0;
      border: 1px solid rgba(255,255,255,0.06);
      font-size: 14px;
    }

    .check-card {
      margin-top: 20px;
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .check-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }

    .check-list {
      display: grid;
      gap: 10px;
    }

    .check-item {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(15,23,42,0.45);
      padding: 12px 14px;
      border-radius: 14px;
    }

    .check-item input {
      width: 18px;
      height: 18px;
      accent-color: #22c55e;
    }

    .motivation-box {
      margin-top: 20px;
      padding: 18px;
      border-radius: 18px;
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      color: #e2e8f0;
      line-height: 1.7;
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
      .workout-grid {
        grid-template-columns: 1fr;
      }

      .form-grid {
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
      <a href="workout.php" class="active">Antrenman</a>
                        <a href="goal.php">Hedef Sistemi</a>

      <a href="profile.php">Profil</a>
      <a href="calorie.php">Kalori</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container">
    <section class="workout-hero">
      <h1>Antrenman Planın</h1>
      <p>
        FORMIX ile hedef türüne göre egzersiz programını oluştur, antrenman günlerini planla
        ve günlük ilerlemeni takip et. İster yağ yakımı, ister kas kazanımı, ister form koruma
        hedefin olsun, burada sana uygun bir düzen var.
      </p>

      <div class="stats-row">
        <div class="stat-card">
          <h3>Haftalık Hedef</h3>
          <strong><?php echo (int)$plan["weekly_goal"]; ?> Gün</strong>
        </div>
        <div class="stat-card">
          <h3>Antrenman Türü</h3>
          <strong><?php echo htmlspecialchars($plan["training_type"]); ?></strong>
        </div>
        <div class="stat-card">
          <h3>Seviye</h3>
          <strong><?php echo htmlspecialchars($plan["level_name"]); ?></strong>
        </div>
        <div class="stat-card">
          <h3>Tamamlanan</h3>
          <strong><?php echo $completedCount; ?></strong>
        </div>
      </div>
    </section>

    <?php if ($message): ?>
      <div class="result-box <?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <div class="workout-grid">
      <div>
        <div class="workout-card">
          <h2>Örnek Haftalık Program</h2>

          <div class="program-list">
            <div class="program-item">
              <h3>Gün 1 - Göğüs & Triceps</h3>
              <ul>
                <li>Bench Press - 4x10</li>
                <li>Incline Dumbbell Press - 3x12</li>
                <li>Chest Fly - 3x12</li>
                <li>Triceps Pushdown - 3x12</li>
                <li>Dips - 3x10</li>
              </ul>
            </div>

            <div class="program-item">
              <h3>Gün 2 - Sırt & Biceps</h3>
              <ul>
                <li>Lat Pulldown - 4x10</li>
                <li>Barbell Row - 3x10</li>
                <li>Seated Cable Row - 3x12</li>
                <li>Barbell Curl - 3x12</li>
                <li>Hammer Curl - 3x12</li>
              </ul>
            </div>

            <div class="program-item">
              <h3>Gün 3 - Omuz & Karın</h3>
              <ul>
                <li>Shoulder Press - 4x10</li>
                <li>Lateral Raise - 3x15</li>
                <li>Rear Delt Fly - 3x15</li>
                <li>Plank - 3 set</li>
                <li>Crunch - 3x20</li>
              </ul>
            </div>

            <div class="program-item">
              <h3>Gün 4 - Bacak</h3>
              <ul>
                <li>Squat - 4x10</li>
                <li>Leg Press - 3x12</li>
                <li>Romanian Deadlift - 3x10</li>
                <li>Leg Curl - 3x12</li>
                <li>Calf Raise - 4x15</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="workout-card">
          <h2>Bugünkü Antrenman Kontrolü</h2>

          <div class="check-card">
            <div class="check-head">
              <strong>Görevler</strong>

              <form method="POST">
                <input type="hidden" name="action" value="reset_checklist">
                <button class="btn secondary" type="submit">Listeyi Sıfırla</button>
              </form>
            </div>

            <form method="POST">
              <input type="hidden" name="action" value="save_checklist">

              <div class="check-list">
                <label class="check-item">
                  <input type="checkbox" name="isinma" <?php echo $tasks["isinma"] ? "checked" : ""; ?> />
                  <span>5-10 dakika ısınma yaptım</span>
                </label>

                <label class="check-item">
                  <input type="checkbox" name="anaantrenman" <?php echo $tasks["anaantrenman"] ? "checked" : ""; ?> />
                  <span>Ana antrenmanımı tamamladım</span>
                </label>

                <label class="check-item">
                  <input type="checkbox" name="kardiyo" <?php echo $tasks["kardiyo"] ? "checked" : ""; ?> />
                  <span>Kardiyo ekledim</span>
                </label>

                <label class="check-item">
                  <input type="checkbox" name="esneme" <?php echo $tasks["esneme"] ? "checked" : ""; ?> />
                  <span>Esneme / soğuma yaptım</span>
                </label>
              </div>

              <div class="action-row">
                <button class="btn primary" type="submit">Kontrolü Kaydet</button>
              </div>
            </form>
          </div>

          <div class="motivation-box">
            <?php echo htmlspecialchars($motivationText); ?>
          </div>
        </div>
      </div>

      <div>
        <div class="workout-card">
          <h2>Kendi Planını Oluştur</h2>

          <form method="POST">
            <input type="hidden" name="action" value="save_plan">

            <div class="form-grid">
              <div class="form-group">
                <label>Antrenman Türü</label>
                <select name="training_type">
                  <option value="Fitness" <?php echo $plan["training_type"] === "Fitness" ? "selected" : ""; ?>>Fitness</option>
                  <option value="Yağ Yakımı" <?php echo $plan["training_type"] === "Yağ Yakımı" ? "selected" : ""; ?>>Yağ Yakımı</option>
                  <option value="Kas Gelişimi" <?php echo $plan["training_type"] === "Kas Gelişimi" ? "selected" : ""; ?>>Kas Gelişimi</option>
                  <option value="Evde Egzersiz" <?php echo $plan["training_type"] === "Evde Egzersiz" ? "selected" : ""; ?>>Evde Egzersiz</option>
                </select>
              </div>

              <div class="form-group">
                <label>Seviye</label>
                <select name="level_name">
                  <option value="Başlangıç" <?php echo $plan["level_name"] === "Başlangıç" ? "selected" : ""; ?>>Başlangıç</option>
                  <option value="Orta" <?php echo $plan["level_name"] === "Orta" ? "selected" : ""; ?>>Orta</option>
                  <option value="İleri" <?php echo $plan["level_name"] === "İleri" ? "selected" : ""; ?>>İleri</option>
                </select>
              </div>

              <div class="form-group">
                <label>Haftalık Gün Sayısı</label>
                <select name="weekly_goal">
                  <option value="2" <?php echo (int)$plan["weekly_goal"] === 2 ? "selected" : ""; ?>>2 Gün</option>
                  <option value="3" <?php echo (int)$plan["weekly_goal"] === 3 ? "selected" : ""; ?>>3 Gün</option>
                  <option value="4" <?php echo (int)$plan["weekly_goal"] === 4 ? "selected" : ""; ?>>4 Gün</option>
                  <option value="5" <?php echo (int)$plan["weekly_goal"] === 5 ? "selected" : ""; ?>>5 Gün</option>
                  <option value="6" <?php echo (int)$plan["weekly_goal"] === 6 ? "selected" : ""; ?>>6 Gün</option>
                </select>
              </div>

              <div class="form-group">
                <label>Günlük Süre</label>
                <select name="duration_min">
                  <option value="30" <?php echo (int)$plan["duration_min"] === 30 ? "selected" : ""; ?>>30 dk</option>
                  <option value="45" <?php echo (int)$plan["duration_min"] === 45 ? "selected" : ""; ?>>45 dk</option>
                  <option value="60" <?php echo (int)$plan["duration_min"] === 60 ? "selected" : ""; ?>>60 dk</option>
                  <option value="75" <?php echo (int)$plan["duration_min"] === 75 ? "selected" : ""; ?>>75 dk</option>
                  <option value="90" <?php echo (int)$plan["duration_min"] === 90 ? "selected" : ""; ?>>90 dk</option>
                </select>
              </div>
            </div>

            <div class="action-row">
              <button class="btn primary" type="submit">Planı Kaydet</button>
            </div>
          </form>

          <div class="action-row">
            <form method="POST">
              <input type="hidden" name="action" value="load_profile_goal">
              <button class="btn secondary" type="submit">Profil Hedefini Yükle</button>
            </form>
          </div>

          <div class="day-tags">
            <?php foreach ($days as $day): ?>
              <span class="day-tag"><?php echo htmlspecialchars($day); ?></span>
            <?php endforeach; ?>
          </div>

          <div class="summary-box">
            <strong>Kayıtlı Planın</strong><br>
            Antrenman Türü: <?php echo htmlspecialchars($plan["training_type"]); ?><br>
            Seviye: <?php echo htmlspecialchars($plan["level_name"]); ?><br>
            Haftalık Gün Sayısı: <?php echo (int)$plan["weekly_goal"]; ?><br>
            Günlük Süre: <?php echo (int)$plan["duration_min"]; ?> dakika<br>
            Planlanan Günler: <?php echo htmlspecialchars(implode(", ", $days)); ?>
          </div>
        </div>

        <div class="workout-card">
          <h2>Antrenman Önerisi</h2>
          <div class="summary-box">
            <?php echo htmlspecialchars($recommendationText); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
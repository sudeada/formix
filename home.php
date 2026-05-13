<?php
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
  header("Location: login.php");
  exit;
}

$user_id = (int)$_SESSION["user_id"];

$stmt = $pdo->prepare("
    SELECT 
        u.*,
        up.target_weight,
        up.goal_type,
        d.daily_calorie,
        d.diet_mode,
        d.bmi,
        d.bmi_text
    FROM users u
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN user_diet d ON u.id = d.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  session_destroy();
  header("Location: login.php");
  exit;
}

$userName = $user["name"] ?? "Kullanıcı";
$height = isset($user["height"]) ? (float)$user["height"] : 0;
$weight = isset($user["weight"]) ? (float)$user["weight"] : 0;

$bmi = "-";
if (!empty($user["bmi"])) {
  $bmi = $user["bmi"];
} elseif ($height > 0 && $weight > 0) {
  $bmi = number_format($weight / (($height / 100) * ($height / 100)), 1);
}

$weightText = $weight > 0
  ? rtrim(rtrim(number_format($weight, 1, ".", ""), "0"), ".") . " kg"
  : "-";

$goalText = "-";
if (!empty($user["goal_type"]) && !empty($user["target_weight"])) {
  $goalText = $user["goal_type"] . " • " .
    rtrim(rtrim(number_format((float)$user["target_weight"], 1, ".", ""), "0"), ".") . " kg";
} elseif (!empty($user["goal_type"])) {
  $goalText = $user["goal_type"];
} elseif (!empty($user["target_weight"])) {
  $goalText = rtrim(rtrim(number_format((float)$user["target_weight"], 1, ".", ""), "0"), ".") . " kg";
} else {
  $goalText = "Hedef yok";
}

$today = date("Y-m-d");
$waterStmt = $pdo->prepare("
    SELECT glass_count
    FROM water_logs
    WHERE user_id = ? AND log_date = ?
    LIMIT 1
");
$waterStmt->execute([$user_id, $today]);
$waterRow = $waterStmt->fetch(PDO::FETCH_ASSOC);
$waterCount = $waterRow ? (int)$waterRow["glass_count"] : 0;
$waterText = $waterCount . " Bardak";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <link rel="icon" type="image/png" href="favicon.png" />
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FORMIX - Ana Sayfa</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .logo-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 26px;
      font-weight: bold;
      color: #22c55e;
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

    .hero-home {
      padding: 34px;
      border-radius: 26px;
      background: linear-gradient(135deg, rgba(34,197,94,0.16), rgba(255,255,255,0.04));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: 0 20px 45px rgba(0,0,0,0.22);
      margin-bottom: 24px;
    }

    .hero-home h1 {
      font-size: 36px;
      margin-bottom: 10px;
    }

    .hero-home p {
      color: #cbd5e1;
      line-height: 1.7;
      max-width: 850px;
    }

    .quick-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 16px;
      margin-top: 22px;
    }

    .quick-stat {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 18px;
      padding: 18px;
    }

    .quick-stat h4 {
      color: #cbd5e1;
      font-size: 14px;
      font-weight: normal;
      margin-bottom: 8px;
    }

    .quick-stat strong {
      color: #4ade80;
      font-size: 26px;
    }

    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      margin-top: 24px;
    }

    .mini-card {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 22px;
      box-shadow: 0 16px 35px rgba(0,0,0,0.20);
      padding: 24px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 220px;
    }

    .mini-card .icon {
      width: 58px;
      height: 58px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 26px;
      margin-bottom: 16px;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      box-shadow: 0 10px 24px rgba(34,197,94,0.22);
    }

    .mini-card h3 {
      margin-bottom: 10px;
      font-size: 22px;
    }

    .mini-card p {
      color: #dbeafe;
      line-height: 1.7;
      margin-bottom: 18px;
      flex-grow: 1;
    }

    .welcome-note {
      margin-top: 24px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 20px;
      padding: 20px;
      color: #dbeafe;
      line-height: 1.8;
    }

    @media (max-width: 1100px) {
      .dashboard-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 650px) {
      .dashboard-grid {
        grid-template-columns: 1fr;
      }

      .hero-home h1 {
        font-size: 28px;
      }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="logo-brand">
      <img src="favicon.png" alt="FORMIX Logo" />
      <span>FORMIX</span>
    </div>

    <div class="top-links">
      <a href="home.php" class="active">Ana Sayfa</a>
      <a href="diet.php">Diyet</a>
            <a href="workout.html">Antrenman</a>
                  <a href="goal.php">Hedef Sistemi</a>


      <a href="profile.php">Profil</a>
      <a href="calorie.php">Kalori</a>
      <a href="logout.php">Çıkış</a>
    </div>
  </div>

  <div class="container">
    <section class="hero-home">
      <h1>Hoş geldin, <span id="userName"><?php echo htmlspecialchars($userName); ?></span></h1>
      <p>
        FORMIX ile sağlıklı yaşamını tek panelden yönet. Su tüketimini takip et,
        vücut ölçülerini kontrol et, antrenman planını görüntüle ve hedeflerine
        daha düzenli şekilde ilerle.
      </p>

      <div class="quick-stats">
        <div class="quick-stat">
          <h4>Bugünkü Su</h4>
          <strong id="waterStat"><?php echo htmlspecialchars($waterText); ?></strong>
        </div>
        <div class="quick-stat">
          <h4>Mevcut Kilo</h4>
          <strong id="weightStat"><?php echo htmlspecialchars($weightText); ?></strong>
        </div>
        <div class="quick-stat">
          <h4>VKİ</h4>
          <strong id="bmiStat"><?php echo htmlspecialchars((string)$bmi); ?></strong>
        </div>
        <div class="quick-stat">
          <h4>Hedef</h4>
          <strong id="goalStat"><?php echo htmlspecialchars($goalText); ?></strong>
        </div>
      </div>
    </section>

    <div class="dashboard-grid">
      <div class="mini-card">
        <div class="icon">🥗</div>
        <h3>Diyet</h3>
        <p>Günlük beslenme düzenini oluştur, kalori planını takip et ve daha dengeli beslen.</p>
        <a href="diet.php" class="btn secondary">Sayfaya Git</a>
      </div>

      <div class="mini-card">
        <div class="icon">💧</div>
        <h3>Su Takibi</h3>
        <p>Gün içinde ne kadar su içtiğini gör, hedefini tamamla ve düzenli tüketim alışkanlığı oluştur.</p>
        <a href="water.php" class="btn secondary">Takip Et</a>
      </div>

      <div class="mini-card">
        <div class="icon">🏋️</div>
        <h3>Antrenman</h3>
        <p>Antrenman programını görüntüle, kendi planını oluştur ve günlük egzersizlerini düzenle.</p>
        <a href="workout.php" class="btn secondary">Antrenmana Git</a>
      </div>

      <div class="mini-card">
        <div class="icon">👤</div>
        <h3>Profil</h3>
        <p>Kişisel bilgilerini, boy-kilo verilerini ve hedef detaylarını düzenleyerek sistemi kişiselleştir.</p>
        <a href="profile.php" class="btn secondary">Profili Aç</a>
      </div>

      <div class="mini-card">
        <div class="icon">📈</div>
        <h3>Kalori Takibi</h3>
        <p>Günlük yemek kalorilerini kaydet ve hedefini kontrol et.</p>
        <a href="calorie.php" class="btn secondary">İlerlemeyi Gör</a>
      </div>

      <div class="mini-card">
        <div class="icon">⚖️</div>
        <h3>VKİ Hesabı</h3>
        <p>Vücut kitle indeksini hesapla, mevcut form durumunu öğren ve gelişimini izle.</p>
        <a href="bmi.php" class="btn secondary">Hesapla</a>
      </div>

      <div class="mini-card">
        <div class="icon">🎯</div>
        <h3>Hedef Sistemi</h3>
        <p>Kilo vermek, form korumak ya da kas kazanmak için net hedefler belirle.</p>
        <a href="progress.php" class="btn secondary">Hedef Belirle</a>
      </div>

      <div class="mini-card">
        <div class="icon">🔥</div>
        <h3>Motivasyon</h3>
        <p>Düzenli takip ile devamlılığını koru, küçük adımlarla büyük sonuçlara yaklaş.</p>
        <a href="workout.php" class="btn secondary">Devam Et</a>
      </div>
    </div>

    <div class="welcome-note">
      <strong>Bugünkü Not:</strong> Düzenli su içmek, antrenmanı aksatmamak ve haftalık ilerlemeyi kaydetmek
      hedeflerine ulaşmanda en büyük farkı oluşturur.
    </div>
  </div>
</body>
</html>
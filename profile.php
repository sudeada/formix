<?php
require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];
$message = "";
$messageType = "";

function getUserProfile($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.age,
            u.gender,
            u.height,
            u.weight,
            u.activity,
            up.target_weight,
            up.goal_type,
            up.phone,
            up.note
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = getUserProfile($pdo, $user_id);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $age = trim($_POST["age"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $height = trim($_POST["height"] ?? "");
    $weight = trim($_POST["weight"] ?? "");
    $targetWeight = trim($_POST["target_weight"] ?? "");
    $activity = trim($_POST["activity"] ?? "");
    $goalType = trim($_POST["goal_type"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $note = trim($_POST["note"] ?? "");

    if ($name === "" || $email === "") {
        $message = "Ad Soyad ve E-posta alanı zorunludur.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Geçerli bir e-posta adresi gir.";
        $messageType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$email, $user_id]);

            if ($checkEmail->fetch()) {
                throw new Exception("Bu e-posta başka bir hesapta kullanılıyor.");
            }

            $updateUser = $pdo->prepare("
                UPDATE users 
                SET name = ?, email = ?, age = ?, gender = ?, height = ?, weight = ?, activity = ?
                WHERE id = ?
            ");
            $updateUser->execute([
                $name,
                $email,
                $age !== "" ? (int)$age : null,
                $gender !== "" ? $gender : null,
                $height !== "" ? (int)$height : null,
                $weight !== "" ? (float)$weight : null,
                $activity !== "" ? $activity : null,
                $user_id
            ]);

            $checkProfile = $pdo->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
            $checkProfile->execute([$user_id]);
            $profileExists = $checkProfile->fetch(PDO::FETCH_ASSOC);

            if ($profileExists) {
                $updateProfile = $pdo->prepare("
                    UPDATE user_profiles
                    SET target_weight = ?, goal_type = ?, phone = ?, note = ?
                    WHERE user_id = ?
                ");
                $updateProfile->execute([
                    $targetWeight !== "" ? (float)$targetWeight : null,
                    $goalType !== "" ? $goalType : null,
                    $phone !== "" ? $phone : null,
                    $note !== "" ? $note : null,
                    $user_id
                ]);
            } else {
                $insertProfile = $pdo->prepare("
                    INSERT INTO user_profiles (user_id, target_weight, goal_type, phone, note)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertProfile->execute([
                    $user_id,
                    $targetWeight !== "" ? (float)$targetWeight : null,
                    $goalType !== "" ? $goalType : null,
                    $phone !== "" ? $phone : null,
                    $note !== "" ? $note : null
                ]);
            }

            $pdo->commit();

            $_SESSION["user_name"] = $name;
            $_SESSION["user_email"] = $email;

            $user = getUserProfile($pdo, $user_id);

            $message = "Profil bilgileri başarıyla kaydedildi.";
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

$name = $user["name"] ?? "Kullanıcı";
$email = $user["email"] ?? "";
$age = $user["age"] ?? "";
$gender = $user["gender"] ?? "";
$height = $user["height"] ?? "";
$weight = $user["weight"] ?? "";
$targetWeight = $user["target_weight"] ?? "";
$activity = $user["activity"] ?? "";
$goalType = $user["goal_type"] ?? "";
$phone = $user["phone"] ?? "";
$note = $user["note"] ?? "";

$avatarText = "F";
if ($name !== "") {
    $parts = preg_split('/\s+/', trim($name));
    $initials = "";
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, "UTF-8"), "UTF-8");
    }
    if ($initials !== "") {
        $avatarText = $initials;
    }
}

$bmi = "-";
$bmiStatus = "-";

if ($height !== "" && $weight !== "" && (float)$height > 0 && (float)$weight > 0) {
    $bmiValue = (float)$weight / (((float)$height / 100) * ((float)$height / 100));
    $bmi = number_format($bmiValue, 1);

    if ($bmiValue < 18.5) {
        $bmiStatus = "Zayıf";
    } elseif ($bmiValue < 25) {
        $bmiStatus = "Normal";
    } elseif ($bmiValue < 30) {
        $bmiStatus = "Fazla kilolu";
    } else {
        $bmiStatus = "Obez";
    }
}

$targetDisplay = $targetWeight !== "" && $targetWeight !== null
    ? rtrim(rtrim(number_format((float)$targetWeight, 1, ".", ""), "0"), ".") . " kg"
    : "-";

$heightDisplay = $height !== "" && $height !== null ? $height . " cm" : "-";
$weightDisplay = $weight !== "" && $weight !== null
    ? rtrim(rtrim(number_format((float)$weight, 1, ".", ""), "0"), ".") . " kg"
    : "-";

$goalBadge = $goalType ?: "-";
$activityBadge = $activity ?: "-";

$progressPercent = 0;
$goalText = "Hedef kilo girildiğinde hedefe yaklaşma durumu burada görünür.";

if ($weight !== "" && $targetWeight !== "" && is_numeric($weight) && is_numeric($targetWeight)) {
    $currentWeight = (float)$weight;
    $targetWeightNum = (float)$targetWeight;

    if ($goalType === "Kilo Vermek") {
        $diff = $currentWeight - $targetWeightNum;
        if ($diff <= 0) {
            $progressPercent = 100;
            $goalText = "Tebrikler, hedef kilona ulaştın.";
        } else {
            $progressPercent = max(10, min(90, 100 - ($diff * 8)));
            $goalText = "Hedefe ulaşmak için yaklaşık " . number_format($diff, 1) . " kg kaldı.";
        }
    } elseif ($goalType === "Kas Kazanmak") {
        $diff = $targetWeightNum - $currentWeight;
        if ($diff <= 0) {
            $progressPercent = 100;
            $goalText = "Hedef seviyeye ulaştın veya geçtin.";
        } else {
            $progressPercent = max(10, min(90, 100 - ($diff * 8)));
            $goalText = "Hedefe ulaşmak için yaklaşık " . number_format($diff, 1) . " kg artış gerekiyor.";
        }
    } elseif ($goalType === "Formu Korumak") {
        $progressPercent = 100;
        $goalText = "Form koruma hedefi seçili. Mevcut durumunu dengede tutmaya odaklan.";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <link rel="icon" type="image/png" href="favicon.png" />
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FORMIX - Profil</title>
    <link rel="stylesheet" href="style.css" />
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 22px;
            margin-top: 24px;
        }

        .profile-side,
        .profile-main {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.22);
            padding: 24px;
        }

        .profile-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: bold;
            color: white;
            box-shadow: 0 12px 28px rgba(34,197,94,0.30);
        }

        .profile-center {
            text-align: center;
        }

        .profile-center h2 {
            margin-bottom: 8px;
            font-size: 28px;
        }

        .profile-center p {
            color: #cbd5e1;
            margin-bottom: 18px;
        }

        .profile-badges {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .badge {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.08);
            padding: 8px 12px;
            border-radius: 999px;
            color: #e2e8f0;
            font-size: 13px;
        }

        .mini-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 18px;
        }

        .mini-stat {
            background: rgba(15,23,42,0.55);
            border-radius: 18px;
            padding: 16px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.06);
        }

        .mini-stat h4 {
            font-size: 13px;
            color: #cbd5e1;
            margin-bottom: 8px;
            font-weight: normal;
        }

        .mini-stat strong {
            font-size: 22px;
            color: #4ade80;
        }

        .section-title {
            font-size: 24px;
            margin-bottom: 18px;
            color: #fff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

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

        .form-group textarea {
            min-height: 110px;
            resize: vertical;
        }

        .summary-box {
            margin-top: 24px;
            padding: 18px;
            border-radius: 18px;
            background: rgba(15,23,42,0.55);
            border: 1px solid rgba(255,255,255,0.06);
            color: #dbeafe;
            line-height: 1.8;
        }

        .action-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .progress-wrap {
            width: 100%;
            height: 16px;
            background: rgba(255,255,255,0.08);
            border-radius: 999px;
            overflow: hidden;
            margin-top: 12px;
        }

        .progress-bar {
            height: 100%;
            width: <?php echo (int)$progressPercent; ?>%;
            background: linear-gradient(135deg, #22c55e, #4ade80);
            transition: 0.35s ease;
        }

        .goal-text {
            color: #cbd5e1;
            margin-top: 10px;
            font-size: 14px;
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

        @media (max-width: 920px) {
            .profile-grid {
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
        <div class="brand">FORMIX</div>
        <div class="top-links">
            <a href="home.php">Ana Sayfa</a>
            <a href="diet.php">Diyet</a>
                  <a href="workout.php">Antrenman</a>
                                          <a href="goal.php">Hedef Sistemi</a>


            <a href="profile.php" class="active">Profil</a>
            <a href="calorie.php">Kalori</a>
            <a href="logout.php">Çıkış</a>
        </div>
    </div>

    <div class="container">
        <div class="hero-card">
            <h1>Profilim</h1>
            <p>Kişisel bilgilerini düzenle, hedeflerini belirle ve mevcut durumunu FORMIX içinde takip et.</p>
        </div>

        <div class="profile-grid">
            <aside class="profile-side">
                <div class="profile-center">
                    <div class="profile-avatar"><?php echo htmlspecialchars($avatarText); ?></div>
                    <h2><?php echo htmlspecialchars($name); ?></h2>
                    <p><?php echo htmlspecialchars($email ?: "E-posta yok"); ?></p>

                    <div class="profile-badges">
                        <span class="badge">Hedef: <?php echo htmlspecialchars($goalBadge); ?></span>
                        <span class="badge">Aktivite: <?php echo htmlspecialchars($activityBadge); ?></span>
                    </div>
                </div>

                <div class="mini-stats">
                    <div class="mini-stat">
                        <h4>Boy</h4>
                        <strong><?php echo htmlspecialchars($heightDisplay); ?></strong>
                    </div>
                    <div class="mini-stat">
                        <h4>Kilo</h4>
                        <strong><?php echo htmlspecialchars($weightDisplay); ?></strong>
                    </div>
                    <div class="mini-stat">
                        <h4>VKİ</h4>
                        <strong><?php echo htmlspecialchars((string)$bmi); ?></strong>
                    </div>
                    <div class="mini-stat">
                        <h4>Hedef</h4>
                        <strong><?php echo htmlspecialchars($targetDisplay); ?></strong>
                    </div>
                </div>

                <div class="summary-box">
                    <strong>Hedefe Yaklaşma</strong>
                    <div class="progress-wrap">
                        <div class="progress-bar"></div>
                    </div>
                    <div class="goal-text">
                        <?php echo htmlspecialchars($goalText); ?>
                    </div>
                </div>
            </aside>

            <main class="profile-main">
                <h2 class="section-title">Bilgileri Düzenle</h2>

                <?php if ($message): ?>
                    <div class="result-box <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Ad Soyad</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($name); ?>" placeholder="Ad Soyad" />
                        </div>

                        <div class="form-group">
                            <label>E-posta</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="E-posta" />
                        </div>

                        <div class="form-group">
                            <label>Yaş</label>
                            <input type="number" name="age" value="<?php echo htmlspecialchars((string)$age); ?>" placeholder="Yaş" />
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
                            <label>Boy (cm)</label>
                            <input type="number" name="height" value="<?php echo htmlspecialchars((string)$height); ?>" placeholder="Boy" />
                        </div>

                        <div class="form-group">
                            <label>Kilo (kg)</label>
                            <input type="number" step="0.1" name="weight" value="<?php echo htmlspecialchars((string)$weight); ?>" placeholder="Kilo" />
                        </div>

                        <div class="form-group">
                            <label>Hedef Kilo (kg)</label>
                            <input type="number" step="0.1" name="target_weight" value="<?php echo htmlspecialchars((string)$targetWeight); ?>" placeholder="Hedef kilo" />
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
                            <label>Telefon</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" placeholder="Telefon" />
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label>Kısa Not</label>
                        <textarea name="note" placeholder="Kendin için kısa bir not yaz..."><?php echo htmlspecialchars($note); ?></textarea>
                    </div>

                    <div class="action-row">
                        <button class="btn primary" type="submit">Kaydet</button>
                    </div>
                </form>

                <div class="summary-box">
                    <strong>Profil Özeti</strong><br>
                    Ad Soyad: <?php echo htmlspecialchars($name); ?><br>
                    Yaş / Cinsiyet: <?php echo htmlspecialchars($age !== "" ? (string)$age : "-"); ?> / <?php echo htmlspecialchars($gender ?: "-"); ?><br>
                    Boy / Kilo: <?php echo htmlspecialchars($height !== "" ? $height . " cm" : "-"); ?> / <?php echo htmlspecialchars($weight !== "" ? rtrim(rtrim(number_format((float)$weight, 1, ".", ""), "0"), ".") . " kg" : "-"); ?><br>
                    VKİ: <?php echo htmlspecialchars((string)$bmi); ?> (<?php echo htmlspecialchars($bmiStatus); ?>)<br>
                    Hedef Türü: <?php echo htmlspecialchars($goalType ?: "-"); ?><br>
                    Aktivite: <?php echo htmlspecialchars($activity ?: "-"); ?><br>
                    Telefon: <?php echo htmlspecialchars($phone ?: "-"); ?><br>
                    Not: <?php echo nl2br(htmlspecialchars($note ?: "Henüz not eklenmedi.")); ?>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
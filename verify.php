<?php
error_reporting(0);
ini_set('display_errors', 0);

class LicenseManager {
    private $db_file = __DIR__ . DIRECTORY_SEPARATOR . 'bboxkey.json';
    private $admin_user = 'onlinesystem;
    private $admin_pass = 'ahmed12345';
    
    public function __construct() {
        if (!file_exists($this->db_file)) {
            $this->saveLicenses([]);
        }

        ini_set('session.save_path', '/home/nerox/tmp');
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    private function loadLicenses() {
        if (!file_exists($this->db_file)) {
            return [];
        }

        $raw = @file_get_contents($this->db_file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $licenses = json_decode($raw, true);

        // Never treat a broken JSON file as valid data.
        // This prevents an accidental overwrite with an empty array.
        if (!is_array($licenses)) {
            return null;
        }

        return $licenses;
    }

    private function saveLicenses($licenses) {
        $json = json_encode($licenses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $dir = dirname($this->db_file);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.licenses_' . bin2hex(random_bytes(8)) . '.tmp';

        // Write to a temporary file first. The real JSON is replaced only
        // after the complete temporary file has been written successfully.
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            return false;
        }

        @chmod($tmp, 0644);

        // Keep the existing JSON untouched if the final replacement fails.
        if (!@rename($tmp, $this->db_file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public function checkLogin() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            if (isset($_POST['username']) && isset($_POST['password'])) {
                if ($_POST['username'] === $this->admin_user && $_POST['password'] === $this->admin_pass) {
                    $_SESSION['logged_in'] = true;
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
        return true;
    }
    
    public function logout() {
        @session_destroy();
    }
    
    public function verifyKey($key, $package, $app_name = 'غير محدد', $app_icon = '', $app_version = '1.0', $app_signature = 'غير متوفر') {
        $licenses = $this->loadLicenses();
        
        foreach ($licenses as &$license) {
            if ($license['key'] === $key) {
                if ($license['package'] !== $package) {
                    return ['status' => 'error', 'message' => 'المفتاح غير صالح لهذه الحزمة'];
                }
                if ($license['expiry_date'] !== 'unlimited' && date('Y-m-d') > $license['expiry_date']) {
                    return ['status' => 'error', 'message' => 'انتهت صلاحية المفتاح'];
                }
                
                if (isset($license['target_app']) && !empty($license['target_app']) && $license['target_app'] !== 'كل التطبيقات') {
                    if (strcasecmp($license['target_app'], $app_name) !== 0) {
                        return ['status' => 'error', 'message' => 'هذا الكود مخصص لتطبيق آخر ومقيد'];
                    }
                }
                
                $device_id = md5($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
                $existing = false;
                foreach ($license['activations'] as $act) {
                    if ($act['device_id'] === $device_id) $existing = true;
                }
                
                if (!$existing) {
                    $license['activations'][] = [
                        'device_id' => $device_id,
                        'ip' => $_SERVER['REMOTE_ADDR'],
                        'app_used' => $app_name,
                        'activated_at' => date('Y-m-d H:i:s')
                    ];
                }
                
                if (!isset($license['used_apps_details'])) {
                    $license['used_apps_details'] = [];
                }
                
                $app_exists = false;
                foreach ($license['used_apps_details'] as &$u_app) {
                    if ($u_app['name'] === $app_name && $u_app['package'] === $package) {
                        if (!empty($app_icon)) $u_app['icon'] = $app_icon;
                        $u_app['version'] = $app_version;
                        $u_app['signature'] = $app_signature;
                        $u_app['last_used'] = date('Y-m-d H:i:s');
                        $app_exists = true;
                        break;
                    }
                }
                if (!$app_exists) {
                    $license['used_apps_details'][] = [
                        'name' => $app_name,
                        'package' => $package,
                        'icon' => $app_icon,
                        'version' => $app_version,
                        'signature' => $app_signature,
                        'first_used' => date('Y-m-d H:i:s'),
                        'last_used' => date('Y-m-d H:i:s')
                    ];
                }
                
                $license['last_accessed'] = date('Y-m-d H:i:s');
                $this->saveLicenses($licenses);
                
                return [
                    'status' => 'success',
                    'message' => 'تم التفعيل بنجاح',
                    'target_app' => $license['target_app'],
                    'expiry_date' => $license['expiry_date']
                ];
            }
        }
        return ['status' => 'error', 'message' => 'الكود غير صحيح'];
    }
    
    public function generateKey($package, $days, $target_app, $customKey = null) {
        $licenses = $this->loadLicenses();
        
        if ($customKey && !empty($customKey)) {
            foreach ($licenses as $l) {
                if ($l['key'] === $customKey) {
                    return false;
                }
            }
            $key = strtoupper($customKey);
        } else {
            $key = strtoupper(substr(md5(uniqid()), 0, 4) . '-' . substr(md5(uniqid()), 0, 4) . '-' . substr(md5(uniqid()), 0, 4));
        }
        
        $expiry = $days == 9999 ? 'unlimited' : date('Y-m-d', strtotime("+$days days"));
        
        $licenses[] = [
            'key' => $key,
            'package' => $package,
            'target_app' => (empty($target_app) || trim($target_app) === '') ? 'كل التطبيقات' : trim($target_app),
            'expiry_date' => $expiry,
            'activations' => [],
            'used_apps_details' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'last_accessed' => null
        ];
        
        $this->saveLicenses($licenses);
        return $key;
    }
    
    public function getAllKeys() {
        $licenses = $this->loadLicenses();
        return is_array($licenses) ? $licenses : [];
    }

    public function getKeyDetails($key) {
        $licenses = $this->getAllKeys();
        foreach ($licenses as $l) {
            if ($l['key'] === $key) return $l;
        }
        return null;
    }
    
    public function deleteKey($key) {
        $licenses = array_filter(json_decode(file_get_contents($this->db_file), true), fn($l) => $l['key'] !== $key);
        file_put_contents($this->db_file, json_encode(array_values($licenses), JSON_PRETTY_PRINT));
    }
}

$manager = new LicenseManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['key']) && isset($_POST['package'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $app_name = isset($_POST['app_name']) ? trim($_POST['app_name']) : 'غير محدد';
    $app_icon = isset($_POST['app_icon']) ? trim($_POST['app_icon']) : '';
    $app_version = isset($_POST['app_version']) ? trim($_POST['app_version']) : '1.0';
    $app_signature = isset($_POST['app_signature']) ? trim($_POST['app_signature']) : 'غير معروف';

    echo json_encode($manager->verifyKey($_POST['key'], $_POST['package'], $app_name, $app_icon, $app_version, $app_signature), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$manager->checkLogin()) {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <title>تسجيل الدخول | SYSTEM SYSTEM</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; font-family:'Cairo', sans-serif; }
            body { background: #0b0f19; min-height:100vh; display:flex; align-items:center; justify-content:center; overflow: hidden; position: relative; }
            .login-card { background: rgba(17, 24, 39, 0.7); padding:40px; border-radius:24px; box-shadow:0 20px 50px rgba(0,0,0,0.5); width:420px; max-width:90%; backdrop-filter:blur(16px); border:1px solid rgba(255,255,255,0.05); }
            .login-card h1 { text-align:center; color:#fff; margin-bottom:10px; font-size:28px; font-weight: 700; }
            .login-card p { text-align: center; color: #9ca3af; font-size: 14px; margin-bottom: 30px; }
            .input-group { margin-bottom:20px; }
            .input-group label { display:block; margin-bottom:8px; color:#9ca3af; font-size: 14px; }
            .input-group input { width:100%; padding:14px 20px; background: rgba(31, 41, 55, 0.6); border:1px solid rgba(255,255,255,0.08); border-radius:14px; font-size:15px; color: #fff; text-align: right; }
            button { width:100%; padding:14px; background:linear-gradient(135deg, #6366f1, #4338ca); color:white; border:none; border-radius:14px; font-size:16px; font-weight:bold; cursor:pointer; }
            .error { background:rgba(239, 68, 68, 0.1); color:#ef4444; padding:12px; border-radius:12px; margin-bottom:20px; text-align:center; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h1> <span>SYSTEM</span> BBOX</h1>
            <p>لوحة التحكم الذكية ونظام إدارة التراخيص</p>
            <?php if (isset($_POST['username']) && isset($_POST['password'])): ?>
                <div class="error">❌ اسم المستخدم أو كلمة المرور غير صحيحة</div>
            <?php endif; ?>
            <form method="post">
                <div class="input-group">
                    <label>👤 اسم المستخدم</label>
                    <input type="text" name="username" required>
                </div>
                <div class="input-group">
                    <label>🔒 كلمة المرور</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">تسجيل الدخول</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['logout'])) {
    $manager->logout();
    header('Location: ?');
    exit;
}

if (isset($_GET['generate'])) {
    $manager->generateKey($_GET['package'], $_GET['days'], $_GET['target_app'], $_GET['custom_key'] ?? null);
    header('Location: ?');
    exit;
}

if (isset($_GET['delete'])) {
    $manager->deleteKey($_GET['delete']);
    header('Location: ?');
    exit;
}

$keys = $manager->getAllKeys();
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <title>SYSTEM PANEL</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Cairo', sans-serif; }
        body { background:#080b11; color: #f3f4f6; padding-bottom: 60px; }
        .navbar { background: rgba(17, 24, 39, 0.8); backdrop-filter: blur(12px); padding:20px 50px; display:flex; justify-content:space-between; align-items:center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .navbar h1 span { color:#6366f1; background: linear-gradient(135deg, #6366f1, #06b6d4); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .logout-btn { background: rgba(239, 68, 68, 0.1); color:#ef4444; text-decoration:none; padding:8px 20px; border-radius:12px; font-size: 14px; font-weight: 600; border:1px solid rgba(239, 68, 68, 0.2); }
        .container { max-width:1400px; margin:40px auto; padding:0 25px; }
        
        .card { background: rgba(17, 24, 39, 0.6); padding:30px; border-radius:20px; border:1px solid rgba(255,255,255,0.04); margin-bottom: 30px; }
        .card h2 { margin-bottom:25px; color:#fff; font-size:20px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:8px; color:#9ca3af; font-size: 14px; }
        .form-group input { width:100%; padding:12px 16px; background: rgba(31, 41, 55, 0.5); border:1px solid rgba(255,255,255,0.06); border-radius:12px; font-size:14px; color: #fff; }
        .flex-box { display:flex; gap:15px; }
        .btn { background:linear-gradient(135deg, #6366f1, #4338ca); color:white; border:none; padding:14px 30px; border-radius:12px; font-size:15px; font-weight:bold; cursor:pointer; width: 100%; }
        
        .table-container { background: rgba(17, 24, 39, 0.6); border-radius:20px; border:1px solid rgba(255,255,255,0.04); overflow:hidden; }
        table { width:100%; border-collapse:collapse; text-align: right; }
        th { background: rgba(31, 41, 55, 0.7); color:#9ca3af; padding:18px 20px; font-size:14px; }
        td { padding:18px 20px; border-bottom:1px solid rgba(255,255,255,0.02); font-size: 14px; color: #d1d5db; vertical-align: middle; }
        code { background: rgba(99, 102, 241, 0.1); color: #a5b4fc; padding: 4px 10px; border-radius: 8px; font-family: monospace; }
        .badge { padding:4px 10px; border-radius:8px; font-size:12px; font-weight:600; }
        .badge.active { background:rgba(16, 185, 129, 0.1); color:#10b981; }
        .badge.expired { background:rgba(239, 68, 68, 0.1); color:#ef4444; }
        .badge.unlimited { background:rgba(6, 182, 212, 0.1); color:#06b6d4; }
        
        .action-btn { padding:6px 14px; border-radius:8px; border:none; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; display:inline-block; }
        .action-btn.copy { background: rgba(99, 102, 241, 0.1); color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.3); }
        .action-btn.details { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .action-btn.delete { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* استايل تفاصيل التطبيقات */
        .details-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .back-btn { background: rgba(255,255,255,0.1); color: #fff; padding: 8px 18px; border-radius: 10px; text-decoration: none; font-size: 14px; }
        .app-cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .app-detail-card { background: rgba(31, 41, 55, 0.5); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 20px; }
        .app-card-top { display: flex; align-items: center; gap: 15px; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 15px; }
        .app-card-top img { width: 55px; height: 55px; border-radius: 12px; object-fit: cover; }
        .info-row { margin-bottom: 10px; font-size: 13px; color: #9ca3af; }
        .info-row strong { color: #fff; display: block; font-weight: 600; margin-bottom: 2px; }
        .hash-code { background: #111827; padding: 6px 10px; border-radius: 6px; font-family: monospace; font-size: 11px; color: #38bdf8; word-break: break-all; display: block; margin-top: 4px; border: 1px solid rgba(56, 189, 248, 0.2); }
    </style>
</head>
<body>

    <div class="navbar">
        <h1> <span>SYSTEM</span> MANAGEMENT</h1>
        <a href="?logout" class="logout-btn">تسجيل الخروج</a>
    </div>
    
    <div class="container">

        <?php if (isset($_GET['details'])): 
            $detailsKey = $manager->getKeyDetails($_GET['details']);
            $apps = $detailsKey['used_apps_details'] ?? [];
        ?>
            <!-- صفحة عرض معلومات التطبيقات المصرحة لهذا المفتاح -->
            <div class="card">
                <div class="details-header">
                    <h2>📱 معلومات المفتاح : <code><?= htmlspecialchars($_GET['details']) ?></code></h2>
                    <a href="?" class="back-btn">⬅️ رجوع</a>
                </div>

                <?php if (empty($apps)): ?>
                    <p style="text-align: center; color: #9ca3af; padding: 40px 0;">لم يقم أي تطبيق باستخدام هذا المفتاح بعد.</p>
                <?php else: ?>
                    <div class="app-cards-grid">
                        <?php foreach ($apps as $app): ?>
                            <div class="app-detail-card">
                                <div class="app-card-top">
                                    <?php if (!empty($app['icon'])): ?>
                                        <img src="data:image/png;base64,<?= $app['icon'] ?>" alt="Icon">
                                    <?php else: ?>
                                        <div style="width:55px; height:55px; background:#374151; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:24px;">🎮</div>
                                    <?php endif; ?>
                                    <div>
                                        <h3 style="color:#fff; font-size:16px; margin-bottom: 4px;"><?= htmlspecialchars($app['name']) ?></h3>
                                        <span style="color:#6366f1; font-size:12px; font-weight: 600;"><?= htmlspecialchars($app['package']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="info-row">
                                    إصدار التطبيق (App Version):
                                    <strong style="color:#10b981;"><?= htmlspecialchars($app['version'] ?? '1.0') ?></strong>
                                </div>

                                <div class="info-row">
                                    أول استخدام (First Used):
                                    <strong><?= $app['first_used'] ?? 'غير معروف' ?></strong>
                                </div>

                                <div class="info-row">
                                     توقيع التطبيق (SHA-256 Signature):
                                    <span class="hash-code"><?= htmlspecialchars($app['signature'] ?? 'غير متوفر') ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="card">
                <h2>➕ توليد ترخيص جديد</h2>
                <form>
                    <div class="form-group">
                        <label>🔑 إدخال الكود يدوياً (اختياري)</label>
                        <input type="text" name="custom_key" placeholder="مثال: SYSTEM-SPECIAL-KEY">
                    </div>
                    
                    <div class="form-group">
                        <label>📦 تحقق الحزمة (Package Name)</label>
                        <input type="text" name="package" placeholder="pubgm.loader" required>
                    </div>
                    
                    <div class="flex-box">
                        <div class="form-group" style="flex:1">
                            <label>⏳ الأيام (9999 = غير محدود)</label>
                            <input type="number" name="days" value="30" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>📱 تقييد باسم تطبيق معين (اختياري)</label>
                            <input type="text" name="target_app" placeholder="مثال: PUBG Mobile">
                        </div>
                    </div>
                    
                    <button type="submit" name="generate" value="1" class="btn">🚀 توليد الترخيص</button>
                </form>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>المفتاح الأساسي</th>
                            <th>الحزمة المستهدفة</th>
                            <th>التطبيق المصرح</th>
                            <th>تاريخ الانتهاء</th>
                            <th>📱 المستخدمين</th>
                            <th>الحالة</th>
                            <th>إجراءات الإدارة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $key): 
                            $today = date('Y-m-d');
                            $expired = $key['expiry_date'] !== 'unlimited' && $key['expiry_date'] < $today;
                            $status_class = $expired ? 'expired' : 'active';
                            $status_text = $expired ? 'منتهي' : 'نشط';
                            $device_count = count($key['activations'] ?? []);
                        ?>
                        <tr>
                            <td><code><?= $key['key'] ?></code></td>
                            <td><span style="color: #6366f1;"><?= $key['package'] ?></span></td>
                            <td>
                                <?php if (!isset($key['target_app']) || $key['target_app'] === 'كل التطبيقات'): ?>
                                    <span class="badge unlimited"> كل التطبيقات</span>
                                <?php else: ?>
                                    <span style="color: #eab308; font-weight: 500;"> <?= htmlspecialchars($key['target_app']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($key['expiry_date'] === 'unlimited'): ?>
                                    <span class="badge unlimited">دائم</span>
                                <?php else: ?>
                                    <span><?= $key['expiry_date'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: bold; color: #10b981;">
                                    👤 <?= $device_count ?> أجهزة
                                </span>
                            </td>
                            <td><span class="badge <?= $status_class ?>"><?= $status_text ?></span></td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <button class="action-btn copy" onclick="navigator.clipboard.writeText('<?= $key['key'] ?>')">نسخ</button>
                                    <a href="?details=<?= $key['key'] ?>" class="action-btn details">الاعدادات</a>
                                    <a href="?delete=<?= $key['key'] ?>" onclick="return confirm('حذف الكود؟')" class="action-btn delete">حذف</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
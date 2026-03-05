<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $phone = trim($_POST['phone']);
        
        if (!empty($phone)) {
            try {
                $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
                $stmt->execute([$phone]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $updateStmt = $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    $sessionStmt = $db->prepare("INSERT INTO session (user_id, device_id, token, ip_address, user_agent, created_at, expired_at) VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))");
                    $token = bin2hex(random_bytes(32));
                    $sessionStmt->execute([
                        $user['id'],
                        'web',
                        $token,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_phone'] = $user['phone'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['token'] = $token;
                    
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Nomor telepon tidak ditemukan. Silakan buat akun baru.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Harap masukkan nomor telepon";
        }
    } 
    elseif (isset($_POST['create'])) {
        $phone = trim($_POST['phone']);
        $name = trim($_POST['name']);
        $about = trim($_POST['about'] ?? '');
        
        if (!empty($phone) && !empty($name)) {
            if (!preg_match('/^\d{10,15}$/', $phone)) {
                $error = "Harap masukkan nomor telepon yang valid (10-15 digit)";
            } else {
                try {
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
                    $checkStmt->execute([$phone]);
                    
                    if ($checkStmt->fetch()) {
                        $error = "Nomor telepon sudah terdaftar. Silakan login.";
                    } else {
                        $userId = generateUuid();
                        
                        $insertStmt = $db->prepare("INSERT INTO users (id, phone, name, about, last_seen, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())");
                        $insertStmt->execute([$userId, $phone, $name, $about]);
                        
                        $sessionStmt = $db->prepare("INSERT INTO session (user_id, device_id, token, ip_address, user_agent, created_at, expired_at) VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))");
                        $token = bin2hex(random_bytes(32));
                        $sessionStmt->execute([
                            $userId,
                            'web',
                            $token,
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT']
                        ]);
                        
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_phone'] = $phone;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['token'] = $token;
                        
                        header('Location: dashboard.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } else {
            $error = "Harap isi semua field yang wajib";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat App - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center p-4">
    <div class="bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-8 text-center">
            <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-comments text-white text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Chat App</h1>
            <p class="text-blue-100">Mulai percakapan dengan teman dan keluarga</p>
        </div>

        <div class="p-8">
            <?php if ($error): ?>
                <div class="bg-red-500/20 border border-red-500 text-red-200 p-4 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex bg-gray-700 rounded-lg p-1 mb-6">
                <button type="button" id="login-tab" class="flex-1 py-2 px-4 rounded-md font-medium transition-all duration-200 bg-blue-600 text-white">
                    Login
                </button>
                <button type="button" id="create-tab" class="flex-1 py-2 px-4 rounded-md font-medium transition-all duration-200 text-gray-300 hover:text-white">
                    Daftar
                </button>
            </div>

            <form id="login-form" method="POST" class="space-y-4">
                <input type="hidden" name="login" value="1">
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Nomor Telepon</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-phone text-gray-500"></i>
                        </div>
                        <input 
                            type="tel" 
                            name="phone"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg py-3 pl-10 pr-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nomor telepon"
                            pattern="[0-9]{10,15}"
                            required
                            value="<?php echo isset($_POST['phone']) && isset($_POST['login']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                        >
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Masuk
                </button>
            </form>

            <form id="create-form" method="POST" class="space-y-4 hidden">
                <input type="hidden" name="create" value="1">
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Nomor Telepon</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-phone text-gray-500"></i>
                        </div>
                        <input 
                            type="tel" 
                            name="phone"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg py-3 pl-10 pr-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nomor telepon"
                            pattern="[0-9]{10,15}"
                            required
                            value="<?php echo isset($_POST['phone']) && isset($_POST['create']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                        >
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Nama Lengkap</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-500"></i>
                        </div>
                        <input 
                            type="text" 
                            name="name"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg py-3 pl-10 pr-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nama lengkap"
                            required
                            value="<?php echo isset($_POST['name']) && isset($_POST['create']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                        >
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Tentang (Opsional)</label>
                    <textarea 
                        name="about"
                        rows="2"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg py-3 px-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        placeholder="Ceritakan sedikit tentang diri Anda"
                    ><?php echo isset($_POST['about']) && isset($_POST['create']) ? htmlspecialchars($_POST['about']) : ''; ?></textarea>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 hover:bg-green-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                    <i class="fas fa-user-plus mr-2"></i>
                    Buat Akun
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-gray-400">
                <p>Dengan melanjutkan, Anda menyetujui <a href="#" class="text-blue-400 hover:text-blue-300">Syarat & Ketentuan</a></p>
            </div>
        </div>
    </div>

    <script>
        const loginTab = document.getElementById('login-tab');
        const createTab = document.getElementById('create-tab');
        const loginForm = document.getElementById('login-form');
        const createForm = document.getElementById('create-form');

        function switchToLogin() {
            loginForm.classList.remove('hidden');
            createForm.classList.add('hidden');
            loginTab.classList.add('bg-blue-600', 'text-white');
            loginTab.classList.remove('text-gray-300');
            createTab.classList.remove('bg-blue-600', 'text-white');
            createTab.classList.add('text-gray-300');
        }

        function switchToCreate() {
            createForm.classList.remove('hidden');
            loginForm.classList.add('hidden');
            createTab.classList.add('bg-blue-600', 'text-white');
            createTab.classList.remove('text-gray-300');
            loginTab.classList.remove('bg-blue-600', 'text-white');
            loginTab.classList.add('text-gray-300');
        }

        loginTab.addEventListener('click', switchToLogin);
        createTab.addEventListener('click', switchToCreate);

        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 15) {
                    value = value.substring(0, 15);
                }
                e.target.value = value;
            });
        });

        <?php if ($error && strpos($error, 'tidak ditemukan') !== false): ?>
            switchToCreate();
        <?php endif; ?>
    </script>
</body>
</html>
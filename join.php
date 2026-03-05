<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// Redirect jika sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Get current user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$error = '';
$success = '';
$invitation = null;
$code = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code']);
    
    if (empty($code)) {
        $error = "Kode undangan tidak boleh kosong!";
    } else {
        // Get invitation info
        $invitation = getGroupInvitation($db, $code);
        
        if (!$invitation) {
            $error = "Kode undangan tidak valid atau sudah kadaluarsa!";
        } else {
            // Check if user is already a member
            $checkStmt = $db->prepare("
                SELECT * FROM conversation_members 
                WHERE conversation_id = ? AND user_id = ?
            ");
            $checkStmt->execute([$invitation['conversation_id'], $_SESSION['user_id']]);
            
            if ($checkStmt->fetch()) {
                $error = "Anda sudah menjadi anggota group ini!";
            } else {
                // Add user to group
                $result = addUserToGroup($db, $invitation['conversation_id'], $_SESSION['user_id']);
                
                if ($result['success']) {
                    // Update invitation usage count
                    useGroupInvitation($db, $code);
                    
                    // Add system message
                    $messageId = generateUuid();
                    $systemMessage = $user['name'] . " bergabung ke group melalui undangan";
                    
                    $msgStmt = $db->prepare("
                        INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                        VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
                    ");
                    $msgStmt->execute([$messageId, $invitation['conversation_id'], $_SESSION['user_id'], $systemMessage]);
                    
                    $success = "Berhasil bergabung ke group '" . htmlspecialchars($invitation['group_name']) . "'!";
                    $_SESSION['success'] = $success;
                    header("Location: dashboard.php?conversation_id=" . $invitation['conversation_id']);
                    exit;
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

// If code is provided in URL
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $invitation = getGroupInvitation($db, $code);
    
    if (!$invitation) {
        $error = "Kode undangan tidak valid atau sudah kadaluarsa!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Group - Chat App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 min-h-screen">
    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-600 to-blue-600 flex items-center justify-center">
                        <i class="fas fa-user-plus text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Join Group</h1>
                        <p class="text-gray-400 text-sm">Gabung group dengan kode undangan</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-300 hidden sm:block"><?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2.5 rounded-xl transition duration-200 font-semibold flex items-center space-x-2">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <div class="max-w-md mx-auto">
            <?php if ($error): ?>
                <div class="bg-red-500/20 border border-red-500 text-red-200 p-4 rounded-xl mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-500/20 border border-green-500 text-green-200 p-4 rounded-xl mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Join Form -->
            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 mb-6">
                <h2 class="text-xl font-bold text-white mb-6 text-center">Masuk ke Group</h2>
                
                <form method="POST">
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">
                            <i class="fas fa-ticket-alt mr-2"></i>Kode Undangan
                        </label>
                        <input 
                            type="text" 
                            name="code" 
                            value="<?php echo htmlspecialchars($code); ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-center text-lg font-mono tracking-wider"
                            placeholder="Masukkan kode undangan"
                            required
                            maxlength="10"
                            autocomplete="off"
                        >
                    </div>
                    
                    <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-600 hover:from-blue-800 hover:to-blue-800 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-lg">
                        <i class="fas fa-sign-in-alt mr-2"></i>Gabung Group
                    </button>
                </form>
            </div>

            <!-- Invitation Info -->
            <?php if ($invitation && !$success): ?>
            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4 text-center">Informasi Group</h3>
                
                <div class="space-y-4">
                    <div class="flex items-center justify-center">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-r from-green-500 to-blue-600 flex items-center justify-center">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <h4 class="text-xl font-bold text-white"><?php echo htmlspecialchars($invitation['group_name']); ?></h4>
                        <p class="text-gray-400">Dibuat oleh <?php echo htmlspecialchars($invitation['creator_name']); ?></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                            <div class="text-blue-400 font-semibold">Kode</div>
                            <div class="text-white font-mono"><?php echo $invitation['code']; ?></div>
                        </div>
                        <div class="bg-gray-700/50 rounded-lg p-3 text-center">
                            <div class="text-blue-400 font-semibold">Digunakan</div>
                            <div class="text-white">
                                <?php echo $invitation['used_count']; ?>
                                <?php if ($invitation['max_uses']): ?>
                                    / <?php echo $invitation['max_uses']; ?>
                                <?php else: ?>
                                    <span class="text-gray-400">∞</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($invitation['expires_at']): ?>
                        <div class="bg-yellow-500/20 border border-yellow-500/30 rounded-lg p-3 text-center">
                            <div class="text-yellow-400 font-semibold">
                                <i class="fas fa-clock mr-1"></i>Kadaluarsa
                            </div>
                            <div class="text-white text-sm">
                                <?php echo date('d M Y H:i', strtotime($invitation['expires_at'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="bg-blue-500/10 border border-blue-500/30 rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-3 flex items-center justify-center">
                    <i class="fas fa-question-circle mr-2"></i>Cara Bergabung
                </h3>
                <div class="space-y-2 text-sm text-gray-300">
                    <div class="flex items-start space-x-2">
                        <i class="fas fa-arrow-right text-blue-400 mt-1"></i>
                        <span>Dapatkan kode undangan dari admin group</span>
                    </div>
                    <div class="flex items-start space-x-2">
                        <i class="fas fa-arrow-right text-blue-400 mt-1"></i>
                        <span>Masukkan kode undangan di atas</span>
                    </div>
                    <div class="flex items-start space-x-2">
                        <i class="fas fa-arrow-right text-blue-400 mt-1"></i>
                        <span>Klik tombol "Gabung Group"</span>
                    </div>
                    <div class="flex items-start space-x-2">
                        <i class="fas fa-arrow-right text-blue-400 mt-1"></i>
                        <span>Anda akan otomatis masuk ke group</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.bg-red-500\\/20, .bg-green-500\\/20');
            messages.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => {
                    if (msg.parentNode) {
                        msg.remove();
                    }
                }, 300);
            });
        }, 5000);

        // Focus on code input
        document.querySelector('input[name="code"]').focus();
    </script>
</body>
</html>
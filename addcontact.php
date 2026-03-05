<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// Check if user is logged in
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

// Handle add contact
$error = '';
$success = '';
$contactPhone = '';
$contactName = '';

if (isset($_POST['add_contact'])) {
    $contactPhone = trim($_POST['contact_phone']);
    $contactName = trim($_POST['contact_name']);
    
    if (!empty($contactPhone) && !empty($contactName)) {
        // Validate phone number
        if (!preg_match('/^\d{10,15}$/', $contactPhone)) {
            $error = "Harap masukkan nomor telepon yang valid (10-15 digit)";
        } else {
            try {
                // Check if contact already exists for this user
                $checkStmt = $db->prepare("SELECT id FROM contacts WHERE user_id = ? AND contact_phone = ?");
                $checkStmt->execute([$_SESSION['user_id'], $contactPhone]);
                
                if ($checkStmt->fetch()) {
                    $error = "Kontak dengan nomor ini sudah ada!";
                } else {
                    // Check if the phone number is registered in users table
                    $userCheckStmt = $db->prepare("SELECT name FROM users WHERE phone = ?");
                    $userCheckStmt->execute([$contactPhone]);
                    $registeredUser = $userCheckStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Insert new contact
                    $insertStmt = $db->prepare("INSERT INTO contacts (user_id, contact_phone, contact_name, synced_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
                    $insertStmt->execute([$_SESSION['user_id'], $contactPhone, $contactName]);
                    
                    $success = "Kontak berhasil ditambahkan!";
                    
                    // Clear form after successful submission
                    $contactPhone = '';
                    $contactName = '';
                }
            } catch (PDOException $e) {
                $error = "Gagal menambahkan kontak: " . $e->getMessage();
            }
        }
    } else {
        $error = "Harap isi semua field yang wajib!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Kontak - Chat App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 min-h-screen">
    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="contact.php" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-500 to-green-600 flex items-center justify-center">
                        <i class="fas fa-user-plus text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Tambah Kontak Baru</h1>
                        <p class="text-gray-400 text-sm">Tambahkan kontak baru ke daftar Anda</p>
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
        <div class="max-w-2xl mx-auto">
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
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3"></i>
                            <span><?php echo htmlspecialchars($success); ?></span>
                        </div>
                        <div class="flex space-x-2">
                            <a href="contact.php" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition duration-200">
                                Lihat Kontak
                            </a>
                            <a href="addcontact.php" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-sm transition duration-200">
                                Tambah Lagi
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                <!-- Header -->
                <div class="text-center mb-8">
                    <div class="w-20 h-20 rounded-full bg-gradient-to-r from-green-500 to-green-600 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-plus text-white text-2xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-2">Tambah Kontak Baru</h2>
                    <p class="text-gray-400">Tambahkan kontak baru untuk memulai percakapan</p>
                </div>

                <!-- Form -->
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="add_contact" value="1">
                    
                    <!-- Phone Number -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            Nomor Telepon <span class="text-red-400">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-phone text-gray-500"></i>
                            </div>
                            <input 
                                type="tel" 
                                name="contact_phone" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-xl py-3 pl-10 pr-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" 
                                placeholder="Masukkan nomor telepon"
                                pattern="[0-9]{10,15}"
                                required
                                value="<?php echo htmlspecialchars($contactPhone); ?>"
                            >
                        </div>
                        <p class="text-xs text-gray-500 mt-2 flex items-center">
                            <i class="fas fa-info-circle mr-1"></i>
                            Masukkan 10-15 digit tanpa spasi atau karakter khusus
                        </p>
                    </div>
                    
                    <!-- Contact Name -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            Nama Kontak <span class="text-red-400">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-user text-gray-500"></i>
                            </div>
                            <input 
                                type="text" 
                                name="contact_name" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-xl py-3 pl-10 pr-4 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" 
                                placeholder="Masukkan nama kontak"
                                required
                                value="<?php echo htmlspecialchars($contactName); ?>"
                            >
                        </div>
                        <p class="text-xs text-gray-500 mt-2 flex items-center">
                            <i class="fas fa-info-circle mr-1"></i>
                            Masukkan nama lengkap kontak Anda
                        </p>
                    </div>

                    <!-- Tips -->
                    <div class="bg-blue-500/20 border border-blue-500/30 rounded-xl p-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-lightbulb text-yellow-400 mt-1 text-lg"></i>
                            <div>
                                <h4 class="font-semibold text-white text-sm mb-2">Tips Menambahkan Kontak:</h4>
                                <ul class="text-xs text-blue-200 space-y-1">
                                    <li class="flex items-center space-x-2">
                                        <i class="fas fa-check text-xs"></i>
                                        <span>Pastikan nomor telepon sudah benar</span>
                                    </li>
                                    <li class="flex items-center space-x-2">
                                        <i class="fas fa-check text-xs"></i>
                                        <span>Kontak akan muncul di daftar kontak Anda</span>
                                    </li>
                                    <li class="flex items-center space-x-2">
                                        <i class="fas fa-check text-xs"></i>
                                        <span>Jika kontak terdaftar, Anda bisa langsung memulai chat</span>
                                    </li>
                                    <li class="flex items-center space-x-2">
                                        <i class="fas fa-check text-xs"></i>
                                        <span>Jika belum terdaftar, Anda bisa mengundang mereka untuk bergabung</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex space-x-4 pt-4">
                        <a href="contact.php" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-times"></i>
                            <span>Batal</span>
                        </a>
                        <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                            <i class="fas fa-user-plus"></i>
                            <span>Tambah Kontak</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Format phone number as user types
        document.querySelector('input[name="contact_phone"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 15) {
                value = value.substring(0, 15);
            }
            e.target.value = value;
        });

        // Auto-focus on phone input
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.querySelector('input[name="contact_phone"]');
            if (phoneInput) {
                phoneInput.focus();
            }
            
            // Auto-hide success message after 5 seconds
            setTimeout(() => {
                const successMsg = document.querySelector('.bg-green-500\\/20');
                if (successMsg) {
                    successMsg.style.opacity = '0';
                    setTimeout(() => successMsg.remove(), 300);
                }
            }, 5000);
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const phone = document.querySelector('input[name="contact_phone"]').value;
            const name = document.querySelector('input[name="contact_name"]').value;
            
            if (!phone || !name) {
                e.preventDefault();
                alert('Harap isi semua field yang wajib!');
                return;
            }
            
            if (!/^\d{10,15}$/.test(phone)) {
                e.preventDefault();
                alert('Harap masukkan nomor telepon yang valid (10-15 digit)!');
                return;
            }
        });
    </script>
</body>
</html>
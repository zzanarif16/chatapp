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

// Handle profile update
$error = '';
$success = '';

if (isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $about = trim($_POST['about'] ?? '');
    
    // Validate name
    if (empty($name)) {
        $error = "Nama tidak boleh kosong";
    } elseif (strlen($name) < 2) {
        $error = "Nama harus minimal 2 karakter";
    } elseif (strlen($name) > 50) {
        $error = "Nama maksimal 50 karakter";
    }
    
    if (!$error) {
        try {
            // Handle avatar upload
            $avatarUrl = $user['avatar_url'];
            
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatar = $_FILES['avatar'];
                
                // Validate file type
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!in_array($avatar['type'], $allowedTypes)) {
                    $error = "Hanya file gambar (JPEG, JPG, PNG, GIF) yang diizinkan";
                } elseif ($avatar['size'] > 5 * 1024 * 1024) { // 5MB max
                    $error = "Ukuran file terlalu besar (maksimal 5MB)";
                } else {
                    // Create fotoprofil directory if not exists
                    $uploadDir = 'fotoprofil/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $fileExtension = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $filePath = $uploadDir . $fileName;
                    
                    // Move uploaded file
                    if (move_uploaded_file($avatar['tmp_name'], $filePath)) {
                        // Delete old avatar if exists
                        if ($avatarUrl && file_exists($avatarUrl)) {
                            unlink($avatarUrl);
                        }
                        $avatarUrl = $filePath;
                    } else {
                        $error = "Gagal mengupload foto profil";
                    }
                }
            }
            
            if (!$error) {
                // Update user profile
                $updateStmt = $db->prepare("UPDATE users SET name = ?, about = ?, avatar_url = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->execute([$name, $about, $avatarUrl, $_SESSION['user_id']]);
                
                $success = "Profil berhasil diperbarui!";
                
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle delete avatar
if (isset($_POST['delete_avatar'])) {
    try {
        if ($user['avatar_url'] && file_exists($user['avatar_url'])) {
            unlink($user['avatar_url']);
        }
        
        $updateStmt = $db->prepare("UPDATE users SET avatar_url = NULL, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$_SESSION['user_id']]);
        
        $success = "Foto profil berhasil dihapus!";
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profil - Chat App</title>
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
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-purple-500 to-purple-600 flex items-center justify-center">
                        <i class="fas fa-user-edit text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Edit Profil</h1>
                        <p class="text-gray-400 text-sm">Kelola informasi profil Anda</p>
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
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                <!-- Profile Header -->
                <div class="text-center mb-8">
                    <div class="relative inline-block">
                        <!-- Avatar Container - Fixed for perfect circle -->
                        <div class="w-32 h-32 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center mx-auto mb-4 overflow-hidden border-4 border-gray-800">
                            <?php if ($user['avatar_url']): ?>
                                <img 
                                    src="<?php echo htmlspecialchars($user['avatar_url']); ?>" 
                                    alt="Profile Photo" 
                                    class="w-full h-full object-cover"
                                >
                            <?php else: ?>
                                <i class="fas fa-user text-white text-5xl"></i>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Camera Button -->
                        <div class="absolute bottom-2 right-2">
                            <label for="avatar" class="cursor-pointer bg-blue-500 hover:bg-blue-600 text-white p-3 rounded-full shadow-lg transition duration-200 transform hover:scale-110 border-2 border-gray-800">
                                <i class="fas fa-camera text-sm w-5 h-5"></i>
                            </label>
                        </div>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                    <p class="text-gray-400"><?php echo htmlspecialchars($user['phone']); ?></p>
                </div>

                <!-- Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <!-- Hidden File Input -->
                    <input type="file" id="avatar" name="avatar" class="hidden" accept="image/*">
                    
                    <!-- Avatar Preview -->
                    <div id="avatarPreview" class="text-center hidden">
                        <div class="w-24 h-24 rounded-full bg-gray-700 mx-auto mb-2 overflow-hidden border-2 border-blue-500">
                            <img id="previewImage" class="w-full h-full object-cover">
                        </div>
                        <p class="text-green-400 text-sm" id="fileName"></p>
                    </div>

                    <!-- Name Section -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-user mr-2"></i>Nama <span class="text-red-400">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="name" 
                            value="<?php echo htmlspecialchars($user['name']); ?>" 
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nama lengkap Anda"
                            required
                            minlength="2"
                            maxlength="50"
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Nama akan ditampilkan kepada kontak Anda (2-50 karakter)
                        </p>
                    </div>

                    <!-- About Section -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-info-circle mr-2"></i>Tentang Saya
                        </label>
                        <textarea 
                            name="about" 
                            rows="4" 
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-none"
                            placeholder="Ceritakan sedikit tentang diri Anda..."
                            maxlength="500"
                        ><?php echo htmlspecialchars($user['about'] ?? ''); ?></textarea>
                        <div class="flex justify-between items-center mt-2">
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Deskripsi singkat tentang diri Anda
                            </p>
                            <span id="aboutCounter" class="text-xs text-gray-500">0/500</span>
                        </div>
                    </div>

                    <!-- Phone Info (Readonly) -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-phone mr-2"></i>Nomor Telepon
                        </label>
                        <input 
                            type="text" 
                            value="<?php echo htmlspecialchars($user['phone']); ?>" 
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-gray-400 cursor-not-allowed"
                            readonly
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-lock mr-1"></i>
                            Nomor telepon tidak dapat diubah
                        </p>
                    </div>

                    <!-- Stats -->
                    <div class="bg-gray-700/50 rounded-xl p-4">
                        <h4 class="font-semibold text-white text-sm mb-3 flex items-center">
                            <i class="fas fa-chart-bar mr-2"></i>Statistik Profil
                        </h4>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div class="text-center">
                                <p class="text-gray-400">Terdaftar Sejak</p>
                                <p class="text-white font-semibold"><?php echo date('d M Y', strtotime($user['created_at'])); ?></p>
                            </div>
                            <div class="text-center">
                                <p class="text-gray-400">Terakhir Online</p>
                                <p class="text-white font-semibold">
                                    <?php 
                                    if ($user['last_seen']) {
                                        echo date('d M Y H:i', strtotime($user['last_seen']));
                                    } else {
                                        echo 'Belum pernah';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex space-x-4 pt-4">
                        <a href="dashboard.php" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-times"></i>
                            <span>Batal</span>
                        </a>
                        <button type="submit" class="flex-1 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                            <i class="fas fa-save"></i>
                            <span>Simpan Perubahan</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Danger Zone -->
            <div class="bg-red-500/10 border border-red-500/30 rounded-2xl p-6 mt-6">
                <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-exclamation-triangle mr-2 text-red-400"></i>
                    Zona Berbahaya
                </h3>
                <p class="text-gray-400 text-sm mb-4">
                    Tindakan ini tidak dapat dibatalkan. Hapus foto profil Anda jika diperlukan.
                </p>
                <?php if ($user['avatar_url']): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="delete_avatar" value="1">
                        <button type="submit" onclick="return confirm('Apakah Anda yakin ingin menghapus foto profil?')" class="bg-red-500 hover:bg-red-600 text-white py-2 px-4 rounded-xl transition duration-200 font-semibold flex items-center space-x-2">
                            <i class="fas fa-trash"></i>
                            <span>Hapus Foto Profil</span>
                        </button>
                    </form>
                <?php else: ?>
                    <button disabled class="bg-gray-600 text-gray-400 py-2 px-4 rounded-xl font-semibold flex items-center space-x-2 cursor-not-allowed">
                        <i class="fas fa-trash"></i>
                        <span>Tidak Ada Foto Profil</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Avatar preview functionality
        document.getElementById('avatar').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatarPreview');
                    const previewImage = document.getElementById('previewImage');
                    const fileName = document.getElementById('fileName');
                    
                    previewImage.src = e.target.result;
                    fileName.textContent = file.name;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });

        // About character counter
        const aboutTextarea = document.querySelector('textarea[name="about"]');
        const aboutCounter = document.getElementById('aboutCounter');
        
        if (aboutTextarea && aboutCounter) {
            // Initialize counter
            aboutCounter.textContent = aboutTextarea.value.length + '/500';
            
            // Update counter on input
            aboutTextarea.addEventListener('input', function() {
                aboutCounter.textContent = this.value.length + '/500';
            });
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const nameInput = document.querySelector('input[name="name"]');
            const name = nameInput.value.trim();
            
            if (!name) {
                e.preventDefault();
                alert('Nama tidak boleh kosong!');
                nameInput.focus();
                return;
            }
            
            if (name.length < 2) {
                e.preventDefault();
                alert('Nama harus minimal 2 karakter!');
                nameInput.focus();
                return;
            }
            
            if (name.length > 50) {
                e.preventDefault();
                alert('Nama maksimal 50 karakter!');
                nameInput.focus();
                return;
            }
        });

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

        // Auto-focus name field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.querySelector('input[name="name"]');
            if (nameInput && !nameInput.value.trim()) {
                nameInput.focus();
            }
        });
    </script>
</body>
</html>
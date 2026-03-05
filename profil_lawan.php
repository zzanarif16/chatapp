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
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentUser) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get target user ID from URL
$targetUserId = $_GET['user_id'] ?? '';

if (empty($targetUserId)) {
    header('Location: dashboard.php');
    exit;
}

// Get target user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        $_SESSION['error'] = "User tidak ditemukan";
        header('Location: dashboard.php');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle add contact form submission
if (isset($_POST['add_contact'])) {
    $contactPhone = $_POST['contact_phone'] ?? '';
    $contactName = $_POST['contact_name'] ?? '';
    
    if (empty($contactPhone) || empty($contactName)) {
        $_SESSION['error'] = "Nomor telepon dan nama kontak tidak boleh kosong";
    } else {
        try {
            // Check if contact already exists
            $checkStmt = $db->prepare("SELECT * FROM contacts WHERE user_id = ? AND contact_phone = ?");
            $checkStmt->execute([$_SESSION['user_id'], $contactPhone]);
            $existingContact = $checkStmt->fetch();
            
            if ($existingContact) {
                $_SESSION['error'] = "Kontak sudah ada dalam daftar";
            } else {
                // Insert new contact
                $insertStmt = $db->prepare("INSERT INTO contacts (user_id, contact_phone, contact_name) VALUES (?, ?, ?)");
                $insertStmt->execute([$_SESSION['user_id'], $contactPhone, $contactName]);
                
                $_SESSION['success'] = "Kontak berhasil ditambahkan!";
                
                // Redirect to refresh page
                header("Location: profil_lawan.php?user_id=" . $targetUserId);
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Check if target user is in contacts
try {
    $contactStmt = $db->prepare("SELECT * FROM contacts WHERE user_id = ? AND contact_phone = ?");
    $contactStmt->execute([$_SESSION['user_id'], $targetUser['phone']]);
    $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
    
    $isContact = $contact ? true : false;
    $contactName = $contact ? $contact['contact_name'] : null;
} catch (PDOException $e) {
    $isContact = false;
    $contactName = null;
}

// Get conversation between current user and target user
try {
    $convStmt = $db->prepare("
        SELECT c.id 
        FROM conversations c
        JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ?
        JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ?
        WHERE c.type = 'private'
    ");
    $convStmt->execute([$_SESSION['user_id'], $targetUserId]);
    $conversation = $convStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conversation = null;
}

// Check for session messages
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - <?php echo htmlspecialchars($targetUser['name']); ?> - Chat App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 min-h-screen">
    <!-- Error/Success Messages -->
    <?php if ($error): ?>
        <div class="fixed top-4 right-4 bg-red-600 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle mr-3"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="fixed top-4 right-4 bg-green-600 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-3"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php<?php echo $conversation ? '?conversation_id=' . $conversation['id'] : ''; ?>" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center">
                        <i class="fas fa-user text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Profil Kontak</h1>
                        <p class="text-gray-400 text-sm">Informasi profil <?php echo htmlspecialchars($targetUser['name']); ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-300 hidden sm:block"><?php echo htmlspecialchars($currentUser['name']); ?></span>
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
            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                <!-- Profile Header -->
                <div class="text-center mb-8">
                    <!-- Avatar -->
                    <div class="w-32 h-32 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center mx-auto mb-4 overflow-hidden border-4 border-gray-800">
                        <?php if ($targetUser['avatar_url']): ?>
                            <img 
                                src="<?php echo htmlspecialchars($targetUser['avatar_url']); ?>" 
                                alt="Profile Photo" 
                                class="w-full h-full object-cover"
                            >
                        <?php else: ?>
                            <i class="fas fa-user text-white text-5xl"></i>
                        <?php endif; ?>
                    </div>
                    
                    <h2 class="text-2xl font-bold text-white mb-2">
                        <?php echo htmlspecialchars($isContact ? $contactName : $targetUser['name']); ?>
                    </h2>
                    
                    <?php if ($isContact && $contactName !== $targetUser['name']): ?>
                        <p class="text-gray-400 text-sm mb-1">
                            Nama asli: <?php echo htmlspecialchars($targetUser['name']); ?>
                        </p>
                    <?php endif; ?>
                    
                    <p class="text-gray-400"><?php echo htmlspecialchars($targetUser['phone']); ?></p>
                    
                    <!-- Status Online/Offline -->
                    <div class="mt-2">
                        <?php if (isUserOnline($targetUser['last_seen'])): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400 border border-green-500/30">
                                <span class="w-2 h-2 bg-green-400 rounded-full mr-2"></span>
                                Online
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-500/20 text-gray-400 border border-gray-500/30">
                                <span class="w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                                Offline - Terakhir online: <?php echo $targetUser['last_seen'] ? date('d M Y H:i', strtotime($targetUser['last_seen'])) : 'Belum pernah'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- About Section -->
                <?php if ($targetUser['about']): ?>
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-info-circle mr-2"></i>Tentang
                        </label>
                        <div class="bg-gray-700/50 rounded-xl p-4">
                            <p class="text-white"><?php echo htmlspecialchars($targetUser['about']); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-info-circle mr-2"></i>Tentang
                        </label>
                        <div class="bg-gray-700/50 rounded-xl p-4 text-center">
                            <p class="text-gray-400">Tidak ada deskripsi</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Contact Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-user mr-2"></i>Nama
                        </label>
                        <div class="bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white">
                            <?php echo htmlspecialchars($targetUser['name']); ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-phone mr-2"></i>Nomor Telepon
                        </label>
                        <div class="bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white">
                            <?php echo htmlspecialchars($targetUser['phone']); ?>
                        </div>
                    </div>
                </div>

                <!-- Contact Status -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-white mb-3">
                        <i class="fas fa-address-book mr-2"></i>Status Kontak
                    </label>
                    <div class="bg-gray-700/50 rounded-xl p-4">
                        <?php if ($isContact): ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center">
                                        <i class="fas fa-check text-white"></i>
                                    </div>
                                    <div>
                                        <p class="text-white font-semibold">Sudah menjadi kontak</p>
                                        <p class="text-gray-400 text-sm">Disimpan sebagai: <?php echo htmlspecialchars($contactName); ?></p>
                                    </div>
                                </div>
                                <a href="contact.php" class="text-blue-400 hover:text-blue-300 text-sm font-medium">
                                    Lihat di Kontak
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-yellow-500 rounded-full flex items-center justify-center">
                                        <i class="fas fa-exclamation text-white"></i>
                                    </div>
                                    <div>
                                        <p class="text-white font-semibold">Belum menjadi kontak</p>
                                        <p class="text-gray-400 text-sm">Tambahkan ke daftar kontak Anda</p>
                                    </div>
                                </div>
                                <button onclick="openAddContactModal('<?php echo $targetUser['phone']; ?>', '<?php echo htmlspecialchars($targetUser['name']); ?>')" 
                                        class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-2 px-4 rounded-xl text-sm font-semibold transition duration-200">
                                    Tambah Kontak
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats -->
                <div class="bg-gray-700/50 rounded-xl p-4 mb-6">
                    <h4 class="font-semibold text-white text-sm mb-3 flex items-center">
                        <i class="fas fa-chart-bar mr-2"></i>Statistik
                    </h4>
                    <div class="grid grid-cols-1 gap-4 text-sm">
                        <div class="text-center">
                            <p class="text-gray-400">Terdaftar Sejak</p>
                            <p class="text-white font-semibold"><?php echo date('d M Y', strtotime($targetUser['created_at'])); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex space-x-4">
                    <?php if ($conversation): ?>
                        <a href="dashboard.php?conversation_id=<?php echo $conversation['id']; ?>" class="flex-1 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-comments"></i>
                            <span>Kirim Pesan</span>
                        </a>
                    <?php else: ?>
                        <a href="dashboard.php?start_chat=1&contact_phone=<?php echo urlencode($targetUser['phone']); ?>" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-comment"></i>
                            <span>Mulai Chat</span>
                        </a>
                    <?php endif; ?>
                    
                    <?php if (!$isContact): ?>
                        <button onclick="openAddContactModal('<?php echo $targetUser['phone']; ?>', '<?php echo htmlspecialchars($targetUser['name']); ?>')" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-user-plus"></i>
                            <span>Tambah Kontak</span>
                        </button>
                    <?php else: ?>
                        <a href="contact.php" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-check"></i>
                            <span>Sudah Kontak</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Contact Modal -->
    <div id="addContactModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-user-plus text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-white">Tambah Kontak</h3>
                    <p class="text-gray-400 text-sm">Tambahkan nomor ini ke daftar kontak Anda</p>
                </div>
            </div>
            
            <form method="POST">
                <input type="hidden" name="add_contact" value="1">
                <input type="hidden" id="modalContactPhone" name="contact_phone">
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Nomor Telepon</label>
                    <input 
                        type="text" 
                        id="modalContactPhoneDisplay" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        readonly
                    >
                </div>
                
                <div class="mb-6">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Nama Kontak</label>
                    <input 
                        type="text" 
                        id="modalContactName" 
                        name="contact_name"
                        placeholder="Masukkan nama kontak"
                        class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        required
                    >
                    <p class="text-xs text-gray-500 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Nama ini akan ditampilkan di daftar kontak Anda
                    </p>
                </div>
                
                <div class="flex space-x-3">
                    <button type="button" onclick="closeAddContactModal()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Tambah Kontak
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Add Contact Modal Functions
        function openAddContactModal(phone, currentName) {
            document.getElementById('modalContactPhone').value = phone;
            document.getElementById('modalContactPhoneDisplay').value = phone;
            document.getElementById('modalContactName').value = currentName === phone ? '' : currentName;
            document.getElementById('addContactModal').classList.remove('hidden');
        }

        function closeAddContactModal() {
            document.getElementById('addContactModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('addContactModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddContactModal();
            }
        });

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.fixed');
            messages.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => {
                    if (msg.parentNode) {
                        msg.remove();
                    }
                }, 300);
            });
        }, 5000);

        // Auto-focus name field in modal when opened
        document.addEventListener('DOMContentLoaded', function() {
            const addContactModal = document.getElementById('addContactModal');
            const modalContactName = document.getElementById('modalContactName');
            
            if (addContactModal && modalContactName) {
                // Observe modal visibility changes
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                            if (!addContactModal.classList.contains('hidden')) {
                                setTimeout(() => {
                                    modalContactName.focus();
                                }, 300);
                            }
                        }
                    });
                });
                
                observer.observe(addContactModal, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            }
        });

        // Prevent form submission if name is empty
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const contactName = document.getElementById('modalContactName')?.value.trim();
            if (!contactName) {
                e.preventDefault();
                alert('Nama kontak tidak boleh kosong!');
                document.getElementById('modalContactName').focus();
            }
        });
    </script>
</body>
</html>
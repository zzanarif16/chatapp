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

// Handle create group
$error = '';
$success = '';
$groupName = '';
$groupAbout = '';
$selectedContacts = [];
$groupAvatarUrl = null;

if (isset($_POST['create_group'])) {
    $groupName = trim($_POST['group_name']);
    $groupAbout = trim($_POST['group_about'] ?? '');
    $selectedContacts = $_POST['contacts'] ?? [];
    
    if (empty($groupName)) {
        $error = "Nama group tidak boleh kosong!";
    } elseif (strlen($groupName) < 3) {
        $error = "Nama group minimal 3 karakter!";
    } elseif (count($selectedContacts) < 1) {
        $error = "Pilih minimal 1 kontak untuk group!";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Handle group avatar upload
            if (isset($_FILES['group_avatar']) && $_FILES['group_avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = handleGroupAvatarUpload($_FILES['group_avatar']);
                if ($uploadResult['success']) {
                    $groupAvatarUrl = $uploadResult['file_url'];
                } else {
                    throw new Exception($uploadResult['message']);
                }
            }
            
            // Create conversation
            $conversationId = generateUuid();
            $stmt = $db->prepare("
                INSERT INTO conversations (id, type, title, about, avatar_url, created_by, created_at, updated_at) 
                VALUES (?, 'group', ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$conversationId, $groupName, $groupAbout, $groupAvatarUrl, $_SESSION['user_id']]);
            
            // Add creator as admin
            $stmt = $db->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) 
                VALUES (?, ?, 'admin', NOW())
            ");
            $stmt->execute([$conversationId, $_SESSION['user_id']]);
            
            // Add selected contacts
            $stmt = $db->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) 
                VALUES (?, ?, 'member', NOW())
            ");
            
            foreach ($selectedContacts as $contactPhone) {
                // Get user ID from phone
                $userStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
                $userStmt->execute([$contactPhone]);
                $contactUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($contactUser) {
                    $stmt->execute([$conversationId, $contactUser['id']]);
                }
            }
            
            // Create welcome message
            $messageId = generateUuid();
            $welcomeMessage = "Grup \"$groupName\" telah dibuat. Selamat bergabung!";
            
            $msgStmt = $db->prepare("
                INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
            ");
            $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $welcomeMessage]);
            
            // Update last message
            $updateStmt = $db->prepare("UPDATE conversations SET last_message_id = ? WHERE id = ?");
            $updateStmt->execute([$messageId, $conversationId]);
            
            $db->commit();
            
            // Redirect langsung ke dashboard setelah berhasil membuat group
            header('Location: dashboard.php');
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Gagal membuat group: " . $e->getMessage();
        }
    }
}

// Function to handle group avatar upload for new group
function handleGroupAvatarUpload($file) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    // Validate file type
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Hanya file gambar yang diizinkan (JPEG, PNG, GIF, WebP)'];
    }
    
    // Validate file size
    if ($file['size'] > $maxFileSize) {
        return ['success' => false, 'message' => 'Ukuran file maksimal 5MB'];
    }
    
    // Validate upload success
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Terjadi kesalahan saat upload file'];
    }
    
    // Create uploads directory if not exists
    $uploadDir = 'uploads/group_avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'group_' . uniqid() . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // Return relative URL for web access
        $fileUrl = $filePath;
        return ['success' => true, 'file_url' => $fileUrl];
    } else {
        return ['success' => false, 'message' => 'Gagal menyimpan file'];
    }
}

// Get user's contacts
try {
    $contactsStmt = $db->prepare("
        SELECT 
            c.*, 
            u.name as contact_user_name, 
            u.avatar_url, 
            u.about,
            u.id as contact_user_id,
            CASE 
                WHEN u.id IS NOT NULL THEN 'terdaftar' 
                ELSE 'belum_terdaftar' 
            END as status
        FROM contacts c
        LEFT JOIN users u ON c.contact_phone = u.phone
        WHERE c.user_id = ?
        ORDER BY c.contact_name
    ");
    $contactsStmt->execute([$_SESSION['user_id']]);
    $contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $contacts = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Group Baru - Chat App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 min-h-screen">
    <!-- Custom Alert Box -->
    <div id="customAlert" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex items-center mb-4">
                <i id="alertIcon" class="fas fa-exclamation-triangle text-yellow-500 text-2xl mr-3"></i>
                <h3 id="alertTitle" class="text-xl font-bold text-white">Peringatan</h3>
            </div>
            <p id="alertMessage" class="text-gray-300 mb-6">Pesan alert akan muncul di sini.</p>
            <div class="flex space-x-3">
                <button id="alertCancel" onclick="closeCustomAlert()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                    Batal
                </button>
                <button id="alertConfirm" onclick="confirmCustomAlert()" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Success Notification -->
    <div id="successNotification" class="fixed top-4 right-4 bg-green-500/20 border border-green-500 text-green-200 p-4 rounded-xl hidden z-50 max-w-sm">
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-3"></i>
            <span id="successMessage">Operasi berhasil!</span>
        </div>
    </div>

    <!-- Header -->
    <header class="bg-gray-800 border-b border-gray-700 sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-orange-600 to-orange-600 flex items-center justify-center">
                        <i class="fas fa-users text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Buat Group Baru</h1>
                        <p class="text-gray-400 text-sm">Buat group chat dengan kontak Anda</p>
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
                <div class="bg-red-500/20 border border-red-500 text-red-200 p-4 rounded-xl mb-6 message-alert">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                <!-- Header -->
                <div class="text-center mb-8">
                    <div class="relative mx-auto mb-4 w-24 h-24">
                        <div id="avatarPreview" class="w-24 h-24 rounded-full bg-gradient-to-r from-orange-600 to-orange-600 flex items-center justify-center overflow-hidden border-4 border-gray-700 cursor-pointer">
                            <i class="fas fa-users text-white text-2xl"></i>
                        </div>
                        <button type="button" onclick="document.getElementById('groupAvatarInput').click()" 
                                class="absolute -bottom-2 -right-2 w-8 h-8 bg-blue-500 hover:bg-blue-600 rounded-full flex items-center justify-center text-white transition duration-200">
                            <i class="fas fa-camera text-sm"></i>
                        </button>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-2">Buat Group Baru</h2>
                    <p class="text-gray-400">Pilih kontak dan beri nama group Anda</p>
                </div>

                <!-- Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-6" id="createGroupForm">
                    <input type="hidden" name="create_group" value="1">
                    <input type="file" name="group_avatar" id="groupAvatarInput" class="hidden" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                    
                    <!-- Group Name -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-tag mr-2"></i>Nama Group <span class="text-red-400">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="group_name" 
                            value="<?php echo htmlspecialchars($groupName); ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nama group"
                            required
                            minlength="3"
                            maxlength="100"
                            id="groupNameInput"
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Nama group akan ditampilkan kepada semua anggota (3-100 karakter)
                        </p>
                    </div>

                    <!-- Group Description -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-info-circle mr-2"></i>Deskripsi Group
                        </label>
                        <textarea 
                            name="group_about" 
                            rows="3"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Tambahkan deskripsi group (opsional)"
                            maxlength="500"
                        ><?php echo htmlspecialchars($groupAbout); ?></textarea>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Deskripsi group akan membantu anggota memahami tujuan group (maksimal 500 karakter)
                        </p>
                    </div>

                    <!-- Contact Selection -->
                    <div>
                        <label class="block text-sm font-medium text-white mb-3">
                            <i class="fas fa-user-plus mr-2"></i>Pilih Anggota <span class="text-red-400">*</span>
                        </label>
                        
                        <!-- Search Contacts -->
                        <div class="mb-4">
                            <input 
                                type="text" 
                                id="searchContacts"
                                class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-2 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                placeholder="Cari kontak..."
                            >
                        </div>

                        <!-- Selected Count -->
                        <div id="selectedCount" class="bg-blue-500/20 border border-blue-500/30 rounded-xl p-3 mb-4 hidden">
                            <p class="text-blue-300 text-sm">
                                <i class="fas fa-users mr-2"></i>
                                <span id="countText">0</span> kontak terpilih
                            </p>
                        </div>

                        <!-- Contacts List -->
                        <div class="bg-gray-700/50 border border-gray-600 rounded-xl p-4 max-h-80 overflow-y-auto">
                            <?php if (empty($contacts)): ?>
                                <div class="text-center py-8 text-gray-400">
                                    <i class="fas fa-address-book text-3xl mb-3 opacity-50"></i>
                                    <p class="text-sm">Belum ada kontak</p>
                                    <p class="text-xs mt-1">Tambahkan kontak terlebih dahulu</p>
                                    <a href="addcontact.php" class="inline-block mt-3 text-blue-400 hover:text-blue-300 text-sm">
                                        <i class="fas fa-plus mr-1"></i>Tambah Kontak
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="space-y-2" id="contactsList">
                                    <?php foreach ($contacts as $contact): ?>
                                        <?php if ($contact['status'] === 'terdaftar'): ?>
                                            <div class="contact-item flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-600 transition duration-150 cursor-pointer" 
                                                data-phone="<?php echo htmlspecialchars($contact['contact_phone']); ?>"
                                                data-name="<?php echo htmlspecialchars($contact['contact_name']); ?>">
                                                <input 
                                                    type="checkbox" 
                                                    name="contacts[]" 
                                                    value="<?php echo htmlspecialchars($contact['contact_phone']); ?>"
                                                    class="contact-checkbox hidden"
                                                    id="contact_<?php echo $contact['id']; ?>"
                                                >
                                                <div class="flex-1 flex items-center space-x-3">
                                                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-green-400 to-blue-500 flex items-center justify-center flex-shrink-0">
                                                        <?php if ($contact['avatar_url']): ?>
                                                            <img src="<?php echo htmlspecialchars($contact['avatar_url']); ?>" alt="Avatar" class="w-10 h-10 rounded-full">
                                                        <?php else: ?>
                                                            <i class="fas fa-user text-white text-sm"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-white font-medium text-sm truncate">
                                                            <?php echo htmlspecialchars($contact['contact_name']); ?>
                                                        </p>
                                                        <p class="text-gray-400 text-xs truncate">
                                                            <?php echo htmlspecialchars($contact['contact_phone']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="w-5 h-5 border-2 border-gray-400 rounded flex items-center justify-center transition duration-150">
                                                    <i class="fas fa-check text-white text-xs opacity-0"></i>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Pilih minimal 1 kontak yang sudah terdaftar
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex space-x-4 pt-4">
                        <a href="dashboard.php" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold text-center flex items-center justify-center space-x-2">
                            <i class="fas fa-times"></i>
                            <span>Batal</span>
                        </a>
                        <button type="submit" class="flex-1 bg-gradient-to-r from-orange-600 to-orange-600 hover:from-orange-800 hover:to-orange-800 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                            <i class="fas fa-users"></i>
                            <span>Buat Group</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Custom Alert System
        let alertCallback = null;
        let alertType = 'info';

        function showCustomAlert(title, message, type = 'warning', callback = null) {
            const alert = document.getElementById('customAlert');
            const alertTitle = document.getElementById('alertTitle');
            const alertMessage = document.getElementById('alertMessage');
            const alertIcon = document.getElementById('alertIcon');
            const alertCancel = document.getElementById('alertCancel');
            const alertConfirm = document.getElementById('alertConfirm');

            alertTitle.textContent = title;
            alertMessage.innerHTML = message;
            alertCallback = callback;
            alertType = type;

            // Set icon based on type
            switch(type) {
                case 'success':
                    alertIcon.className = 'fas fa-check-circle text-green-500 text-2xl mr-3';
                    alertConfirm.className = 'flex-1 bg-green-500 hover:bg-green-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium';
                    break;
                case 'error':
                    alertIcon.className = 'fas fa-times-circle text-red-500 text-2xl mr-3';
                    alertConfirm.className = 'flex-1 bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium';
                    break;
                case 'info':
                    alertIcon.className = 'fas fa-info-circle text-blue-500 text-2xl mr-3';
                    alertConfirm.className = 'flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium';
                    break;
                default:
                    alertIcon.className = 'fas fa-exclamation-triangle text-yellow-500 text-2xl mr-3';
                    alertConfirm.className = 'flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium';
            }

            // Show/hide cancel button based on callback
            if (callback) {
                alertCancel.classList.remove('hidden');
            } else {
                alertCancel.classList.add('hidden');
            }

            alert.classList.remove('hidden');
        }

        function closeCustomAlert() {
            document.getElementById('customAlert').classList.add('hidden');
            alertCallback = null;
        }

        function confirmCustomAlert() {
            if (alertCallback && typeof alertCallback === 'function') {
                alertCallback();
            }
            closeCustomAlert();
        }

        function showSuccessNotification(message) {
            const notification = document.getElementById('successNotification');
            const messageElement = document.getElementById('successMessage');
            
            messageElement.textContent = message;
            notification.classList.remove('hidden');
            
            setTimeout(() => {
                notification.classList.add('hidden');
            }, 3000);
        }

        // Contact selection functionality
        document.addEventListener('DOMContentLoaded', function() {
            const contactItems = document.querySelectorAll('.contact-item');
            const selectedCount = document.getElementById('selectedCount');
            const countText = document.getElementById('countText');
            const searchInput = document.getElementById('searchContacts');
            const avatarInput = document.getElementById('groupAvatarInput');
            const avatarPreview = document.getElementById('avatarPreview');
            const createGroupForm = document.getElementById('createGroupForm');
            const groupNameInput = document.getElementById('groupNameInput');
            
            let selectedContacts = [];
            
            // Toggle contact selection
            contactItems.forEach(item => {
                item.addEventListener('click', function() {
                    const checkbox = this.querySelector('.contact-checkbox');
                    const checkIcon = this.querySelector('.fa-check');
                    const border = this.querySelector('.border-gray-400');
                    
                    if (checkbox.checked) {
                        // Deselect
                        checkbox.checked = false;
                        checkIcon.classList.add('opacity-0');
                        border.classList.remove('border-green-500', 'bg-green-500');
                        border.classList.add('border-gray-400');
                        
                        // Remove from selected array
                        const index = selectedContacts.indexOf(checkbox.value);
                        if (index > -1) {
                            selectedContacts.splice(index, 1);
                        }
                    } else {
                        // Select
                        checkbox.checked = true;
                        checkIcon.classList.remove('opacity-0');
                        border.classList.remove('border-gray-400');
                        border.classList.add('border-green-500', 'bg-green-500');
                        
                        // Add to selected array
                        selectedContacts.push(checkbox.value);
                    }
                    
                    updateSelectedCount();
                });
            });
            
            // Update selected count display
            function updateSelectedCount() {
                const count = selectedContacts.length;
                if (count > 0) {
                    selectedCount.classList.remove('hidden');
                    countText.textContent = count;
                } else {
                    selectedCount.classList.add('hidden');
                }
            }
            
            // Search contacts
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                contactItems.forEach(item => {
                    const name = item.getAttribute('data-name').toLowerCase();
                    const phone = item.getAttribute('data-phone').toLowerCase();
                    
                    if (name.includes(searchTerm) || phone.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
            
            // Avatar preview functionality
            avatarInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        showCustomAlert(
                            'Format File Tidak Didukung',
                            'Hanya file gambar yang diizinkan (JPEG, PNG, GIF, WebP)',
                            'error'
                        );
                        this.value = '';
                        return;
                    }
                    
                    // Validate file size (5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        showCustomAlert(
                            'File Terlalu Besar',
                            'Ukuran file maksimal 5MB',
                            'error'
                        );
                        this.value = '';
                        return;
                    }
                    
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        // Create image element for preview
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'w-full h-full object-cover';
                        
                        // Clear previous preview
                        avatarPreview.innerHTML = '';
                        avatarPreview.appendChild(img);
                    }
                    
                    reader.readAsDataURL(file);
                }
            });
            
            // Form validation
            createGroupForm.addEventListener('submit', function(e) {
                const groupName = groupNameInput.value.trim();
                const selectedCount = selectedContacts.length;
                
                if (!groupName) {
                    e.preventDefault();
                    showCustomAlert(
                        'Nama Group Kosong',
                        'Harap isi nama group!',
                        'error'
                    );
                    return;
                }
                
                if (groupName.length < 3) {
                    e.preventDefault();
                    showCustomAlert(
                        'Nama Group Terlalu Pendek',
                        'Nama group minimal 3 karakter!',
                        'error'
                    );
                    return;
                }
                
                if (selectedCount < 1) {
                    e.preventDefault();
                    showCustomAlert(
                        'Belum Memilih Anggota',
                        'Pilih minimal 1 kontak untuk group!',
                        'error'
                    );
                    return;
                }
                
                // If all validations pass, show loading state
                const submitBtn = createGroupForm.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Membuat Group...';
                submitBtn.disabled = true;
            });
            
            // Auto-focus group name input
            if (groupNameInput) {
                groupNameInput.focus();
            }
        });

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.message-alert');
            messages.forEach(msg => {
                msg.style.opacity = '0';
                setTimeout(() => {
                    if (msg.parentNode) {
                        msg.remove();
                    }
                }, 300);
            });
        }, 5000);
    </script>
</body>
</html>
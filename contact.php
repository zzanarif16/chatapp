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

// Handle delete contact
if (isset($_POST['delete_contact'])) {
    $contactId = $_POST['contact_id'];
    
    try {
        // Verify that the contact belongs to the current user
        $verifyStmt = $db->prepare("SELECT * FROM contacts WHERE id = ? AND user_id = ?");
        $verifyStmt->execute([$contactId, $_SESSION['user_id']]);
        $contactToDelete = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contactToDelete) {
            // Delete the contact
            $deleteStmt = $db->prepare("DELETE FROM contacts WHERE id = ? AND user_id = ?");
            $deleteStmt->execute([$contactId, $_SESSION['user_id']]);
            
            $_SESSION['success'] = "Kontak berhasil dihapus!";
            header("Location: contact.php");
            exit;
        } else {
            $_SESSION['error'] = "Kontak tidak ditemukan atau Anda tidak memiliki izin untuk menghapusnya.";
            header("Location: contact.php");
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: contact.php");
        exit;
    }
}

// Handle start new chat
if (isset($_GET['start_chat'])) {
    $contactPhone = $_GET['contact_phone'];
    
    try {
        // Get contact user ID
        $contactStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $contactStmt->execute([$contactPhone]);
        $contactUser = $contactStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contactUser) {
            // Check if conversation already exists between current user and contact
            $convCheckStmt = $db->prepare("
                SELECT c.id 
                FROM conversations c
                INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ?
                INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ?
                WHERE c.type = 'private'
            ");
            $convCheckStmt->execute([$_SESSION['user_id'], $contactUser['id']]);
            $existingConv = $convCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingConv) {
                // Redirect to dashboard with existing conversation
                header("Location: dashboard.php?conversation_id=" . $existingConv['id']);
                exit;
            } else {
                // Create new conversation
                $conversationId = generateUuid();
                $convStmt = $db->prepare("INSERT INTO conversations (id, type, created_by, created_at, updated_at) VALUES (?, 'private', ?, NOW(), NOW())");
                $convStmt->execute([$conversationId, $_SESSION['user_id']]);
                
                // Add both users to conversation
                $memberStmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                $memberStmt->execute([$conversationId, $_SESSION['user_id']]);
                $memberStmt->execute([$conversationId, $contactUser['id']]);
                
                // Redirect to dashboard with new conversation
                header("Location: dashboard.php?conversation_id=" . $conversationId);
                exit;
            }
        } else {
            $_SESSION['error'] = "Kontak belum terdaftar di sistem. Silakan minta mereka membuat akun terlebih dahulu.";
            header("Location: contact.php");
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: contact.php");
        exit;
    }
}

// Get user's contacts with user info
try {
    $contactsStmt = $db->prepare("
        SELECT 
            c.*, 
            u.name as contact_user_name, 
            u.avatar_url, 
            u.about, 
            u.last_seen,
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

// Handle search contacts
$searchTerm = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchTerm = trim($_GET['search']);
    $filteredContacts = [];
    
    foreach ($contacts as $contact) {
        if (stripos($contact['contact_name'], $searchTerm) !== false || 
            stripos($contact['contact_phone'], $searchTerm) !== false ||
            stripos($contact['contact_user_name'] ?? '', $searchTerm) !== false) {
            $filteredContacts[] = $contact;
        }
    }
    $contacts = $filteredContacts;
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
    <title>Kontak Saya - Chat App</title>
    
    <!-- Cache Control -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Simplified and stable styling */
        .status-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.25rem !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .status-icon {
            display: inline-block !important;
            font-family: 'Font Awesome 6 Free' !important;
            font-weight: 900 !important;
            font-style: normal !important;
            -webkit-font-smoothing: antialiased !important;
            -moz-osx-font-smoothing: grayscale !important;
        }

        .status-container {
            position: relative;
        }

        .status-info {
            display: flex !important;
            align-items: center !important;
            gap: 0.5rem !important;
            flex-wrap: wrap;
        }

        /* Specific protection for online status */
        .last-seen {
            display: inline-block !important;
            opacity: 1 !important;
            visibility: visible !important;
            font-family: inherit !important;
        }

        .online-indicator {
            display: inline-block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .contact-item {
            transition: all 0.2s ease-in-out;
        }

        .contact-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Ensure all status elements stay visible */
        .status-badge,
        .status-info > *,
        .last-seen,
        .online-indicator {
            opacity: 1 !important;
            visibility: visible !important;
        }

        /* Loading states */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Prevent any transitions on status elements */
        .status-info * {
            transition: none !important;
            animation: none !important;
        }

        /* Fix for delete button */
        .delete-contact-btn {
            cursor: pointer !important;
            border: none !important;
            outline: none !important;
        }
    </style>
</head>
<body class="bg-gray-900 min-h-screen">
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="text-center">
                <div class="w-16 h-16 rounded-full bg-red-500 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-white text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Hapus Kontak</h3>
                <p class="text-gray-400 mb-6" id="deleteMessage">Apakah Anda yakin ingin menghapus kontak ini?</p>
                
                <div class="flex space-x-3">
                    <button type="button" id="cancelDelete" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Batal
                    </button>
                    <form id="deleteForm" method="POST" class="flex-1">
                        <input type="hidden" name="contact_id" id="deleteContactId">
                        <input type="hidden" name="delete_contact" value="1">
                        <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                            Hapus
                        </button>
                    </form>
                </div>
            </div>
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
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center">
                        <i class="fas fa-address-book text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Kontak Saya</h1>
                        <p class="text-gray-400 text-sm">Kelola daftar kontak Anda</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-300 hidden sm:block"><?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="addcontact.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-2.5 rounded-xl transition duration-200 font-semibold flex items-center space-x-2">
                        <i class="fas fa-user-plus"></i>
                        <span>Tambah Kontak</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
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

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                    <!-- Stats -->
                    <div class="text-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                        <h3 class="font-semibold text-white text-lg mb-2"><?php echo count($contacts); ?> Kontak</h3>
                        <p class="text-gray-400 text-sm">Total kontak yang tersimpan</p>
                    </div>

                    <!-- Quick Actions -->
                    <div class="space-y-3">
                        <a href="addcontact.php" class="flex items-center space-x-3 p-3 bg-gray-700 hover:bg-gray-600 rounded-xl transition duration-200 group">
                            <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center group-hover:bg-green-600 transition duration-200">
                                <i class="fas fa-user-plus text-white"></i>
                            </div>
                            <div>
                                <p class="font-medium text-white">Tambah Kontak</p>
                                <p class="text-gray-400 text-sm">Tambahkan kontak baru</p>
                            </div>
                        </a>
                        
                        <a href="dashboard.php" class="flex items-center space-x-3 p-3 bg-gray-700 hover:bg-gray-600 rounded-xl transition duration-200 group">
                            <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center group-hover:bg-blue-600 transition duration-200">
                                <i class="fas fa-comments text-white"></i>
                            </div>
                            <div>
                                <p class="font-medium text-white">Kembali Chat</p>
                                <p class="text-gray-400 text-sm">Lihat percakapan</p>
                            </div>
                        </a>
                    </div>

                    <!-- Info -->
                    <div class="mt-6 p-4 bg-blue-500/20 border border-blue-500/30 rounded-xl">
                        <h4 class="font-semibold text-white text-sm mb-2 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i> Informasi
                        </h4>
                        <div class="text-xs text-blue-200 space-y-2">
                            <div class="flex items-center space-x-2">
                                <span class="w-2 h-2 bg-green-400 rounded-full"></span>
                                <span>Terdaftar - Bisa langsung chat</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-2 h-2 bg-yellow-400 rounded-full"></span>
                                <span>Belum terdaftar - Minta buat akun</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contacts List -->
            <div class="lg:col-span-3">
                <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                    <!-- Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 space-y-4 sm:space-y-0">
                        <div>
                            <h2 class="text-xl font-bold text-white">Semua Kontak</h2>
                            <p class="text-gray-400 text-sm">Kelola dan mulai percakapan dengan kontak Anda</p>
                        </div>
                        
                        <!-- Search -->
                        <form method="GET" class="w-full sm:w-64">
                            <div class="relative">
                                <input 
                                    type="text" 
                                    name="search" 
                                    value="<?php echo htmlspecialchars($searchTerm); ?>"
                                    placeholder="Cari kontak..." 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-xl pl-10 pr-4 py-2.5 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                            </div>
                        </form>
                    </div>

                    <!-- Contacts Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="contactsList">
                        <?php if (empty($contacts)): ?>
                            <div class="col-span-2 text-center py-12 text-gray-400">
                                <div class="w-20 h-20 rounded-full bg-gray-700 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-address-book text-3xl"></i>
                                </div>
                                <p class="text-lg font-medium text-white mb-2">Belum ada kontak</p>
                                <p class="text-sm mb-6">Tambahkan kontak untuk memulai chat!</p>
                                <a href="addcontact.php" class="inline-flex items-center space-x-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl transition duration-200 font-semibold">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Tambah Kontak Pertama</span>
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <?php
                                // Cek apakah conversation sudah ada untuk kontak yang terdaftar
                                $existingConv = null;
                                if ($contact['status'] === 'terdaftar') {
                                    $convCheckStmt = $db->prepare("
                                        SELECT c.id 
                                        FROM conversations c
                                        INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id AND cm1.user_id = ?
                                        INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id AND cm2.user_id = ?
                                        WHERE c.type = 'private'
                                    ");
                                    $convCheckStmt->execute([$_SESSION['user_id'], $contact['contact_user_id']]);
                                    $existingConv = $convCheckStmt->fetch(PDO::FETCH_ASSOC);
                                }
                                
                                // Check if user is online
                                $isOnline = isUserOnline($contact['last_seen']);
                                ?>
                                <div class="bg-gray-750 border border-gray-600 rounded-xl p-4 contact-item">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-start space-x-3 flex-1 min-w-0">
                                            <div class="relative flex-shrink-0">
                                                <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-400 to-blue-500 flex items-center justify-center">
                                                    <?php if ($contact['avatar_url']): ?>
                                                        <img src="<?php echo htmlspecialchars($contact['avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                                                    <?php else: ?>
                                                        <i class="fas fa-user text-white"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($isOnline): ?>
                                                    <span class="absolute -bottom-1 -right-1 w-3 h-3 bg-green-500 border-2 border-gray-800 rounded-full online-indicator"></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 min-w-0 status-container">
                                                <h3 class="font-semibold text-white truncate contact-name"><?php echo htmlspecialchars($contact['contact_name']); ?></h3>
                                                <p class="text-gray-400 text-sm truncate contact-phone"><?php echo htmlspecialchars($contact['contact_phone']); ?></p>
                                                <div class="status-info mt-2">
                                                    <?php if ($contact['status'] === 'terdaftar'): ?>
                                                        <span class="status-badge bg-green-500/20 text-green-400 text-xs px-2 py-1 rounded-full">
                                                            <i class="fas fa-check text-xs status-icon"></i>
                                                            <span class="status-text">Terdaftar</span>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge bg-yellow-500/20 text-yellow-400 text-xs px-2 py-1 rounded-full">
                                                            <i class="fas fa-exclamation-triangle text-xs status-icon"></i>
                                                            <span class="status-text">Belum Terdaftar</span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($contact['last_seen']): ?>
                                                        <span class="text-xs <?php echo $isOnline ? 'text-green-500' : 'text-gray-500'; ?> last-seen online-status">
                                                            <?php 
                                                            if ($isOnline) {
                                                                echo '🟢 Online';
                                                            } else {
                                                                echo 'Terakhir dilihat ' . date('j M, H:i', strtotime($contact['last_seen']));
                                                            }
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2 ml-3">
                                            <?php if ($contact['status'] === 'terdaftar'): ?>
                                                <?php if ($existingConv): ?>
                                                    <a href="dashboard.php?conversation_id=<?php echo $existingConv['id']; ?>" 
                                                       class="bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-lg transition duration-200 flex items-center space-x-1 group"
                                                       title="Lanjutkan Chat">
                                                        <i class="fas fa-comment group-hover:scale-110 transition-transform duration-200"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="contact.php?start_chat=1&contact_phone=<?php echo urlencode($contact['contact_phone']); ?>" 
                                                       class="bg-green-500 hover:bg-green-600 text-white p-2 rounded-lg transition duration-200 flex items-center space-x-1 group"
                                                       title="Mulai Chat Baru">
                                                        <i class="fas fa-plus group-hover:scale-110 transition-transform duration-200"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button type="button" class="bg-gray-600 text-gray-400 p-2 rounded-lg cursor-not-allowed flex items-center space-x-1" 
                                                        title="Pengguna belum terdaftar">
                                                    <i class="fas fa-comment"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="delete-contact-btn bg-red-500 hover:bg-red-600 text-white p-2 rounded-lg transition duration-200 flex items-center space-x-1 group"
                                                    data-contact-id="<?php echo $contact['id']; ?>"
                                                    data-contact-name="<?php echo htmlspecialchars($contact['contact_name']); ?>"
                                                    title="Hapus Kontak">
                                                <i class="fas fa-trash group-hover:scale-110 transition-transform duration-200"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php if ($contact['about']): ?>
                                        <p class="text-gray-400 text-sm mt-3 pl-15 line-clamp-2"><?php echo htmlspecialchars($contact['about']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Simple and reliable delete functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - setting up delete buttons');
            
            // Get modal elements
            const deleteModal = document.getElementById('deleteModal');
            const deleteForm = document.getElementById('deleteForm');
            const deleteContactId = document.getElementById('deleteContactId');
            const deleteMessage = document.getElementById('deleteMessage');
            const cancelDelete = document.getElementById('cancelDelete');
            
            // Function to setup delete buttons
            function setupDeleteButtons() {
                console.log('Setting up delete buttons...');
                
                const deleteButtons = document.querySelectorAll('.delete-contact-btn');
                console.log('Found', deleteButtons.length, 'delete buttons');
                
                deleteButtons.forEach(button => {
                    // Remove any existing event listeners
                    button.replaceWith(button.cloneNode(true));
                });
                
                // Get the new buttons after cloning
                const newDeleteButtons = document.querySelectorAll('.delete-contact-btn');
                
                newDeleteButtons.forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const contactId = this.getAttribute('data-contact-id');
                        const contactName = this.getAttribute('data-contact-name');
                        
                        console.log('Delete button clicked - ID:', contactId, 'Name:', contactName);
                        
                        if (contactId && contactName) {
                            deleteContactId.value = contactId;
                            deleteMessage.textContent = `Apakah Anda yakin ingin menghapus kontak "${contactName}"? Tindakan ini tidak dapat dibatalkan.`;
                            deleteModal.classList.remove('hidden');
                            deleteModal.style.display = 'flex';
                        } else {
                            console.error('Missing data attributes on delete button');
                        }
                    });
                });
            }
            
            // Setup delete buttons initially
            setupDeleteButtons();
            
            // Cancel delete button
            cancelDelete.addEventListener('click', function() {
                deleteModal.classList.add('hidden');
                deleteModal.style.display = 'none';
            });
            
            // Close modal when clicking outside
            deleteModal.addEventListener('click', function(e) {
                if (e.target === deleteModal) {
                    deleteModal.classList.add('hidden');
                    deleteModal.style.display = 'none';
                }
            });
            
            // Prevent form from closing modal when clicking inside
            if (deleteForm) {
                deleteForm.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
            // Re-setup delete buttons after search
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    setTimeout(setupDeleteButtons, 100);
                });
            }
            
            // Function to ensure ONLINE status elements stay visible
            function stabilizeOnlineStatus() {
                const onlineStatuses = document.querySelectorAll('.last-seen, .online-status, .online-indicator');
                const statusInfos = document.querySelectorAll('.status-info');
                
                onlineStatuses.forEach(element => {
                    if (element) {
                        element.style.display = 'inline-block';
                        element.style.opacity = '1';
                        element.style.visibility = 'visible';
                    }
                });
                
                statusInfos.forEach(container => {
                    container.style.display = 'flex';
                    container.style.opacity = '1';
                    container.style.visibility = 'visible';
                });
            }
            
            // Initialize stabilization
            stabilizeOnlineStatus();
            
            // Additional stabilization
            window.addEventListener('load', function() {
                stabilizeOnlineStatus();
                setupDeleteButtons(); // Re-setup buttons after full page load
            });
            
            // Simple interval for online status (less aggressive)
            setInterval(stabilizeOnlineStatus, 2000);
        });
    </script>
</body>
</html>
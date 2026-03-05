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

// Get conversation ID
$conversationId = $_GET['conversation_id'] ?? '';

if (empty($conversationId)) {
    header('Location: dashboard.php');
    exit;
}

// Verify user is member of conversation and it's a group
try {
    $checkStmt = $db->prepare("
        SELECT c.*, cm.role 
        FROM conversations c 
        JOIN conversation_members cm ON c.id = cm.conversation_id 
        WHERE c.id = ? AND cm.user_id = ? AND c.type = 'group'
    ");
    $checkStmt->execute([$conversationId, $_SESSION['user_id']]);
    $conversation = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        header('Location: dashboard.php');
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get group info
$groupInfo = getGroupInfo($db, $conversationId);
$groupMembers = getGroupMembers($db, $conversationId);

// Handle actions
$error = '';
$success = '';

// Check for session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle group avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['group_avatar'])) {
    if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
        $_SESSION['error'] = "Hanya admin yang dapat mengubah foto profil group!";
        header("Location: groupinfo.php?conversation_id=" . $conversationId);
        exit;
    } else {
        $uploadResult = uploadGroupAvatar($db, $conversationId, $_SESSION['user_id'], $_FILES['group_avatar']);
        
        if ($uploadResult['success']) {
            // Update conversation avatar_url
            $updateStmt = $db->prepare("UPDATE conversations SET avatar_url = ? WHERE id = ?");
            $updateStmt->execute([$uploadResult['file_url'], $conversationId]);
            
            // Add system message
            $messageId = generateUuid();
            $systemMessage = $user['name'] . " mengubah foto profil group";
            
            $msgStmt = $db->prepare("
                INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
            ");
            $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
            
            $_SESSION['success'] = "Foto profil group berhasil diubah!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
            
        } else {
            $_SESSION['error'] = $uploadResult['message'];
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        }
    }
}

// Handle delete group avatar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_avatar'])) {
    if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
        $_SESSION['error'] = "Hanya admin yang dapat menghapus foto profil group!";
        header("Location: groupinfo.php?conversation_id=" . $conversationId);
        exit;
    } else {
        try {
            // Delete from group_avatars table
            $deleteStmt = $db->prepare("DELETE FROM group_avatars WHERE conversation_id = ?");
            $deleteStmt->execute([$conversationId]);
            
            // Update conversation to remove avatar
            $updateStmt = $db->prepare("UPDATE conversations SET avatar_url = NULL WHERE id = ?");
            $updateStmt->execute([$conversationId]);
            
            // Add system message
            $messageId = generateUuid();
            $systemMessage = $user['name'] . " menghapus foto profil group";
            
            $msgStmt = $db->prepare("
                INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
            ");
            $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
            
            $_SESSION['success'] = "Foto profil group berhasil dihapus!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Gagal menghapus foto profil: " . $e->getMessage();
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_group_name'])) {
        $newName = trim($_POST['group_name']);
        
        if (empty($newName)) {
            $_SESSION['error'] = "Nama group tidak boleh kosong!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } elseif (strlen($newName) < 3) {
            $_SESSION['error'] = "Nama group minimal 3 karakter!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } elseif (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya admin yang dapat mengubah nama group!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                $stmt = $db->prepare("UPDATE conversations SET title = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newName, $conversationId]);
                
                // Add system message
                $messageId = generateUuid();
                $systemMessage = $user['name'] . " mengubah nama group menjadi \"$newName\"";
                
                $msgStmt = $db->prepare("
                    INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                    VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
                ");
                $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
                
                $_SESSION['success'] = "Nama group berhasil diubah!";
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal mengubah nama group: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
    
    elseif (isset($_POST['update_group_about'])) {
        $newAbout = trim($_POST['group_about'] ?? '');
        
        if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya admin yang dapat mengubah deskripsi group!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                $stmt = $db->prepare("UPDATE conversations SET about = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newAbout, $conversationId]);
                
                // Add system message
                $messageId = generateUuid();
                $systemMessage = $user['name'] . " mengubah deskripsi group";
                
                $msgStmt = $db->prepare("
                    INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                    VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
                ");
                $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
                
                $_SESSION['success'] = "Deskripsi group berhasil diubah!";
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal mengubah deskripsi group: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
    
    elseif (isset($_POST['update_role'])) {
        $targetUserId = $_POST['user_id'];
        $newRole = $_POST['role'];
        
        if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya admin yang dapat mengubah peran anggota!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } elseif ($targetUserId === $_SESSION['user_id']) {
            $_SESSION['error'] = "Tidak dapat mengubah peran sendiri!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                $stmt = $db->prepare("UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ?");
                $stmt->execute([$newRole, $conversationId, $targetUserId]);
                
                $_SESSION['success'] = "Peran anggota berhasil diubah!";
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal mengubah peran: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
    
    elseif (isset($_POST['remove_member'])) {
        $targetUserId = $_POST['user_id'];
        
        if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya admin yang dapat mengeluarkan anggota!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } elseif ($targetUserId === $_SESSION['user_id']) {
            $_SESSION['error'] = "Tidak dapat mengeluarkan diri sendiri!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
                $stmt->execute([$conversationId, $targetUserId]);
                
                // Add system message
                $targetUserStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                $targetUserStmt->execute([$targetUserId]);
                $targetUser = $targetUserStmt->fetch(PDO::FETCH_ASSOC);
                
                $messageId = generateUuid();
                $systemMessage = $targetUser['name'] . " dikeluarkan dari group oleh " . $user['name'];
                
                $msgStmt = $db->prepare("
                    INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                    VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
                ");
                $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
                
                $_SESSION['success'] = "Anggota berhasil dikeluarkan!";
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal mengeluarkan anggota: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
    
    elseif (isset($_POST['leave_group'])) {
        try {
            // Check if user is the last admin
            $adminStmt = $db->prepare("
                SELECT COUNT(*) as admin_count 
                FROM conversation_members 
                WHERE conversation_id = ? AND role = 'admin' AND user_id != ?
            ");
            $adminStmt->execute([$conversationId, $_SESSION['user_id']]);
            $adminCount = $adminStmt->fetch(PDO::FETCH_ASSOC)['admin_count'];
            
            if ($adminCount === 0 && isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
                $_SESSION['error'] = "Anda adalah satu-satunya admin. Promosikan anggota lain menjadi admin sebelum keluar!";
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            } else {
                $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
                $stmt->execute([$conversationId, $_SESSION['user_id']]);
                
                // Add system message
                $messageId = generateUuid();
                $systemMessage = $user['name'] . " meninggalkan group";
                
                $msgStmt = $db->prepare("
                    INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                    VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
                ");
                $msgStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $systemMessage]);
                
                $_SESSION['success'] = "Anda telah keluar dari group!";
                header("Location: dashboard.php");
                exit;
            }
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Gagal keluar dari group: " . $e->getMessage();
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        }
    }
    
    elseif (isset($_POST['delete_group'])) {
        // Check if user is the creator
        if (!isGroupCreator($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya pembuat group yang dapat menghapus group!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                // Delete the group
                if (deleteGroup($db, $conversationId)) {
                    $_SESSION['success'] = "Group berhasil dihapus!";
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $_SESSION['error'] = "Gagal menghapus group!";
                    header("Location: groupinfo.php?conversation_id=" . $conversationId);
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal menghapus group: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
    
    // Di bagian create_invite
    elseif (isset($_POST['create_invite'])) {
        $maxUses = $_POST['max_uses'] ? intval($_POST['max_uses']) : null;
        $expiresAt = $_POST['expires_at'] ? $_POST['expires_at'] . ':00' : null;
        
        try {
            $result = createGroupInvitation($db, $conversationId, $_SESSION['user_id'], $maxUses, $expiresAt);
            
            if ($result['success']) {
                $_SESSION['success'] = "Link undangan berhasil dibuat! Kode: " . $result['code'];
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            } else {
                $error = $result['message'];
            }
            
        } catch (PDOException $e) {
            $error = "Gagal membuat link undangan: " . $e->getMessage();
        }
    }
    
    elseif (isset($_POST['revoke_invite'])) {
        $inviteId = $_POST['invite_id'];
        
        if (!isGroupAdmin($db, $conversationId, $_SESSION['user_id'])) {
            $_SESSION['error'] = "Hanya admin yang dapat mencabut undangan!";
            header("Location: groupinfo.php?conversation_id=" . $conversationId);
            exit;
        } else {
            try {
                if (revokeGroupInvitation($db, $inviteId)) {
                    $_SESSION['success'] = "Undangan berhasil dicabut!";
                    header("Location: groupinfo.php?conversation_id=" . $conversationId);
                    exit;
                } else {
                    $_SESSION['error'] = "Gagal mencabut undangan!";
                    header("Location: groupinfo.php?conversation_id=" . $conversationId);
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Gagal mencabut undangan: " . $e->getMessage();
                header("Location: groupinfo.php?conversation_id=" . $conversationId);
                exit;
            }
        }
    }
}

// Get active invitations
$invitations = getGroupInvitations($db, $conversationId);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Info Group - <?php echo htmlspecialchars($groupInfo['title']); ?> - Chat App</title>
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

    <!-- Avatar Actions Modal -->
    <div id="avatarActionsModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-sm w-full mx-4">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white">Foto Profil Group</h3>
                <button onclick="closeAvatarActionsModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="space-y-3">
                <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                    <!-- Upload Button -->
                    <input 
                        type="file" 
                        name="group_avatar" 
                        id="groupAvatarInput"
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                        class="hidden"
                    >
                    <button type="button" onclick="handleUploadAvatar()" 
                            class="w-full bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium flex items-center justify-center space-x-3">
                        <i class="fas fa-camera"></i>
                        <span>Ubah Foto</span>
                    </button>
                    
                    <!-- Delete Button -->
                    <?php if ($groupInfo['avatar_url']): ?>
                        <button type="button" 
                                onclick="handleDeleteAvatar()"
                                class="w-full bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium flex items-center justify-center space-x-3">
                            <i class="fas fa-trash"></i>
                            <span>Hapus Foto</span>
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-user-shield text-3xl text-gray-500 mb-3"></i>
                        <p class="text-gray-400">Hanya admin yang dapat mengubah foto profil group</p>
                    </div>
                <?php endif; ?>
                
                <!-- Info Format File -->
                <div class="pt-4 border-t border-gray-600">
                    <p class="text-gray-400 text-sm text-center">
                        Format: JPEG, PNG, GIF, WebP<br>Maksimal: 5MB
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Group Name Modal -->
    <div id="editNameModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white">Ubah Nama Group</h3>
                <button onclick="closeEditNameModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" id="editNameForm">
                <input type="hidden" name="update_group_name" value="1">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Nama Group Baru</label>
                        <input 
                            type="text" 
                            name="group_name" 
                            id="groupNameInput"
                            value="<?php echo htmlspecialchars($groupInfo['title']); ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Masukkan nama group baru"
                            required
                            minlength="3"
                            maxlength="100"
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            Minimal 3 karakter, maksimal 100 karakter
                        </p>
                    </div>
                </div>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="closeEditNameModal()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        <i class="fas fa-save mr-2"></i>Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Description Modal -->
    <div id="editDescriptionModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white">Edit Deskripsi Group</h3>
                <button onclick="closeEditDescriptionModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" id="editDescriptionForm">
                <input type="hidden" name="update_group_about" value="1">
                <div class="space-y-4 ">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Deskripsi Group</label>
                        <textarea 
                            name="group_about" 
                            id="groupAboutInput"
                            rows="4"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-y"
                            placeholder="Tambahkan deskripsi group (opsional)"
                            maxlength="500"
                        ><?php echo htmlspecialchars($groupInfo['about'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-500 mt-2">
                            Maksimal 500 karakter.
                        </p>
                    </div>
                </div>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="closeEditDescriptionModal()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        <i class="fas fa-save mr-2"></i>Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Progress Indicator -->
    <div id="avatarUploadProgress" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-sm w-full mx-4">
            <div class="flex items-center justify-center mb-4">
                <i class="fas fa-spinner fa-spin text-blue-500 text-2xl mr-3"></i>
                <h3 class="text-xl font-bold text-white">Mengupload Foto</h3>
            </div>
            <p class="text-gray-300 text-center">Sedang mengupload foto profil group...</p>
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
                    <a href="dashboard.php?conversation_id=<?php echo $conversationId; ?>" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-500 to-blue-600 flex items-center justify-center">
                        <i class="fas fa-users text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">Info Group</h1>
                        <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($groupInfo['title']); ?></p>
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
        <div class="max-w-4xl mx-auto">
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column - Group Info -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Group Header -->
                    <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                        <div class="flex flex-col items-center space-y-6">
                            <!-- Group Avatar -->
                            <div class="relative group">
                                <div class="w-32 h-32 rounded-full bg-gradient-to-r from-green-500 to-blue-600 flex items-center justify-center overflow-hidden border-4 border-gray-600">
                                    <?php if ($groupInfo['avatar_url']): ?>
                                        <img src="<?php echo htmlspecialchars($groupInfo['avatar_url']); ?>" 
                                             alt="Group Avatar" 
                                             class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-users text-white text-4xl"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Camera Icon Overlay -->
                                <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                                    <button onclick="openAvatarActionsModal()" 
                                            class="absolute bottom-2 right-2 w-10 h-10 bg-blue-500 hover:bg-blue-600 text-white rounded-full flex items-center justify-center transition duration-200 shadow-lg">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Group Info -->
                            <div class="text-center w-full">
                                <div class="flex items-center justify-center space-x-3 mb-4">
                                    <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($groupInfo['title']); ?></h2>
                                    <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                                        <button onclick="openEditNameModal()" class="text-blue-400 hover:text-blue-300 transition duration-200" title="Edit Nama Group">
                                            <i class="fas fa-edit text-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Group Description -->
                                <?php if (!empty($groupInfo['about'])): ?>
                                    <div class="mb-6">
                                        <div class="flex items-center justify-center space-x-3 mb-2">
                                            <h3 class="text-lg text-gray-400">Deskripsi</h3>
                                            <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                                                <button onclick="openEditDescriptionModal()" class="text-blue-400 hover:text-blue-300 transition duration-200" title="Edit Deskripsi">
                                                    <i class="fas fa-edit text-sm"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-gray-300 text-sm bg-gray-700/50 rounded-xl p-4 text-justify leading-relaxed">
                                            <?php echo nl2br(htmlspecialchars($groupInfo['about'])); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                                        <div class="mb-6">
                                            <div class="flex items-center justify-center space-x-3 mb-2">
                                                <h3 class="text-lg text-gray-400">Deskripsi</h3>
                                                <button onclick="openEditDescriptionModal()" class="text-blue-400 hover:text-blue-300 transition duration-200" title="Tambah Deskripsi">
                                                    <i class="fas fa-plus text-sm"></i>
                                                </button>
                                            </div>
                                            <div class="text-gray-400 text-sm bg-gray-700/50 rounded-xl p-4 text-center italic">
                                                Belum ada deskripsi group. Klik icon <i class="fas fa-plus text-xs"></i> untuk menambahkan.
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Group Stats -->
                                <div class="flex justify-center space-x-6 text-sm text-gray-400">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-users text-xl mb-1"></i>
                                        <span><?php echo $groupInfo['member_count']; ?> anggota</span>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-calendar text-xl mb-1"></i>
                                        <span><?php echo date('d M Y', strtotime($groupInfo['created_at'])); ?></span>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-user text-xl mb-1"></i>
                                        <span><?php echo htmlspecialchars($groupInfo['creator_name']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Group Members -->
                    <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-white flex items-center">
                                <i class="fas fa-users mr-2"></i>Anggota Group
                                <span class="ml-2 text-sm font-normal text-gray-400 bg-gray-700 px-2 py-1 rounded-full">
                                    <?php echo count($groupMembers); ?>
                                </span>
                            </h3>
                        </div>

                        <div class="space-y-3">
                            <?php foreach ($groupMembers as $member): ?>
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-700/50 hover:bg-gray-700 transition duration-150">
                                    <div class="flex items-center space-x-3">
                                        <div class="relative">
                                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-green-400 to-blue-500 flex items-center justify-center">
                                                <?php if ($member['avatar_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($member['avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                                                <?php else: ?>
                                                    <i class="fas fa-user text-white"></i>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (isUserOnline($member['last_seen'])): ?>
                                                <span class="absolute -bottom-1 -right-1 w-3 h-3 bg-green-500 border-2 border-gray-800 rounded-full"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="flex items-center space-x-2">
                                                <h4 class="font-semibold text-white">
                                                    <?php echo htmlspecialchars($member['name']); ?>
                                                </h4>
                                                <?php if ($member['display_role'] === 'creator'): ?>
                                                    <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-lg">Pembuat</span>
                                                <?php elseif ($member['display_role'] === 'admin'): ?>
                                                    <span class="bg-blue-500 text-white text-xs px-2 py-1 rounded-lg">Admin</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($member['phone']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons for Admins -->
                                    <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id']) && $member['user_id'] !== $_SESSION['user_id']): ?>
                                        <div class="flex items-center space-x-2">
                                            <!-- Role Update -->
                                            <?php if ($member['display_role'] !== 'creator'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="update_role" value="1">
                                                    <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                                                    <select name="role" onchange="this.form.submit()" class="bg-gray-600 border border-gray-500 rounded-lg px-2 py-1 text-white text-sm focus:outline-none focus:border-blue-500">
                                                        <option value="member" <?php echo $member['role'] === 'member' ? 'selected' : ''; ?>>Member</option>
                                                        <option value="admin" <?php echo $member['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    </select>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <!-- Remove Member -->
                                            <form method="POST" class="inline remove-member-form" id="removeMemberForm_<?php echo $member['user_id']; ?>">
                                                <input type="hidden" name="remove_member" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                                                <button type="button" 
                                                        onclick="confirmRemoveMember('<?php echo htmlspecialchars(addslashes($member['name'])); ?>', '<?php echo $member['user_id']; ?>')" 
                                                        class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white p-2 rounded-lg transition duration-200" 
                                                        title="Keluarkan">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Actions -->
                <div class="space-y-6">
                    <!-- Quick Actions -->
                    <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                        <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-bolt mr-2"></i>Aksi Cepat
                        </h3>
                        <div class="space-y-3">
                            <a href="dashboard.php?conversation_id=<?php echo $conversationId; ?>" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                                <i class="fas fa-comments"></i>
                                <span>Kembali Chat</span>
                            </a>
                            
                            <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                                <button onclick="openInviteModal()" class="w-full bg-green-500 hover:bg-green-600 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                                    <i class="fas fa-link"></i>
                                    <span>Buat Undangan</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Group Invitations -->
                    <?php if (isGroupAdmin($db, $conversationId, $_SESSION['user_id'])): ?>
                    <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6">
                        <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-link mr-2"></i>Link Undangan
                        </h3>
                        
                        <?php if (empty($invitations)): ?>
                            <p class="text-gray-400 text-sm text-center py-4">Belum ada link undangan aktif</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($invitations as $invite): ?>
                                    <div class="bg-gray-700/50 rounded-xl p-4">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <p class="text-white font-semibold text-sm">Kode: <?php echo $invite['code']; ?></p>
                                                <p class="text-gray-400 text-xs">
                                                    Digunakan: <?php echo $invite['used_count']; ?>
                                                    <?php if ($invite['max_uses']): ?>
                                                        / <?php echo $invite['max_uses']; ?>
                                                    <?php endif; ?>
                                                </p>
                                                <?php if ($invite['expires_at']): ?>
                                                    <p class="text-gray-400 text-xs">
                                                        Kadaluarsa: <?php echo date('d M H:i', strtotime($invite['expires_at'])); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2">
                                            <button onclick="copyInviteLink('<?php echo $invite['code']; ?>')" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 px-3 rounded-lg text-xs font-semibold transition duration-200">
                                                <i class="fas fa-copy mr-1"></i>Copy
                                            </button>
                                            <form method="POST" class="inline revoke-invite-form" id="revokeInviteForm_<?php echo $invite['id']; ?>">
                                                <input type="hidden" name="revoke_invite" value="1">
                                                <input type="hidden" name="invite_id" value="<?php echo $invite['id']; ?>">
                                                <button type="button" 
                                                        onclick="confirmRevokeInvite('<?php echo $invite['id']; ?>')" 
                                                        class="bg-red-500 hover:bg-red-600 text-white py-2 px-3 rounded-lg text-xs font-semibold transition duration-200">
                                                    <i class="fas fa-ban mr-1"></i>Cabut
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Danger Zone -->
                    <div class="bg-red-500/10 border border-red-500/30 rounded-2xl p-6">
                        <h3 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Zona Berbahaya
                        </h3>
                        
                        <div class="space-y-3">
                            <!-- Leave Group Button -->
                            <form method="POST" id="leaveGroupForm">
                                <input type="hidden" name="leave_group" value="1">
                                <button type="button" onclick="confirmLeaveGroup()" class="w-full bg-red-500 hover:bg-red-600 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <span>Keluar Group</span>
                                </button>
                            </form>
                            
                            <!-- Delete Group Button (Only for Creator) -->
                            <?php if (isGroupCreator($db, $conversationId, $_SESSION['user_id'])): ?>
                                <form method="POST" id="deleteGroupForm">
                                    <input type="hidden" name="delete_group" value="1">
                                    <button type="button" onclick="confirmDeleteGroup()" class="w-full bg-red-700 hover:bg-red-800 text-white py-3 px-4 rounded-xl transition duration-200 font-semibold flex items-center justify-center space-x-2">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Hapus Group</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-gray-400 text-xs mt-3 text-center">
                            <i class="fas fa-info-circle mr-1"></i>
                            Tindakan ini tidak dapat dibatalkan
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Invite Modal -->
    <div id="inviteModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Buat Link Undangan</h3>
                <button onclick="closeInviteModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="create_invite" value="1">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Maksimal Penggunaan</label>
                        <input 
                            type="number" 
                            name="max_uses" 
                            min="1"
                            max="100"
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            placeholder="Kosongkan untuk unlimited"
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            Biarkan kosong untuk link yang tidak terbatas
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Kadaluarsa</label>
                        <input 
                            type="datetime-local" 
                            name="expires_at" 
                            class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        >
                        <p class="text-xs text-gray-500 mt-2">
                            Biarkan kosong untuk tidak ada kadaluarsa
                        </p>
                    </div>
                </div>
                
                <div class="flex space-x-3 mt-6">
                    <button type="button" onclick="closeInviteModal()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Batal
                    </button>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                        Buat Link
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden Form for Delete Avatar -->
    <form method="POST" id="deleteAvatarForm" class="hidden">
        <input type="hidden" name="delete_avatar" value="1">
    </form>

    <script>
        // Custom Alert System
        let alertCallback = null;
        let alertType = 'info';
        let currentForm = null;

        function showCustomAlert(title, message, type = 'warning', callback = null, form = null) {
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
            currentForm = form;

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
            currentForm = null;
        }

        function confirmCustomAlert() {
            if (alertCallback && currentForm) {
                // Submit form yang disimpan
                currentForm.submit();
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

        // Avatar Actions Modal
        function openAvatarActionsModal() {
            document.getElementById('avatarActionsModal').classList.remove('hidden');
        }

        function closeAvatarActionsModal() {
            document.getElementById('avatarActionsModal').classList.add('hidden');
        }

        // Edit Name Modal
        function openEditNameModal() {
            document.getElementById('editNameModal').classList.remove('hidden');
        }

        function closeEditNameModal() {
            document.getElementById('editNameModal').classList.add('hidden');
        }

        // Edit Description Modal
        function openEditDescriptionModal() {
            document.getElementById('editDescriptionModal').classList.remove('hidden');
        }

        function closeEditDescriptionModal() {
            document.getElementById('editDescriptionModal').classList.add('hidden');
        }

        // Handle avatar actions
        function handleUploadAvatar() {
            closeAvatarActionsModal();
            document.getElementById('groupAvatarInput').click();
        }

        function handleDeleteAvatar() {
            closeAvatarActionsModal();
            confirmDeleteAvatar();
        }

        // Confirmation Functions
        function confirmDeleteAvatar() {
            const form = document.getElementById('deleteAvatarForm');
            showCustomAlert(
                'Hapus Foto Profil',
                'Apakah Anda yakin ingin menghapus foto profil group?',
                'warning',
                true,
                form
            );
        }

        function confirmRemoveMember(memberName, userId) {
            const form = document.getElementById('removeMemberForm_' + userId);
            showCustomAlert(
                'Keluarkan Anggota',
                `Apakah Anda yakin ingin mengeluarkan ${memberName} dari group?`,
                'warning',
                true,
                form
            );
        }

        function confirmRevokeInvite(inviteId) {
            const form = document.getElementById('revokeInviteForm_' + inviteId);
            showCustomAlert(
                'Cabut Undangan',
                'Apakah Anda yakin ingin mencabut undangan ini?',
                'warning',
                true,
                form
            );
        }

        function confirmLeaveGroup() {
            const form = document.getElementById('leaveGroupForm');
            const groupName = '<?php echo htmlspecialchars($groupInfo['title']); ?>';
            showCustomAlert(
                'Keluar Group',
                `Apakah Anda yakin ingin keluar dari group "${groupName}"?`,
                'warning',
                true,
                form
            );
        }

        function confirmDeleteGroup() {
            const form = document.getElementById('deleteGroupForm');
            const groupName = '<?php echo htmlspecialchars($groupInfo['title']); ?>';
            const memberCount = <?php echo count($groupMembers); ?>;
            
            const message = `
                Group "<strong>${groupName}</strong>" akan dihapus selamanya.<br><br>
                Semua pesan dan percakapan akan hilang dan tidak dapat dikembalikan.<br><br>
                Jumlah anggota saat ini: <strong>${memberCount} orang</strong>.<br><br>
                Apakah Anda yakin ingin melanjutkan?
            `;
            
            showCustomAlert(
                'Hapus Group Permanen',
                message,
                'error',
                true,
                form
            );
        }

        // Avatar upload functionality
        document.getElementById('groupAvatarInput').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                uploadGroupAvatar(this.files[0]);
            }
        });

        function uploadGroupAvatar(file) {
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                showCustomAlert(
                    'Format File Tidak Didukung',
                    'Hanya file gambar yang diizinkan (JPEG, PNG, GIF, WebP)',
                    'error'
                );
                return;
            }
            
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                showCustomAlert(
                    'File Terlalu Besar',
                    'Ukuran file maksimal 5MB',
                    'error'
                );
                return;
            }
            
            const formData = new FormData();
            formData.append('group_avatar', file);
            formData.append('conversation_id', '<?php echo $conversationId; ?>');
            
            const progressIndicator = document.getElementById('avatarUploadProgress');
            progressIndicator.classList.remove('hidden');
            
            fetch('groupinfo.php?conversation_id=<?php echo $conversationId; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Reload the page to show changes
                location.reload();
            })
            .catch(error => {
                console.error('Upload error:', error);
                showCustomAlert(
                    'Upload Gagal',
                    'Terjadi kesalahan saat mengupload foto',
                    'error'
                );
                progressIndicator.classList.add('hidden');
            });
        }

        // Invite Modal Functions
        function openInviteModal() {
            document.getElementById('inviteModal').classList.remove('hidden');
        }

        function closeInviteModal() {
            document.getElementById('inviteModal').classList.add('hidden');
        }

        // Copy invite link
        function copyInviteLink(code) {
            const inviteUrl = `${window.location.origin}/join.php?code=${code}`;
            navigator.clipboard.writeText(inviteUrl).then(() => {
                showSuccessNotification('Link undangan berhasil disalin!');
            }).catch(() => {
                // Fallback for older browsers
                const tempInput = document.createElement('input');
                tempInput.value = inviteUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                showSuccessNotification('Link undangan berhasil disalin!');
            });
        }

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
    </script>
</body>
</html>
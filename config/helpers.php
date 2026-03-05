<?php
function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function formatMessageTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'baru saja';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . 'm';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . 'j';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . 'h';
    } else {
        return date('d M', $time);
    }
}

function isUserOnline($lastSeen) {
    if (!$lastSeen) return false;
    $lastSeenTime = strtotime($lastSeen);
    return (time() - $lastSeenTime) < 300; // 5 minutes
}

function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function markMessagesAsRead($db, $userId, $conversationId) {
    try {
        $stmt = $db->prepare("
            UPDATE message_receipts mr
            JOIN messages m ON mr.message_id = m.id
            SET mr.read_at = NOW()
            WHERE m.conversation_id = ? AND mr.recipient_id = ? AND mr.read_at IS NULL
        ");
        $stmt->execute([$conversationId, $userId]);
    } catch (PDOException $e) {
        error_log("Error marking messages as read: " . $e->getMessage());
    }
}

// Fungsi untuk mendapatkan nama tampilan kontak
function getContactDisplayName($db, $userId, $conversationId, $conversationType) {
    try {
        if ($conversationType === 'private') {
            // Untuk chat private, cek apakah kontak sudah disimpan
            $stmt = $db->prepare("
                SELECT 
                    u.id,
                    u.phone,
                    COALESCE(c.contact_name, u.name) as display_name,
                    c.contact_name as saved_contact_name,
                    u.name as user_name
                FROM conversation_members cm
                JOIN users u ON cm.user_id = u.id
                LEFT JOIN contacts c ON (c.user_id = ? AND c.contact_phone = u.phone)
                WHERE cm.conversation_id = ? AND cm.user_id != ?
            ");
            $stmt->execute([$userId, $conversationId, $userId]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($contact) {
                // Jika ada nama kontak yang disimpan, gunakan itu
                // Jika tidak, gunakan nomor telepon
                return $contact['display_name'] ?: $contact['phone'];
            }
        }
        
        // Fallback
        return 'Unknown Contact';
    } catch (PDOException $e) {
        error_log("Error getting contact display name: " . $e->getMessage());
        return 'Unknown Contact';
    }
}

// Fungsi untuk mendapatkan informasi group
function getGroupInfo($db, $conversationId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                c.*,
                COUNT(cm.id) as member_count,
                creator.name as creator_name,
                creator.id as creator_id
            FROM conversations c
            LEFT JOIN conversation_members cm ON c.id = cm.conversation_id
            LEFT JOIN users creator ON c.created_by = creator.id
            WHERE c.id = ?
            GROUP BY c.id
        ");
        $stmt->execute([$conversationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting group info: " . $e->getMessage());
        return null;
    }
}

// Fungsi untuk mendapatkan anggota group
function getGroupMembers($db, $conversationId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                cm.*,
                u.name,
                u.phone,
                u.avatar_url,
                u.last_seen,
                CASE 
                    WHEN cm.user_id = c.created_by THEN 'creator'
                    ELSE cm.role 
                END as display_role
            FROM conversation_members cm
            JOIN users u ON cm.user_id = u.id
            JOIN conversations c ON cm.conversation_id = c.id
            WHERE cm.conversation_id = ?
            ORDER BY 
                CASE WHEN cm.user_id = c.created_by THEN 0 ELSE 1 END,
                cm.role DESC,
                cm.joined_at ASC
        ");
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting group members: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk generate invitation code
function generateInviteCode() {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Fungsi untuk cek apakah user adalah admin group
function isGroupAdmin($db, $conversationId, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT role 
            FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $member && ($member['role'] === 'admin' || isGroupCreator($db, $conversationId, $userId));
    } catch (PDOException $e) {
        error_log("Error checking group admin: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk cek apakah user adalah creator group
function isGroupCreator($db, $conversationId, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT created_by 
            FROM conversations 
            WHERE id = ? AND created_by = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("Error checking group creator: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk mendapatkan invitation info
function getGroupInvitation($db, $code) {
    try {
        $stmt = $db->prepare("
            SELECT gi.*, c.title as group_name, c.type, u.name as creator_name
            FROM group_invitations gi
            JOIN conversations c ON gi.conversation_id = c.id
            JOIN users u ON gi.created_by = u.id
            WHERE gi.code = ? AND gi.is_active = TRUE
            AND (gi.expires_at IS NULL OR gi.expires_at > NOW())
            AND (gi.max_uses IS NULL OR gi.used_count < gi.max_uses)
        ");
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting group invitation: " . $e->getMessage());
        return null;
    }
}

// Fungsi untuk menambah user ke group
function addUserToGroup($db, $conversationId, $userId, $role = 'member') {
    try {
        // Check if user is already a member
        $checkStmt = $db->prepare("SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $checkStmt->execute([$conversationId, $userId]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'User already in group'];
        }
        
        // Add user to group
        $stmt = $db->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$conversationId, $userId, $role]);
        
        return ['success' => true, 'message' => 'User added to group'];
    } catch (PDOException $e) {
        error_log("Error adding user to group: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Fungsi untuk menghapus user dari group
function removeUserFromGroup($db, $conversationId, $userId) {
    try {
        $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error removing user from group: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk update role member
function updateMemberRole($db, $conversationId, $userId, $role) {
    try {
        $stmt = $db->prepare("UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$role, $conversationId, $userId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error updating member role: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk membuat undangan group
function createGroupInvitation($db, $conversationId, $createdBy, $maxUses = null, $expiresAt = null) {
    try {
        $inviteId = generateUuid();
        $inviteCode = generateInviteCode();
        
        $stmt = $db->prepare("
            INSERT INTO group_invitations (id, conversation_id, code, created_by, max_uses, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$inviteId, $conversationId, $inviteCode, $createdBy, $maxUses, $expiresAt]);
        
        return ['success' => true, 'code' => $inviteCode];
    } catch (PDOException $e) {
        error_log("Error creating group invitation: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Fungsi untuk mendapatkan undangan aktif
function getGroupInvitations($db, $conversationId) {
    try {
        $stmt = $db->prepare("
            SELECT * FROM group_invitations 
            WHERE conversation_id = ? AND is_active = TRUE
            AND (expires_at IS NULL OR expires_at > NOW())
            AND (max_uses IS NULL OR used_count < max_uses)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting group invitations: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk mencabut undangan
function revokeGroupInvitation($db, $inviteId) {
    try {
        $stmt = $db->prepare("UPDATE group_invitations SET is_active = FALSE WHERE id = ?");
        $stmt->execute([$inviteId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error revoking group invitation: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk menggunakan undangan
function useGroupInvitation($db, $code) {
    try {
        $stmt = $db->prepare("UPDATE group_invitations SET used_count = used_count + 1 WHERE code = ?");
        $stmt->execute([$code]);
        return true;
    } catch (PDOException $e) {
        error_log("Error using group invitation: " . $e->getMessage());
        return false;
    }
}

function uploadGroupAvatar($db, $conversationId, $uploaderId, $file) {
    try {
        // Check if file was uploaded successfully
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'File upload failed'];
        }
        
        // Check file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            return ['success' => false, 'message' => 'Hanya file gambar yang diizinkan (JPEG, PNG, GIF, WebP)'];
        }
        
        // Check file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Ukuran file maksimal 5MB'];
        }
        
        // Create fotoprofil directory if not exists
        $uploadDir = 'fotoprofil/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return ['success' => false, 'message' => 'Gagal membuat direktori upload'];
            }
        }
        
        // Check if directory is writable
        if (!is_writable($uploadDir)) {
            return ['success' => false, 'message' => 'Direktori upload tidak dapat ditulisi'];
        }
        
        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'group_' . $conversationId . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'message' => 'Gagal menyimpan file'];
        }
        
        // Generate IDs
        $avatarId = generateUuid();
        
        // Delete old avatar if exists
        $deleteStmt = $db->prepare("DELETE FROM group_avatars WHERE conversation_id = ?");
        $deleteStmt->execute([$conversationId]);
        
        // Insert into group_avatars table
        $insertStmt = $db->prepare("
            INSERT INTO group_avatars (id, conversation_id, file_url, uploaded_by, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$avatarId, $conversationId, $filePath, $uploaderId]);
        
        return [
            'success' => true, 
            'file_url' => $filePath,
            'message' => 'Foto profil berhasil diupload'
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in uploadGroupAvatar: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (Exception $e) {
        error_log("General error in uploadGroupAvatar: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// =============================================
// FUNGSI BARU UNTUK HAPUS GROUP
// =============================================

/**
 * Delete group and all related data
 */
function deleteGroup($db, $conversationId) {
    try {
        $db->beginTransaction();
        
        // Delete group avatars
        $stmt = $db->prepare("DELETE FROM group_avatars WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Delete group invitations
        $stmt = $db->prepare("DELETE FROM group_invitations WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Delete message receipts
        $stmt = $db->prepare("
            DELETE mr FROM message_receipts mr 
            JOIN messages m ON mr.message_id = m.id 
            WHERE m.conversation_id = ?
        ");
        $stmt->execute([$conversationId]);
        
        // Delete messages
        $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Delete conversation members
        $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Delete conversation
        $stmt = $db->prepare("DELETE FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        
        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error deleting group: " . $e->getMessage());
        return false;
    }
}
?>
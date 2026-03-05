<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// Cek jika user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Ambil data user yang sedang login
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

// Handle AJAX requests untuk mengirim pesan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'send_message') {
        $conversationId = $_POST['conversation_id'] ?? '';
        $messageContent = trim($_POST['message'] ?? '');
        
        if (empty($conversationId) || empty($messageContent)) {
            echo json_encode(['success' => false, 'message' => 'Conversation ID and message are required']);
            exit;
        }
        
        try {
            // Verifikasi user adalah member conversation
            $checkStmt = $db->prepare("SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
            $checkStmt->execute([$conversationId, $_SESSION['user_id']]);
            $isMember = $checkStmt->fetch();
            
            if (!$isMember) {
                echo json_encode(['success' => false, 'message' => 'You are not a member of this conversation']);
                exit;
            }
            
            // Generate message ID
            $messageId = generateUuid();
            
            // Insert message
            $insertStmt = $db->prepare("
                INSERT INTO messages (id, conversation_id, sender_id, content_type, content, created_at, updated_at) 
                VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
            ");
            $insertStmt->execute([$messageId, $conversationId, $_SESSION['user_id'], $messageContent]);
            
            // Update conversation updated_at dan last_message_id
            $updateConvStmt = $db->prepare("UPDATE conversations SET updated_at = NOW(), last_message_id = ? WHERE id = ?");
            $updateConvStmt->execute([$messageId, $conversationId]);
            
            // Create message receipts untuk semua member conversation kecuali sender
            $membersStmt = $db->prepare("SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?");
            $membersStmt->execute([$conversationId, $_SESSION['user_id']]);
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $receiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at) VALUES (?, ?, NOW())");
            foreach ($members as $member) {
                $receiptStmt->execute([$messageId, $member['user_id']]);
            }
            
            // Create receipt untuk sender (mark as delivered immediately)
            $senderReceiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at, read_at) VALUES (?, ?, NOW(), NOW())");
            $senderReceiptStmt->execute([$messageId, $_SESSION['user_id']]);
            
            // Ambil message yang baru dibuat dengan info sender
            $newMessageStmt = $db->prepare("
                SELECT m.*, u.name as sender_name, u.avatar_url 
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.id = ?
            ");
            $newMessageStmt->execute([$messageId]);
            $newMessage = $newMessageStmt->fetch(PDO::FETCH_ASSOC);
            
            // Untuk pesan baru, status default adalah 'sent' (ceklis 1)
            $newMessage['message_status'] = 'sent';
            
            echo json_encode([
                'success' => true,
                'message' => 'Pesan berhasil dikirim',
                'message_data' => $newMessage
            ]);
            
        } catch (PDOException $e) {
            error_log("Database error in dashboard.php: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'send_file_message') {
        $conversationId = $_POST['conversation_id'] ?? '';
        $messageContent = trim($_POST['message'] ?? '');
        
        if (empty($conversationId)) {
            echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
            exit;
        }
        
        try {
            // Verify user is member of conversation
            $checkStmt = $db->prepare("SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
            $checkStmt->execute([$conversationId, $_SESSION['user_id']]);
            $isMember = $checkStmt->fetch();
            
            if (!$isMember) {
                echo json_encode(['success' => false, 'message' => 'You are not a member of this conversation']);
                exit;
            }
            
            // Handle file upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'File upload failed: ';
                switch ($_FILES['file']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMessage .= 'File size too large';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errorMessage .= 'File only partially uploaded';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMessage .= 'No file was uploaded';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errorMessage .= 'Missing temporary folder';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $errorMessage .= 'Failed to write file to disk';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $errorMessage .= 'File upload stopped by extension';
                        break;
                    default:
                        $errorMessage .= 'Unknown error';
                        break;
                }
                echo json_encode(['success' => false, 'message' => $errorMessage]);
                exit;
            }
            
            $file = $_FILES['file'];
            
            // Extended allowed file types
            $allowedTypes = [
                // Images
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
                
                // Videos
                'video/mp4', 'video/avi', 'video/mov', 'video/mkv', 'video/webm', 'video/flv', 'video/wmv',
                
                // Audio
                'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp3', 'audio/aac', 'audio/flac',
                
                // Documents
                'application/pdf', 
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                
                // Spreadsheets
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                
                // Presentations
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                
                // Text files
                'text/plain', 'text/csv',
                
                // Archives
                'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed'
            ];
            
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'File type not allowed: ' . $file['type']]);
                exit;
            }
            
            // Check file size (max 25MB)
            if ($file['size'] > 25 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File size too large (max 25MB)']);
                exit;
            }
            
            // Create media_files directory if not exists
            $uploadDir = 'media_files/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
                    exit;
                }
            }
            
            // Check if directory is writable
            if (!is_writable($uploadDir)) {
                echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
                exit;
            }
            
            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                echo json_encode(['success' => false, 'message' => 'Failed to save file to server']);
                exit;
            }
            
            // FIX: Map to available ENUM values in messages table
            $contentType = 'file'; // default for documents, archives, etc.
            
            if (strpos($file['type'], 'image/') === 0) {
                $contentType = 'image';
            } elseif (strpos($file['type'], 'video/') === 0) {
                $contentType = 'video';
            } elseif (strpos($file['type'], 'audio/') === 0) {
                $contentType = 'audio';
            }
            
            // Generate message ID
            $messageId = generateUuid();
            $mediaFileId = generateUuid();
            
            // Insert into media_files table - FIX: Limit file_type length
            $fileTypeForDB = substr($file['type'], 0, 50); // Truncate to 50 characters
            
            $mediaStmt = $db->prepare("
                INSERT INTO media_files (id, uploader_id, file_name, file_type, file_size, file_url, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW())
            ");
            $mediaStmt->execute([
                $mediaFileId, 
                $_SESSION['user_id'], 
                $file['name'],
                $fileTypeForDB,
                $file['size'],
                $filePath
            ]);
            
            // Insert message - FIX: Use only allowed ENUM values
            $insertStmt = $db->prepare("
                INSERT INTO messages (id, conversation_id, sender_id, content_type, content, file_url, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([
                $messageId, 
                $conversationId, 
                $_SESSION['user_id'], 
                $contentType,
                $messageContent ?: $file['name'],
                $filePath
            ]);
            
            // Update conversation
            $updateConvStmt = $db->prepare("UPDATE conversations SET updated_at = NOW(), last_message_id = ? WHERE id = ?");
            $updateConvStmt->execute([$messageId, $conversationId]);
            
            // Create message receipts
            $membersStmt = $db->prepare("SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?");
            $membersStmt->execute([$conversationId, $_SESSION['user_id']]);
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $receiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at) VALUES (?, ?, NOW())");
            foreach ($members as $member) {
                $receiptStmt->execute([$messageId, $member['user_id']]);
            }
            
            // Create receipt for sender
            $senderReceiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at, read_at) VALUES (?, ?, NOW(), NOW())");
            $senderReceiptStmt->execute([$messageId, $_SESSION['user_id']]);
            
            // Get the newly created message
            $newMessageStmt = $db->prepare("
                SELECT m.*, u.name as sender_name, u.avatar_url 
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.id = ?
            ");
            $newMessageStmt->execute([$messageId]);
            $newMessage = $newMessageStmt->fetch(PDO::FETCH_ASSOC);
            
            // Add file info to response
            $newMessage['file_name'] = $file['name'];
            $newMessage['file_size'] = $file['size'];
            $newMessage['file_type'] = $file['type'];
            
            echo json_encode([
                'success' => true,
                'message' => 'File berhasil dikirim',
                'message_data' => $newMessage
            ]);
            
        } catch (PDOException $e) {
            error_log("Database error in send_file_message: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        } catch (Exception $e) {
            error_log("General error in send_file_message: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_messages') {
        $conversationId = $_POST['conversation_id'] ?? '';
        
        if (empty($conversationId)) {
            echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
            exit;
        }
        
        try {
            // Verifikasi user adalah member conversation
            $checkStmt = $db->prepare("SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
            $checkStmt->execute([$conversationId, $_SESSION['user_id']]);
            
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Not a member of this conversation']);
                exit;
            }

            // LOGIKA SEDERHANA: Cek apakah lawan chat sudah membaca pesan
            $messagesStmt = $db->prepare("
                SELECT 
                    m.*,
                    -- Gunakan logika contact_name > phone untuk nama pengirim
                    COALESCE(ct.contact_name, u.phone) as sender_display_name,
                    u.avatar_url,
                    mf.file_name,
                    -- LOGIKA CEKLIS SEDERHANA: 
                    -- Jika pesan dikirim oleh saya, cek apakah lawan chat sudah baca
                    CASE 
                        WHEN m.sender_id = ? THEN 
                            CASE 
                                -- Jika ada lawan chat yang sudah membaca (read_at IS NOT NULL)
                                WHEN EXISTS (
                                    SELECT 1 FROM message_receipts mr 
                                    WHERE mr.message_id = m.id 
                                    AND mr.recipient_id != ? 
                                    AND mr.read_at IS NOT NULL
                                ) THEN 'read'
                                -- Default: sent (belum dibaca)
                                ELSE 'sent'
                            END
                        ELSE 'received'
                    END as message_status
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN media_files mf ON m.file_url = mf.file_url
                -- Join dengan contacts untuk mendapatkan contact_name
                LEFT JOIN contacts ct ON (ct.user_id = ? AND ct.contact_phone = u.phone)
                WHERE m.conversation_id = ? AND m.is_deleted = 0
                ORDER BY m.created_at ASC
            ");
            $messagesStmt->execute([
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $conversationId
            ]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Mark messages as read - Tandai pesan lawan sebagai sudah dibaca
                $markReadStmt = $db->prepare("
                    UPDATE message_receipts mr
                    JOIN messages m ON mr.message_id = m.id
                    SET mr.read_at = NOW()
                    WHERE m.conversation_id = ? 
                    AND mr.recipient_id = ? 
                    AND mr.read_at IS NULL
                    AND m.sender_id != ?
                ");
                $markReadStmt->execute([$conversationId, $_SESSION['user_id'], $_SESSION['user_id']]);
                
                $html = '';
                if (empty($messages)) {
                    $html = '<div class="text-center py-12 text-gray-500">
                                <i class="fas fa-comments text-3xl mb-3 opacity-50"></i>
                                <p class="text-lg">Belum ada pesan</p>
                                <p class="text-sm mt-2">Mulai percakapan dengan mengirim pesan pertama!</p>
                            </div>';
                } else {
                    foreach ($messages as $message) {
                        $isOwnMessage = $message['sender_id'] === $_SESSION['user_id'];
                        // Hanya tampilkan jam saja
                        $time = date('H:i', strtotime($message['created_at']));
                        
                        $html .= '<div class="flex ' . ($isOwnMessage ? 'justify-end' : 'justify-start') . ' mb-4 message-item" data-message-id="' . $message['id'] . '">';
                        $html .= '<div class="max-w-xs lg:max-w-md ' . ($isOwnMessage ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-gray-700 text-white rounded-2xl rounded-bl-none') . ' px-4 py-2 relative group">';
                        
                        // TOMBOL HAPUS PESAN - hanya untuk pesan yang dikirim user
                        if ($isOwnMessage) {
                            $html .= '<button onclick="deleteMessage(\'' . $message['id'] . '\')" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 hover:bg-red-600" title="Hapus pesan">';
                            $html .= '<i class="fas fa-times text-xs"></i>';
                            $html .= '</button>';
                        }
                        
                        // Gunakan sender_display_name yang sudah menggunakan logika contact_name > phone
                        if (!$isOwnMessage) {
                            $html .= '<p class="text-xs text-blue-300 font-semibold mb-1">' . htmlspecialchars($message['sender_display_name']) . '</p>';
                        }
                        
                        // Tambahkan konten file jika ada
                        if ($message['file_url']) {
                            if ($message['content_type'] === 'image') {
                                $html .= '<div class="mb-2">';
                                $html .= '<img src="' . htmlspecialchars($message['file_url']) . '" alt="Gambar" class="max-w-full max-h-64 rounded-lg cursor-pointer" onclick="openImageModal(\'' . htmlspecialchars($message['file_url']) . '\')">';
                                $html .= '</div>';
                            } elseif ($message['content_type'] === 'video') {
                                $html .= '<div class="mb-2">';
                                $html .= '<video controls class="max-w-full max-h-64 rounded-lg">';
                                $html .= '<source src="' . htmlspecialchars($message['file_url']) . '" type="video/mp4">';
                                $html .= 'Browser tidak mendukung video.';
                                $html .= '</video>';
                                $html .= '</div>';
                            } elseif ($message['content_type'] === 'audio') {
                                $html .= '<div class="mb-2">';
                                $html .= '<audio controls class="w-full">';
                                $html .= '<source src="' . htmlspecialchars($message['file_url']) . '" type="audio/mpeg">';
                                $html .= 'Browser tidak mendukung audio.';
                                $html .= '</audio>';
                                $html .= '</div>';
                            } else {
                                $fileColor = $isOwnMessage ? 'bg-blue-500' : 'bg-gray-600';
                                $textColor = $isOwnMessage ? 'text-blue-100' : 'text-gray-300';
                                $fileSize = file_exists($message['file_url']) ? filesize($message['file_url']) : 0;
                                $html .= '<div class="mb-2">';
                                $html .= '<a href="' . htmlspecialchars($message['file_url']) . '" target="_blank" class="flex items-center space-x-3 p-3 ' . $fileColor . ' rounded-lg hover:opacity-80 transition duration-200">';
                                $html .= '<div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center">';
                                $html .= '<i class="fas fa-file text-white"></i>';
                                $html .= '</div>';
                                $html .= '<div class="flex-1 min-w-0">';
                                $html .= '<p class="text-white text-sm font-medium truncate">' . htmlspecialchars($message['file_name'] ?: $message['content'] ?: 'File') . '</p>';
                                $html .= '<p class="' . $textColor . ' text-xs">' . pathinfo($message['file_url'], PATHINFO_EXTENSION) . ' • ' . formatFileSize($fileSize) . '</p>';
                                $html .= '</div>';
                                $html .= '<i class="fas fa-download ' . $textColor . '"></i>';
                                $html .= '</a>';
                                $html .= '</div>';
                            }
                        }
                        
                        if ($message['content'] && !$message['file_url']) {
                            $html .= '<p class="break-words">' . htmlspecialchars($message['content']) . '</p>';
                        }
                        
                        $html .= '<p class="text-xs ' . ($isOwnMessage ? 'text-blue-200' : 'text-gray-400') . ' text-right mt-1">';
                        $html .= $time;
                         
                        // Ceklis 1 untuk belum dibaca, Ceklis 2 untuk sudah dibaca
                        if ($isOwnMessage) {
                            if ($message['message_status'] === 'read') {
                                // Ceklis 2 - sudah dibaca (biru)
                                $html .= ' <i class="fas fa-check-double text-blue-300 ml-1" title="Dibaca"></i>';
                            } else {
                                // Ceklis 1 - belum dibaca (abu-abu)
                                $html .= ' <i class="fas fa-check text-gray-400 ml-1" title="Terkirim"></i>';
                            }
                        }
                        
                        $html .= '</p>';
                        $html .= '</div>';
                        $html .= '</div>';
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'html' => $html
                ]);
                
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error loading messages: ' . $e->getMessage()
                ]);
            }
            exit;
        }
        
        // Handle add contact
        if ($_POST['action'] === 'add_contact') {
            $contactPhone = $_POST['contact_phone'] ?? '';
            $contactName = $_POST['contact_name'] ?? '';
            
            if (empty($contactPhone)) {
                echo json_encode(['success' => false, 'message' => 'Nomor telepon tidak boleh kosong']);
                exit;
            }
            
            try {
                // Cek jika kontak sudah ada
                $checkStmt = $db->prepare("SELECT * FROM contacts WHERE user_id = ? AND contact_phone = ?");
                $checkStmt->execute([$_SESSION['user_id'], $contactPhone]);
                $existingContact = $checkStmt->fetch();
                
                if ($existingContact) {
                    echo json_encode(['success' => false, 'message' => 'Kontak sudah ada dalam daftar']);
                    exit;
                }
                
                // Insert new contact - HANYA kolom yang diperlukan
                $insertStmt = $db->prepare("
                    INSERT INTO contacts (user_id, contact_phone, contact_name) 
                    VALUES (?, ?, ?)
                ");
                $insertStmt->execute([$_SESSION['user_id'], $contactPhone, $contactName]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Kontak berhasil ditambahkan'
                ]);
                
            } catch (PDOException $e) {
                error_log("Database error in add_contact: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;
        }
        
        // Handle delete message
        if ($_POST['action'] === 'delete_message') {
            $messageId = $_POST['message_id'] ?? '';
            
            if (empty($messageId)) {
                echo json_encode(['success' => false, 'message' => 'Message ID required']);
                exit;
            }
            
            try {
                // Verifikasi user adalah sender dari message
                $checkStmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND sender_id = ?");
                $checkStmt->execute([$messageId, $_SESSION['user_id']]);
                $message = $checkStmt->fetch();
                
                if (!$message) {
                    echo json_encode(['success' => false, 'message' => 'Anda tidak dapat menghapus pesan ini']);
                    exit;
                }
                
                // Update message sebagai deleted (soft delete)
                $updateStmt = $db->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ?");
                $updateStmt->execute([$messageId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Pesan berhasil dihapus'
                ]);
                
            } catch (PDOException $e) {
                error_log("Database error in delete_message: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;
        }

        // AJAX: Get conversation list
        if ($_POST['action'] === 'get_conversations') {
            try {
                $conversationsStmt = $db->prepare("
                    SELECT 
                        c.*,
                        cm.role,
                        lm.content as last_message_content,
                        lm.created_at as last_message_time,
                        sender.name as last_message_sender,
                        -- Untuk private chat, dapatkan informasi kontak
                        CASE 
                            WHEN c.type = 'private' THEN (
                                SELECT COALESCE(ct.contact_name, ou.phone)
                                FROM conversation_members cm2
                                JOIN users ou ON cm2.user_id = ou.id
                                LEFT JOIN contacts ct ON (ct.user_id = ? AND ct.contact_phone = ou.phone)
                                WHERE cm2.conversation_id = c.id AND cm2.user_id != ?
                            )
                            ELSE c.title
                        END as display_name,
                        -- Untuk private chat, dapatkan avatar kontak
                        CASE 
                            WHEN c.type = 'private' THEN (
                                SELECT ou.avatar_url
                                FROM conversation_members cm2
                                JOIN users ou ON cm2.user_id = ou.id
                                WHERE cm2.conversation_id = c.id AND cm2.user_id != ?
                            )
                            ELSE c.avatar_url
                        END as contact_avatar_url,
                        -- Untuk group chat, hitung jumlah anggota
                        CASE 
                            WHEN c.type = 'group' THEN (
                                SELECT COUNT(*) 
                                FROM conversation_members cm3 
                                WHERE cm3.conversation_id = c.id
                            )
                            ELSE NULL
                        END as member_count
                    FROM conversations c
                    JOIN conversation_members cm ON c.id = cm.conversation_id
                    LEFT JOIN LATERAL (
                        SELECT m.content, m.created_at, m.sender_id, u.name
                        FROM messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        WHERE m.conversation_id = c.id
                        ORDER BY m.created_at DESC
                        LIMIT 1
                    ) lm ON true
                    LEFT JOIN users sender ON lm.sender_id = sender.id
                    WHERE cm.user_id = ?
                    ORDER BY COALESCE(lm.created_at, c.updated_at) DESC
                ");
                $conversationsStmt->execute([
                    $_SESSION['user_id'], $_SESSION['user_id'], // untuk contact_name
                    $_SESSION['user_id'], // untuk avatar_url
                    $_SESSION['user_id']  // untuk WHERE clause
                ]);
                $conversations = $conversationsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $html = '';
                if (empty($conversations)) {
                    $html = '<div class="text-center py-12 text-gray-400">
                                <i class="fas fa-comments text-4xl mb-4 opacity-50"></i>
                                <p class="text-sm mb-2">Belum ada percakapan</p>
                                <p class="text-xs">Mulai chat dengan kontak Anda</p>
                            </div>';
                } else {
                    foreach ($conversations as $conv) {
                        $isActive = isset($_GET['conversation_id']) && $_GET['conversation_id'] === $conv['id'];
                        $html .= '<div class="relative group">';
                        $html .= '<a href="dashboard.php?conversation_id=' . $conv['id'] . '" class="block">';
                        $html .= '<div class="p-4 border-b border-gray-700 hover:bg-gray-700 transition duration-150 ' . ($isActive ? 'bg-gradient-to-r from-blue-500 to-purple-600' : '') . '">';
                        $html .= '<div class="flex items-center space-x-3">';
                        $html .= '<div class="relative">';
                        $html .= '<div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-400 to-purple-500 flex items-center justify-center flex-shrink-0">';
                        if ($conv['contact_avatar_url']) {
                            $html .= '<img src="' . htmlspecialchars($conv['contact_avatar_url']) . '" alt="Avatar" class="w-12 h-12 rounded-full">';
                        } else {
                            $html .= '<i class="fas fa-' . ($conv['type'] === 'private' ? 'user' : 'users') . ' text-white"></i>';
                        }
                        $html .= '</div>';
                        if ($conv['type'] === 'group') {
                            $html .= '<span class="absolute -bottom-1 -right-1 bg-green-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">';
                            $html .= '<i class="fas fa-users text-xs"></i>';
                            $html .= '</span>';
                        }
                        $html .= '</div>';
                        $html .= '<div class="flex-1 min-w-0">';
                        $html .= '<div class="flex justify-between items-start mb-1">';
                        $html .= '<h4 class="font-semibold text-white text-sm truncate">';
                        $html .= htmlspecialchars($conv['display_name'] ?: 'Unknown Contact');
                        if ($conv['type'] === 'group') {
                            $html .= '<span class="text-xs text-gray-400 ml-1">(' . $conv['member_count'] . ')</span>';
                        }
                        $html .= '</h4>';
                        if ($conv['last_message_time']) {
                            $html .= '<span class="text-xs text-gray-400 whitespace-nowrap flex-shrink-0 ml-2">';
                            $html .= date('H:i', strtotime($conv['last_message_time']));
                            $html .= '</span>';
                        }
                        $html .= '</div>';
                        $html .= '<div class="flex justify-between items-center">';
                        $html .= '<p class="text-gray-400 text-sm truncate">';
                        if ($conv['last_message_content']) {
                            $messageContent = htmlspecialchars($conv['last_message_content']);
                            if (strlen($messageContent) > 35) {
                                $messageContent = substr($messageContent, 0, 35) . '...';
                            }
                            $html .= ($conv['type'] === 'group' && $conv['last_message_sender']) ? 
                                     $conv['last_message_sender'] . ': ' . $messageContent : $messageContent;
                        } else {
                            $html .= 'Belum ada pesan';
                        }
                        $html .= '</p>';
                        $html .= '</div>';
                        $html .= '</div>';
                        $html .= '</div>';
                        $html .= '</div>';
                        $html .= '</a>';
                        $html .= '</div>';
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'html' => $html,
                    'count' => count($conversations)
                ]);
                
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error loading conversations: ' . $e->getMessage()
                ]);
            }
            exit;
        }

        // AJAX: Update user status
        if ($_POST['action'] === 'update_user_status') {
            try {
                $updateStmt = $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
                $updateStmt->execute([$_SESSION['user_id']]);
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating status']);
            }
            exit;
        }
    }

// Update last_seen
try {
    $updateStmt = $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$_SESSION['user_id']]);
} catch (PDOException $e) {
    // Continue even if update fails
}

// Ambil conversations user dengan last message (termasuk group chats)
try {
    $conversationsStmt = $db->prepare("
        SELECT 
            c.*,
            cm.role,
            lm.content as last_message_content,
            lm.created_at as last_message_time,
            sender.name as last_message_sender,
            -- Untuk private chat, dapatkan informasi kontak
            CASE 
                WHEN c.type = 'private' THEN (
                    SELECT COALESCE(ct.contact_name, ou.phone)
                    FROM conversation_members cm2
                    JOIN users ou ON cm2.user_id = ou.id
                    LEFT JOIN contacts ct ON (ct.user_id = ? AND ct.contact_phone = ou.phone)
                    WHERE cm2.conversation_id = c.id AND cm2.user_id != ?
                )
                ELSE c.title
            END as display_name,
            -- Untuk private chat, dapatkan avatar kontak
            CASE 
                WHEN c.type = 'private' THEN (
                    SELECT ou.avatar_url
                    FROM conversation_members cm2
                    JOIN users ou ON cm2.user_id = ou.id
                    WHERE cm2.conversation_id = c.id AND cm2.user_id != ?
                )
                ELSE c.avatar_url
            END as contact_avatar_url,
            -- Untuk group chat, hitung jumlah anggota
            CASE 
                WHEN c.type = 'group' THEN (
                    SELECT COUNT(*) 
                    FROM conversation_members cm3 
                    WHERE cm3.conversation_id = c.id
                )
                ELSE NULL
            END as member_count
        FROM conversations c
        JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN LATERAL (
            SELECT m.content, m.created_at, m.sender_id, u.name
            FROM messages m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = c.id
            ORDER BY m.created_at DESC
            LIMIT 1
        ) lm ON true
        LEFT JOIN users sender ON lm.sender_id = sender.id
        WHERE cm.user_id = ?
        ORDER BY COALESCE(lm.created_at, c.updated_at) DESC
    ");
    $conversationsStmt->execute([
        $_SESSION['user_id'], $_SESSION['user_id'], // untuk contact_name
        $_SESSION['user_id'], // untuk avatar_url
        $_SESSION['user_id']  // untuk WHERE clause
    ]);
    $conversations = $conversationsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching conversations: " . $e->getMessage());
    $conversations = [];
}

// Ambil kontak user
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

// Handle search
$searchTerm = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchTerm = trim($_GET['search']);
    $filteredConversations = [];
    
    foreach ($conversations as $conv) {
        if (stripos($conv['display_name'], $searchTerm) !== false || 
            stripos($conv['last_message_content'] ?? '', $searchTerm) !== false) {
            $filteredConversations[] = $conv;
        }
    }
    $conversations = $filteredConversations;
}

// Ambil active conversation
$activeConversationId = $_GET['conversation_id'] ?? null;
$activeConversation = null;
$otherUserInfo = null;
$targetUserId = '';

if ($activeConversationId) {
    foreach ($conversations as $conv) {
        if ($conv['id'] === $activeConversationId) {
            $activeConversation = $conv;
            break;
        }
    }
    
    // DAPATKAN INFO LAWAN CHAT DENGAN LOGIKA YANG BENAR DAN STATUS ONLINE
    if ($activeConversation && $activeConversation['type'] === 'private') {
        try {
            // Gunakan logika yang sama dengan sidebar - contact_name jika ada, jika tidak phone
            $otherUserStmt = $db->prepare("
                SELECT 
                    ou.id as user_id,
                    ou.phone,
                    ou.avatar_url,
                    ou.last_seen,
                    -- LOGIKA UTAMA: contact_name dari tabel contacts jika ada, jika tidak gunakan phone
                    COALESCE(ct.contact_name, ou.phone) as display_name,
                    ct.contact_name as saved_contact_name,
                    -- Tambahkan field untuk cek apakah kontak sudah disimpan
                    CASE WHEN ct.contact_name IS NOT NULL THEN 1 ELSE 0 END as is_contact_saved,
                    -- Status online dengan logika yang sama seperti profil_lawan.php
                    CASE 
                        WHEN TIMESTAMPDIFF(SECOND, ou.last_seen, NOW()) < 300 THEN 'online'
                        ELSE 'offline'
                    END as online_status
                FROM conversation_members cm
                JOIN users ou ON cm.user_id = ou.id
                LEFT JOIN contacts ct ON (ct.user_id = ? AND ct.contact_phone = ou.phone)
                WHERE cm.conversation_id = ? AND cm.user_id != ?
            ");
            $otherUserStmt->execute([$_SESSION['user_id'], $activeConversationId, $_SESSION['user_id']]);
            $otherUserInfo = $otherUserStmt->fetch(PDO::FETCH_ASSOC);
            
            // Simpan targetUserId untuk digunakan di JavaScript
            $targetUserId = $otherUserInfo['user_id'] ?? '';
            
        } catch (PDOException $e) {
            error_log("Error fetching other user info: " . $e->getMessage());
            $otherUserInfo = null;
            $targetUserId = '';
        }
    }
    
    // Mark messages as read ketika conversation dibuka
    markMessagesAsRead($db, $_SESSION['user_id'], $activeConversationId);
}

// Handle start new chat
if (isset($_GET['start_chat'])) {
    $contactPhone = $_GET['contact_phone'];
    
    try {
        // Cek jika kontak ada dalam daftar kontak user
        $contactCheckStmt = $db->prepare("SELECT * FROM contacts WHERE user_id = ? AND contact_phone = ?");
        $contactCheckStmt->execute([$_SESSION['user_id'], $contactPhone]);
        $contact = $contactCheckStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contact) {
            $_SESSION['error'] = "Kontak tidak ditemukan dalam daftar kontak Anda.";
            header("Location: dashboard.php");
            exit;
        }
        
        // Ambil contact user ID jika terdaftar
        $userStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $userStmt->execute([$contactPhone]);
        $contactUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contactUser) {
            // Cek jika conversation sudah ada
            $convCheckStmt = $db->prepare("
                SELECT c.id 
                FROM conversations c
                JOIN conversation_members cm1 ON c.id = cm1.conversation_id
                JOIN conversation_members cm2 ON c.id = cm2.conversation_id
                WHERE c.type = 'private'
                AND cm1.user_id = ? AND cm2.user_id = ?
            ");
            $convCheckStmt->execute([$_SESSION['user_id'], $contactUser['id']]);
            $existingConv = $convCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingConv) {
                // Redirect ke existing conversation
                header("Location: dashboard.php?conversation_id=" . $existingConv['id']);
                exit;
            } else {
                // Buat new conversation
                $conversationId = generateUuid();
                $convStmt = $db->prepare("INSERT INTO conversations (id, type, created_by, created_at, updated_at) VALUES (?, 'private', ?, NOW(), NOW())");
                $convStmt->execute([$conversationId, $_SESSION['user_id']]);
                
                // Tambahkan kedua user ke conversation
                $memberStmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                $memberStmt->execute([$conversationId, $_SESSION['user_id']]);
                $memberStmt->execute([$conversationId, $contactUser['id']]);
                
                // Redirect ke new conversation
                header("Location: dashboard.php?conversation_id=" . $conversationId);
                exit;
            }
        } else {
            $_SESSION['error'] = "Kontak belum terdaftar di sistem. Silakan minta mereka membuat akun terlebih dahulu.";
            header("Location: dashboard.php");
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: dashboard.php");
        exit;
    }
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
    <title>Chat App - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .scrollbar-thin::-webkit-scrollbar {
            width: 6px;
        }
        .scrollbar-thumb-gray-600::-webkit-scrollbar-thumb {
            background-color: #4B5563;
            border-radius: 3px;
        }
        .scrollbar-track-transparent::-webkit-scrollbar-track {
            background: transparent;
        }
        .scrollbar-thumb-gray-600:hover::-webkit-scrollbar-thumb {
            background-color: #6B7280;
        }
    </style>
</head>
<body class="bg-gray-900 h-screen overflow-hidden">
    <!-- Error/Success Messages -->
    <?php if ($error): ?>
        <div class="fixed top-4 right-4 bg-red-600 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm transition-all duration-300 transform">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle mr-3"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="fixed top-4 right-4 bg-green-600 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm transition-all duration-300 transform">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-3"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-96 bg-gray-800 border-r border-gray-700 flex flex-col">
            <!-- User Profile dengan Hamburger Menu - IMPROVED -->
            <div class="p-4 border-b border-gray-700 bg-gray-900 relative">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="relative">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center">
                                <?php if ($user['avatar_url']): ?>
                                    <img src="<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                                <?php else: ?>
                                    <i class="fas fa-user text-white text-lg"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white"><?php echo htmlspecialchars($user['name']); ?></h3>
                            <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($user['phone']); ?></p>
                        </div>
                    </div>
                    
                    <!-- Hamburger Menu Button -->
                    <button id="hamburgerMenu" class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition duration-200 relative z-50">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>

                <!-- Hamburger Menu Dropdown  -->
                <div id="hamburgerDropdown" class="absolute mr-4 right-0 top-16 bg-gray-800 border border-gray-700 rounded-xl shadow-2xl z-50 hidden w-64 transition-all duration-300 transform origin-top-right opacity-0 scale-95 -translate-y-2">
                    <!-- Menu Items -->
                    <div class="rounded-xl py-2 bg-gray-800 ">

                        <!-- Kontak -->
                        <a href="contact.php" class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 group hover:translate-x-1 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-address-book text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Kontak</span>
                            <i class="fas fa-chevron-right text-gray-500 text-xs ml-auto group-hover:text-white transition-colors duration-200"></i>
                        </a>
                        
                        <!-- Edit Profile -->
                        <a href="profil.php" class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 group hover:translate-x-1 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-user-edit text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Edit Profile</span>
                            <i class="fas fa-chevron-right text-gray-500 text-xs ml-auto group-hover:text-white transition-colors duration-200"></i>
                        </a>
                        
                        <!-- Add Contact -->
                        <a href="addcontact.php" class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 group hover:translate-x-1 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-user-plus text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Tambah Kontak</span>
                            <i class="fas fa-chevron-right text-gray-500 text-xs ml-auto group-hover:text-white transition-colors duration-200"></i>
                        </a>
                        
                        <!-- Create Group -->
                        <a href="creategroup.php" class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 group hover:translate-x-1 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-orange-500 to-orange-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-users text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Buat Group</span>
                            <i class="fas fa-chevron-right text-gray-500 text-xs ml-auto group-hover:text-white transition-colors duration-200"></i>
                        </a>
                        
                        <!-- Join Group -->
                        <a href="join.php" class="flex items-center space-x-3 px-4 py-2 text-gray-300 hover:bg-gray-700 hover:text-white transition-all duration-200 group hover:translate-x-1 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-blue-600 to-blue-700 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-sign-in-alt text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Join Group</span>
                            <i class="fas fa-chevron-right text-gray-500 text-xs ml-auto group-hover:text-white transition-colors duration-200"></i>
                        </a>
                        
                        <!-- Divider -->
                        <div class="border-t border-gray-700 my-2 mx-4"></div>
                        
                        <!-- Logout -->
                        <a href="logout.php" class="flex items-center space-x-3 px-2 py-2 text-red-400 hover:bg-red-500 hover:text-white transition-all duration-200 group hover:translate-x-1 mx-2 rounded-lg">
                            <div class="w-8 h-8 bg-gradient-to-r from-red-500 to-red-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-200">
                                <i class="fas fa-sign-out-alt text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Search and New Chat -->
            <div class="p-4 border-b border-gray-700">
                <!-- New Chat Dropdown -->
                <div class="relative">
                    <button onclick="toggleContacts()" class="w-full bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white py-3 px-4 rounded-xl text-sm font-semibold transition-all duration-200 flex items-center justify-between">
                        <span>Mulai Chat Baru</span>
                        <i class="fas fa-chevron-down text-xs transition-transform duration-200" id="contactsChevron"></i>
                    </button>
                    
                    <div id="contactsDropdown" class="absolute top-full left-0 right-0 mt-2 bg-gray-700 border border-gray-600 rounded-xl shadow-2xl z-10 hidden max-h-80 overflow-y-auto scrollbar-thin scrollbar-thumb-gray-600 scrollbar-track-transparent">
                        <?php if (empty($contacts)): ?>
                            <div class="p-6 text-center text-gray-400">
                                <i class="fas fa-address-book text-3xl mb-3"></i>
                                <p class="text-sm mb-2">Belum ada kontak</p>
                                <a href="addcontact.php" class="text-blue-400 hover:text-blue-300 text-xs font-medium transition duration-200">
                                    Tambah kontak dulu
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <div class="hover:translate-lg-1 rounded-xl p-4 border-b border-gray-600 hover:bg-gray-600 cursor-pointer transition duration-150 group" 
                                    onclick="startChat('<?php echo $contact['contact_phone']; ?>')">
                                    <div class="flex items-center space-x-3">
                                        <div class="relative">
                                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-green-400 to-blue-500 flex items-center justify-center flex-shrink-0">
                                                <?php if ($contact['avatar_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($contact['avatar_url']); ?>" alt="Avatar" class="w-10 h-10 rounded-full">
                                                <?php else: ?>
                                                    <i class="fas fa-user text-white text-sm"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <!-- TAMPILKAN NAMA KONTAK YANG DISIMPAN USER -->
                                            <p class="text-white text-sm font-semibold truncate">
                                                <?php echo htmlspecialchars($contact['contact_name']); ?>
                                            </p>
                                            <p class="text-gray-400 text-xs truncate">
                                                <?php echo htmlspecialchars($contact['contact_phone']); ?>
                                            </p>
                                        </div>
                                        <?php if ($contact['status'] === 'terdaftar'): ?>
                                            <span class="text-green-400 text-xs" title="Terdaftar">🟢</span>
                                        <?php else: ?>
                                            <span class="text-yellow-400 text-xs" title="Belum terdaftar">⚠️</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Conversations Header -->
            <div class="px-4 py-3 border-b border-gray-700 bg-gray-900">
                <div class="flex justify-between items-center">
                    <h3 class="font-semibold text-white">Percakapan</h3>
                    <span class="text-xs text-gray-400 bg-gray-700 px-2 py-1 rounded-full" id="conversationCount">
                        <?php echo count($conversations); ?>
                    </span>
                </div>
            </div>

            <!-- Conversations List -->
            <div class="flex-1 overflow-y-auto scrollbar-thin scrollbar-thumb-gray-600 scrollbar-track-transparent hover:scrollbar-thumb-gray-500 transition-all duration-300" id="conversationsList">
                <?php if (empty($conversations)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <i class="fas fa-comments text-4xl mb-4 opacity-50"></i>
                        <p class="text-sm mb-2">Belum ada percakapan</p>
                        <p class="text-xs">Mulai chat dengan kontak Anda</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="relative group">
                            <a href="dashboard.php?conversation_id=<?php echo $conv['id']; ?>" class="block">
                                <div class="p-4 border-b border-gray-700 hover:bg-gray-700 transition duration-150 <?php echo $activeConversationId === $conv['id'] ?  : ''; ?>">
                                    <div class="flex items-center space-x-3">
                                        <div class="relative">
                                            <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-400 to-purple-500 flex items-center justify-center flex-shrink-0">
                                                <?php if ($conv['contact_avatar_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($conv['contact_avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                                                <?php else: ?>
                                                    <i class="fas fa-<?php echo $conv['type'] === 'private' ? 'user' : 'users'; ?> text-white"></i>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($conv['type'] === 'group'): ?>
                                                <span class="absolute -bottom-1 -right-1 bg-green-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                                    <i class="fas fa-users text-xs"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex justify-between items-start mb-1">
                                                <h4 class="font-semibold text-white text-sm truncate">
                                                    <?php echo htmlspecialchars($conv['display_name'] ?: 'Unknown Contact'); ?>
                                                    <?php if ($conv['type'] === 'group'): ?>
                                                        <span class="text-xs text-gray-400 ml-1">
                                                            (<?php echo $conv['member_count']; ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                </h4>
                                                <?php if ($conv['last_message_time']): ?>
                                                    <span class="text-xs text-gray-400 whitespace-nowrap flex-shrink-0 ml-2">
                                                        <?php echo date('H:i', strtotime($conv['last_message_time'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="flex justify-between items-center">
                                                <p class="text-gray-400 text-sm truncate">
                                                    <?php 
                                                    if ($conv['last_message_content']) {
                                                        $messageContent = htmlspecialchars($conv['last_message_content']);
                                                        if (strlen($messageContent) > 35) {
                                                            $messageContent = substr($messageContent, 0, 35) . '...';
                                                        }
                                                        echo ($conv['type'] === 'group' && $conv['last_message_sender']) ? 
                                                             $conv['last_message_sender'] . ': ' . $messageContent : $messageContent;
                                                    } else {
                                                        echo 'Belum ada pesan';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                            
                            <!-- Group context menu -->
                            <?php if ($conv['type'] === 'group'): ?>
                                <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                    <a href="groupinfo.php?conversation_id=<?php echo $conv['id']; ?>" 
                                       class="bg-gray-600 hover:bg-gray-500 text-white p-1 rounded text-xs transition duration-200"
                                       title="Info Group">
                                        <i class="fas fa-cog"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Area -->
        <div class="flex-1 flex flex-col">
            <?php if ($activeConversationId && $activeConversation): ?>
                <!-- Chat Header - MODIFIED FOR REAL-TIME STATUS -->
                <div class="bg-gray-800 border-b border-gray-700 p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3 cursor-pointer group" 
                             onclick="<?php echo $activeConversation['type'] === 'private' ? 'openOpponentProfile(\'' . $targetUserId . '\')' : 'window.location.href=\'groupinfo.php?conversation_id=' . $activeConversationId . '\''; ?>">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center group-hover:scale-105 transition-transform duration-200 relative">
                                <?php if ($activeConversation['type'] === 'private' && $otherUserInfo && $otherUserInfo['avatar_url']): ?>
                                    <img src="<?php echo htmlspecialchars($otherUserInfo['avatar_url']); ?>" alt="Avatar" class="w-10 h-10 rounded-full">
                                <?php elseif ($activeConversation['type'] === 'group' && $activeConversation['avatar_url']): ?>
                                    <img src="<?php echo htmlspecialchars($activeConversation['avatar_url']); ?>" alt="Group Avatar" class="w-10 h-10 rounded-full">
                                <?php else: ?>
                                    <i class="fas fa-<?php echo $activeConversation['type'] === 'private' ? 'user' : 'users'; ?> text-white"></i>
                                <?php endif; ?>
                                
                                <!-- Online Status Indicator  -->
                                <?php if ($activeConversation['type'] === 'private' && $otherUserInfo): ?>
                                    <div class="absolute -bottom-1 -right-1 w-3 h-3 rounded-full border-2 border-gray-800 <?php echo ($otherUserInfo['online_status'] === 'online') ? 'bg-green-500' : 'bg-gray-500'; ?>"></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3 class="font-semibold text-white group-hover:text-blue-300 transition-colors duration-200">
                                    <?php 
                                    if ($activeConversation['type'] === 'private' && $otherUserInfo) {
                                        echo htmlspecialchars($otherUserInfo['display_name']);
                                    } else {
                                        echo htmlspecialchars($activeConversation['title']);
                                    }
                                    ?>
                                </h3>
                                <p class="text-gray-400 text-sm group-hover:text-gray-300 transition-colors duration-200" id="userStatusText">
                                    <?php 
                                    if ($activeConversation['type'] === 'private' && $otherUserInfo) {
                                        if ($otherUserInfo['online_status'] === 'online') {
                                            echo 'Online';
                                        } else {
                                            echo 'Terakhir online: ' . ($otherUserInfo['last_seen'] ? date('d M Y H:i', strtotime($otherUserInfo['last_seen'])) : 'Belum pernah');
                                        }
                                    } elseif ($activeConversation['type'] === 'group') {
                                        echo $activeConversation['member_count'] . ' anggota';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <!-- Tombol Tambah Kontak - Hanya tampil untuk private chat dan kontak belum disimpan -->
                            <?php if ($activeConversation['type'] === 'private' && $otherUserInfo && $otherUserInfo['is_contact_saved'] == 0): ?>
                                <button onclick="openAddContactModal('<?php echo $otherUserInfo['phone']; ?>', '<?php echo htmlspecialchars($otherUserInfo['display_name']); ?>')" 
                                        class="flex items-center space-x-2 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white py-2 px-4 rounded-xl text-sm font-semibold transition duration-200 hover:scale-105">
                                    <i class="fas fa-user-plus"></i>
                                    <span>Tambah Kontak</span>
                                </button>
                            <?php endif; ?>
                            
                            <!-- Tombol Info Group -->
                            <?php if ($activeConversation['type'] === 'group'): ?>
                                <a href="groupinfo.php?conversation_id=<?php echo $activeConversationId; ?>" 
                                   class="flex items-center space-x-2 bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-xl text-sm font-semibold transition duration-200">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Info Group</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Messages Area -->
                <div class="flex-1 bg-gray-900 p-4 overflow-y-auto scrollbar-thin scrollbar-thumb-gray-600 scrollbar-track-transparent hover:scrollbar-thumb-gray-500 transition-all duration-300" id="messagesContainer">
                    <!-- Messages will be loaded here via AJAX -->
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-comments text-3xl mb-3 opacity-50"></i>
                        <p>Memuat pesan...</p>
                    </div>
                </div>

                <!-- Message Input -->
                <div class="bg-gray-800 border-t border-gray-700 p-4">
                    <form id="messageForm" class="flex items-center space-x-3" enctype="multipart/form-data">
                        <input type="hidden" name="conversation_id" value="<?php echo $activeConversationId; ?>">
                        
                        <button type="button" onclick="openFilePicker()" class="p-3 text-gray-400 hover:text-white hover:bg-gray-700 rounded-xl transition duration-200" title="Lampirkan File">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        
                        <div class="flex-1">
                            <input 
                                type="text" 
                                name="message" 
                                placeholder="Ketik pesan..." 
                                class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition duration-200"
                                id="messageInput"
                                oninput="handleTyping()"
                            >
                        </div>
                        
                        <button type="submit" class="p-3 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white rounded-xl transition duration-200">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <!-- No Conversation Selected -->
                <div class="flex-1 flex items-center justify-center bg-gray-900">
                    <div class="text-center text-gray-400 max-w-md">
                        <div class="w-24 h-24 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-comments text-white text-3xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-white mb-3">Selamat Datang di Chat App</h3>
                        <p class="text-gray-400 mb-6">Pilih percakapan dari sidebar atau mulai chat baru dengan kontak Anda</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hidden File Input -->
    <input type="file" id="fileInput" class="hidden" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">

    <!-- Add Contact Modal -->
    <div id="addContactModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4 transition-all duration-300">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full transform transition-all duration-300">
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-user-plus text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-white">Tambah Kontak</h3>
                    <p class="text-gray-400 text-sm">Tambahkan nomor ini ke daftar kontak Anda</p>
                </div>
            </div>
            
            <form id="addContactForm">
                <input type="hidden" id="modalContactPhone" name="contact_phone">
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Nomor Telepon</label>
                    <input 
                        type="text" 
                        id="modalContactPhoneDisplay" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition duration-200"
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
                        class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition duration-200"
                        required
                    >
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
// Global variables untuk AJAX
let typingTimer;
let lastTypingTime = 0;
let isTyping = false;
let isUserScrolling = false;
let scrollTimer = null;

// Hamburger Menu Functionality - IMPROVED
document.addEventListener('DOMContentLoaded', function() {
    const hamburgerMenu = document.getElementById('hamburgerMenu');
    const hamburgerDropdown = document.getElementById('hamburgerDropdown');
    
    if (hamburgerMenu && hamburgerDropdown) {
        // Toggle dropdown dengan animasi yang lebih smooth
        hamburgerMenu.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            
            const isHidden = hamburgerDropdown.classList.contains('hidden');
            
            if (isHidden) {
                // Show dropdown
                hamburgerDropdown.classList.remove('hidden');
                setTimeout(() => {
                    hamburgerDropdown.classList.remove('opacity-0', 'scale-95', '-translate-y-2');
                    hamburgerDropdown.classList.add('opacity-100', 'scale-100', 'translate-y-0');
                }, 10);
            } else {
                // Hide dropdown
                hamburgerDropdown.classList.remove('opacity-100', 'scale-100', 'translate-y-0');
                hamburgerDropdown.classList.add('opacity-0', 'scale-95', '-translate-y-2');
                setTimeout(() => {
                    hamburgerDropdown.classList.add('hidden');
                }, 300);
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!hamburgerDropdown.contains(e.target) && !hamburgerMenu.contains(e.target)) {
                hamburgerDropdown.classList.remove('opacity-100', 'scale-100', 'translate-y-0');
                hamburgerDropdown.classList.add('opacity-0', 'scale-95', '-translate-y-2');
                setTimeout(() => {
                    hamburgerDropdown.classList.add('hidden');
                }, 300);
            }
        });
        
        // Close dropdown when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !hamburgerDropdown.classList.contains('hidden')) {
                hamburgerDropdown.classList.remove('opacity-100', 'scale-100', 'translate-y-0');
                hamburgerDropdown.classList.add('opacity-0', 'scale-95', '-translate-y-2');
                setTimeout(() => {
                    hamburgerDropdown.classList.add('hidden');
                }, 300);
            }
        });
        
        // Prevent dropdown from closing when clicking inside it
        hamburgerDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});

// Function untuk membuka profil lawan chat
function openOpponentProfile(targetUserId) {
    window.location.href = `profil_lawan.php?user_id=${targetUserId}`;
}

// Toggle contacts dropdown
function toggleContacts() {
    const dropdown = document.getElementById('contactsDropdown');
    const chevron = document.getElementById('contactsChevron');
    dropdown.classList.toggle('hidden');
    chevron.classList.toggle('rotate-180');
}

// Start new chat
function startChat(contactPhone) {
    window.location.href = `dashboard.php?start_chat=1&contact_phone=${contactPhone}`;
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('contactsDropdown');
    const button = event.target.closest('button');
    if (!button && dropdown && !dropdown.contains(event.target)) {
        dropdown.classList.add('hidden');
        document.getElementById('contactsChevron').classList.remove('rotate-180');
    }
});

// Auto-hide messages after 3 seconds
setTimeout(() => {
    const messages = document.querySelectorAll('.fixed');
    messages.forEach(msg => {
        if (msg.textContent.includes('error') || msg.textContent.includes('success')) {
            msg.remove();
        }
    });
}, 3000);

// Setup scroll detection
function setupScrollDetection() {
    const messagesContainer = document.getElementById('messagesContainer');
    if (!messagesContainer) return;
    
    messagesContainer.addEventListener('scroll', function() {
        isUserScrolling = true;
        
        // Clear existing timer
        if (scrollTimer) clearTimeout(scrollTimer);
        
        // Set timer to reset scrolling flag after user stops scrolling
        scrollTimer = setTimeout(() => {
            isUserScrolling = false;
        }, 1000);
    });
}

// Check if scroll is at bottom
function isScrollAtBottom(container, threshold = 100) {
    return container.scrollTop + container.clientHeight >= container.scrollHeight - threshold;
}

// Real-time status update untuk user
function updateUserStatusRealTime() {
    const conversationId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!conversationId) return;

    // Cek apakah ini private conversation
    const chatHeader = document.querySelector('.bg-gray-800.border-b');
    if (!chatHeader || !chatHeader.querySelector('.relative .bg-green-500, .relative .bg-gray-500')) {
        return; // Bukan private chat atau tidak ada status indicator
    }

    fetch('get_user_status.php?conversation_id=' + conversationId, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.user_status) {
            updateStatusUI(data.user_status);
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
    });
}

// Update UI dengan status baru
function updateStatusUI(userStatus) {
    const statusIndicator = document.querySelector('.absolute.-bottom-1.-right-1');
    const statusText = document.getElementById('userStatusText');
    
    if (!statusIndicator || !statusText) return;
    
    // Update status indicator
    if (userStatus.online_status === 'online') {
        statusIndicator.classList.remove('bg-gray-500');
        statusIndicator.classList.add('bg-green-500');
        statusText.textContent = 'Online';
    } else {
        statusIndicator.classList.remove('bg-green-500');
        statusIndicator.classList.add('bg-gray-500');
        const lastSeen = userStatus.last_seen ? 
            'Terakhir online: ' + formatLastSeen(userStatus.last_seen) : 
            'Offline';
        statusText.textContent = lastSeen;
    }
}

// Format last seen time
function formatLastSeen(timestamp) {
    const now = new Date();
    const lastSeen = new Date(timestamp);
    const diffMs = now - lastSeen;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);  
    if (diffMins < 1) return 'Baru saja';
    if (diffMins < 60) return diffMins + ' menit lalu';
    if (diffHours < 24) return diffHours + ' jam lalu';
    if (diffDays === 1) return 'Kemarin';
    if (diffDays < 7) return diffDays + ' hari lalu';  
    return lastSeen.toLocaleDateString('id-ID', { 
        day: 'numeric', 
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Message form submission
document.getElementById('messageForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const message = formData.get('message').trim();
    
    if (message) {
        sendMessage(formData);
    }
});

// Send message to server
function sendMessage(formData) {
    const submitButton = document.querySelector('#messageForm button[type="submit"]');
    const originalContent = submitButton.innerHTML;
    
    // Show loading state
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitButton.disabled = true;

    // Create FormData and add action
    const data = new FormData();
    data.append('action', 'send_message');
    data.append('conversation_id', formData.get('conversation_id'));
    data.append('message', formData.get('message'));

    fetch('dashboard.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear input
            document.querySelector('input[name="message"]').value = '';
            
            // Add new message to chat
            if (data.message_data) {
                addMessageToChat(data.message_data, true);
            }
            
            // Update conversations list
            loadConversations();
            
        } else {
            alert('Gagal mengirim pesan: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat mengirim pesan');
    })
    .finally(() => {
        // Restore button state
        submitButton.innerHTML = originalContent;
        submitButton.disabled = false;
    });
}

// Add message to chat UI
function addMessageToChat(messageData, isOwnMessage = false) {
    const messagesContainer = document.getElementById('messagesContainer');
    const wasAtBottom = isScrollAtBottom(messagesContainer);
    
    // Hanya tampilkan jam saja
    const time = new Date(messageData.created_at).toLocaleTimeString('id-ID', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });

    // LOGIKA CEKLIS SEDERHANA: 
    // Ceklis 1 untuk belum dibaca, Ceklis 2 untuk sudah dibaca
    const messageStatus = messageData.message_status || 'sent';
    let checkIcon = '';
    
    if (isOwnMessage) {
        if (messageStatus === 'read') {
            checkIcon = ' <i class="fas fa-check-double text-blue-300 ml-1" title="Dibaca"></i>';
        } else {
            checkIcon = ' <i class="fas fa-check text-gray-400 ml-1" title="Terkirim"></i>';
        }
    }
    
    const messageElement = document.createElement('div');
    messageElement.className = `flex ${isOwnMessage ? 'justify-end' : 'justify-start'} mb-4 message-item`;
    messageElement.setAttribute('data-message-id', messageData.id);
    messageElement.innerHTML = `
        <div class="max-w-xs lg:max-w-md ${isOwnMessage ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-gray-700 text-white rounded-2xl rounded-bl-none'} px-4 py-2 relative group">
            ${isOwnMessage ? `
                <button onclick="deleteMessage('${messageData.id}')" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-200 hover:bg-red-600 hover:scale-110">
                    <i class="fas fa-times text-xs"></i>
                </button>
            ` : ''}
            ${!isOwnMessage ? `<p class="text-xs text-blue-300 font-semibold mb-1">${messageData.sender_name}</p>` : ''}
            <p class="break-words ">${messageData.content}</p>
            <p class="text-xs ${isOwnMessage ? 'text-blue-200' : 'text-gray-400'} text-right mt-1">
                ${time}${checkIcon}
            </p>
        </div>
    `;

    messagesContainer.appendChild(messageElement);
    
    // Remove "no messages" placeholder if it exists
    const noMessages = messagesContainer.querySelector('.text-center');
    if (noMessages) {
        noMessages.remove();
    }
    
    // Scroll hanya jika sebelumnya di bottom atau pesan baru dikirim oleh user
    setTimeout(() => {
        if (wasAtBottom || isOwnMessage) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }, 100);
}

// Load messages function dengan smart scroll
function loadMessages() {
    const conversationId = document.querySelector('input[name="conversation_id"]')?.value;
    if (!conversationId) return;

    // Simpan posisi scroll sebelum refresh
    const messagesContainer = document.getElementById('messagesContainer');
    const scrollPosBefore = messagesContainer.scrollTop;
    const isAtBottom = isScrollAtBottom(messagesContainer);

    const data = new FormData();
    data.append('action', 'get_messages');
    data.append('conversation_id', conversationId);

    fetch('dashboard.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.html) {
                messagesContainer.innerHTML = data.html;
                
                // Smart scroll logic
                setTimeout(() => {
                    if (isUserScrolling) {
                        // Jika user sedang scroll, pertahankan posisi
                        messagesContainer.scrollTop = scrollPosBefore;
                    } else if (isAtBottom) {
                        // Jika sebelumnya di bottom, scroll ke bottom
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                    // Jika tidak di bottom dan user tidak scroll, biarkan posisi tetap
                }, 100);
            }
        }
    })
    .catch(error => {
        console.error('Error loading messages:', error);
    });
}

// Load conversations list via AJAX
function loadConversations() {
    const data = new FormData();
    data.append('action', 'get_conversations');

    fetch('dashboard.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const conversationsList = document.getElementById('conversationsList');
            const conversationCount = document.getElementById('conversationCount');
            
            if (data.html) {
                conversationsList.innerHTML = data.html;
            }
            
            if (conversationCount) {
                conversationCount.textContent = data.count;
            }
        }
    })
    .catch(error => {
        console.error('Error loading conversations:', error);
    });
}

// Update user status (online/offline)
function updateUserStatus() {
    const data = new FormData();
    data.append('action', 'update_user_status');

    fetch('dashboard.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to update user status');
        }
    })
    .catch(error => {
        console.error('Error updating user status:', error);
    });
}

// Handle typing indicator
function handleTyping() {
    const messageInput = document.getElementById('messageInput');
    if (!messageInput) return;

    const now = new Date().getTime();
    const typingLength = messageInput.value.length;

    if (typingLength > 0 && !isTyping) {
        isTyping = true;
        // Di sini bisa ditambahkan AJAX untuk mengirim status typing ke server
        showTypingIndicator();
    }

    lastTypingTime = now;

    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
        const timeDiff = now - lastTypingTime;
        if (timeDiff >= 1000 && isTyping) {
            isTyping = false;
            hideTypingIndicator();
        }
    }, 1000);
}

// Show typing indicator
function showTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.classList.remove('hidden');
        
        // Scroll to bottom when someone is typing
        const messagesContainer = document.getElementById('messagesContainer');
        if (messagesContainer) {
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }
    }
}

// Hide typing indicator
function hideTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.classList.add('hidden');
    }
}

// Auto-focus message input when conversation is selected
<?php if ($activeConversationId): ?>
    document.addEventListener('DOMContentLoaded', function() {
        setupScrollDetection(); // Setup scroll detection
        
        const messageInput = document.querySelector('input[name="message"]');
        if (messageInput) {
            messageInput.focus();
        }
        
        // Load initial messages dan conversations
        loadMessages();
        loadConversations();
        
        // Auto-refresh dengan interval yang berbeda
        window.messagesRefreshInterval = setInterval(() => {
            if (document.querySelector('input[name="conversation_id"]')) {
                loadMessages();
            }
        }, 3000); // Refresh messages setiap 3 detik
        
        window.conversationsRefreshInterval = setInterval(() => {
            loadConversations();
        }, 8000); // Refresh conversations setiap 8 detik
        
        window.statusRefreshInterval = setInterval(() => {
            updateUserStatus();
        }, 30000); // Update user's own status setiap 30 detik
        
        // REAL-TIME STATUS UPDATE - lebih sering
        window.userStatusInterval = setInterval(() => {
            updateUserStatusRealTime();
        }, 10000); // Update opponent status setiap 10 detik
    });
<?php else: ?>
    // Jika tidak ada conversation yang aktif, hanya refresh conversations
    document.addEventListener('DOMContentLoaded', function() {
        loadConversations();
        
        window.conversationsRefreshInterval = setInterval(() => {
            loadConversations();
        }, 8000);
        
        window.statusRefreshInterval = setInterval(() => {
            updateUserStatus();
        }, 30000);
    });
<?php endif; ?>

// Handle page visibility change - optimasi performa
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Clear semua interval ketika tab tidak aktif
        if (window.messagesRefreshInterval) {
            clearInterval(window.messagesRefreshInterval);
        }
        if (window.conversationsRefreshInterval) {
            clearInterval(window.conversationsRefreshInterval);
        }
        if (window.statusRefreshInterval) {
            clearInterval(window.statusRefreshInterval);
        }
        if (window.userStatusInterval) {
            clearInterval(window.userStatusInterval);
        }
    } else {
        // Restart intervals ketika tab aktif kembali
        const conversationId = document.querySelector('input[name="conversation_id"]')?.value;
        
        if (conversationId) {
            window.messagesRefreshInterval = setInterval(() => {
                loadMessages();
            }, 3000);
            
            window.userStatusInterval = setInterval(() => {
                updateUserStatusRealTime();
            }, 10000);
        }
        
        window.conversationsRefreshInterval = setInterval(() => {
            loadConversations();
        }, 8000);
        
        window.statusRefreshInterval = setInterval(() => {
            updateUserStatus();
        }, 30000);
        
        // Immediate refresh ketika kembali ke tab
        setTimeout(() => {
            if (conversationId) {
                loadMessages();
                updateUserStatusRealTime(); // Immediate status update
            }
            loadConversations();
        }, 1000);
    }
});

// File attachment functions
let selectedFiles = [];

function openFilePicker() {
    document.getElementById('fileInput').click();
}

// Handle file selection - langsung kirim tanpa preview
document.getElementById('fileInput').addEventListener('change', function(e) {
    const files = e.target.files;
    if (files.length > 0) {
        selectedFiles = Array.from(files);
        sendFileMessage();
    }
});

// Send file message langsung tanpa preview
function sendFileMessage() {
    if (selectedFiles.length === 0) return;
    
    const file = selectedFiles[0];
    const formData = new FormData();
    formData.append('action', 'send_file_message');
    formData.append('conversation_id', document.querySelector('input[name="conversation_id"]').value);
    formData.append('message', document.getElementById('messageInput').value);
    formData.append('file', file);
    
    const submitButton = document.querySelector('#messageForm button[type="submit"]');
    const originalContent = submitButton.innerHTML;
    
    // Show loading state
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitButton.disabled = true;
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Clear input and file input
            document.getElementById('messageInput').value = '';
            document.getElementById('fileInput').value = '';
            selectedFiles = [];
            
            // Add message to chat
            if (data.message_data) {
                addFileMessageToChat(data.message_data, true);
            }
            
            // Update conversations list
            loadConversations();
            
            // Show success notification
            showNotification('File berhasil dikirim', 'success');
            
        } else {
            // Show specific error message
            showNotification('Gagal mengirim file: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Terjadi kesalahan saat mengirim file: ' + error.message, 'error');
    })
    .finally(() => {
        // Restore button state
        submitButton.innerHTML = originalContent;
        submitButton.disabled = false;
    });
}

// Notification function
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    const bgColor = type === 'success' ? 'bg-green-600' : 
                   type === 'error' ? 'bg-red-600' : 'bg-blue-600';
    
    notification.className = `fixed top-4 right-4 ${bgColor} text-white p-4 rounded-lg shadow-lg z-50 max-w-sm transition-all duration-300 transform translate-x-full`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} mr-3"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, 5000);
    
    // Allow manual close
    notification.addEventListener('click', () => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    });
}

// Add file message to chat UI
function addFileMessageToChat(messageData, isOwnMessage = false) {
    const messagesContainer = document.getElementById('messagesContainer');
    const wasAtBottom = isScrollAtBottom(messagesContainer);
    
    // Hanya tampilkan jam saja
    const time = new Date(messageData.created_at).toLocaleTimeString('id-ID', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });

    // LOGIKA CEKLIS SEDERHANA: 
    // Ceklis 1 untuk belum dibaca, Ceklis 2 untuk sudah dibaca
    const messageStatus = messageData.message_status || 'sent';
    let checkIcon = '';
    
    if (isOwnMessage) {
        if (messageStatus === 'read') {
            checkIcon = ' <i class="fas fa-check-double text-blue-300 ml-1" title="Dibaca"></i>';
        } else {
            checkIcon = ' <i class="fas fa-check text-gray-400 ml-1" title="Terkirim"></i>';
        }
    }

    let fileContent = '';
    if (messageData.file_url) {
        if (messageData.content_type === 'image') {
            fileContent = `
                <div class="mb-2">
                    <img src="${messageData.file_url}" alt="Gambar" class="max-w-full max-h-64 rounded-lg cursor-pointer hover:opacity-90 transition duration-200" onclick="openImageModal('${messageData.file_url}')">
                </div>
            `;
        } else if (messageData.content_type === 'video') {
            fileContent = `
                <div class="mb-2">
                    <video controls class="max-w-full max-h-64 rounded-lg hover:opacity-90 transition duration-200">
                        <source src="${messageData.file_url}" type="video/mp4">
                        Browser tidak mendukung video.
                    </video>
                </div>
            `;
        } else if (messageData.content_type === 'audio') {
            fileContent = `
                <div class="mb-2">
                    <audio controls class="w-full">
                        <source src="${messageData.file_url}" type="audio/mpeg">
                        Browser tidak mendukung audio.
                    </audio>
                </div>
            `;
        } else {
            // File biasa dengan coloring yang sesuai
            const fileColor = isOwnMessage ? 'bg-blue-500' : 'bg-gray-600';
            const textColor = isOwnMessage ? 'text-blue-100' : 'text-gray-300';
            const fileName = messageData.file_name || messageData.content || 'File';
            const fileSize = messageData.file_size ? formatFileSize(messageData.file_size) : '';
            
            fileContent = `
                <div class="mb-2">
                    <a href="${messageData.file_url}" target="_blank" class="flex items-center space-x-3 p-3 ${fileColor} rounded-lg hover:opacity-80 transition duration-200">
                        <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                            <i class="fas fa-file text-white"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white text-sm font-medium truncate">${fileName}</p>
                            <p class="${textColor} text-xs">${getFileType(messageData.file_url)} ${fileSize ? '• ' + fileSize : ''}</p>
                        </div>
                        <i class="fas fa-download ${textColor}"></i>
                    </a>
                </div>
            `;
        }
    }
}

// Utility functions
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function getFileType(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const fileTypes = {
        'jpg': 'Gambar JPEG',
        'jpeg': 'Gambar JPEG', 
        'png': 'Gambar PNG',
        'gif': 'Gambar GIF',
        'pdf': 'Dokumen PDF',
        'doc': 'Dokumen Word',
        'docx': 'Dokumen Word',
        'txt': 'File Teks',
        'mp4': 'Video MP4',
        'avi': 'Video AVI',
        'mov': 'Video MOV',
        'mp3': 'Audio MP3',
        'wav': 'Audio WAV'
    };
    return fileTypes[ext] || 'File';
}

// Image modal for fullscreen view
function openImageModal(imageUrl) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4 transition-all duration-300';
    modal.innerHTML = `
        <div class="relative max-w-4xl max-h-full transform transition-all duration-300 scale-95 opacity-0">
            <button onclick="this.parentElement.parentElement.remove()" class="absolute -top-12 right-0 text-white text-2xl hover:text-gray-300 transition duration-200">
                <i class="fas fa-times"></i>
            </button>
            <img src="${imageUrl}" alt="Fullscreen" class="max-w-full max-h-screen object-contain rounded-lg">
        </div>
    `;
    document.body.appendChild(modal);
    
    // Animate in
    setTimeout(() => {
        modal.querySelector('.relative').classList.remove('scale-95', 'opacity-0');
        modal.querySelector('.relative').classList.add('scale-100', 'opacity-100');
    }, 100);
}

// Add Contact Modal Functions
function openAddContactModal(phone, currentName) {
    document.getElementById('modalContactPhone').value = phone;
    document.getElementById('modalContactPhoneDisplay').value = phone;
    document.getElementById('modalContactName').value = currentName === phone ? '' : currentName;
    const modal = document.getElementById('addContactModal');
    modal.classList.remove('hidden');
    modal.classList.add('opacity-0');
    
    // Animate in
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        modal.classList.add('opacity-100');
    }, 100);
}

function closeAddContactModal() {
    const modal = document.getElementById('addContactModal');
    modal.classList.remove('opacity-100');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Handle add contact form submission
document.getElementById('addContactForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_contact');
    
    const submitButton = this.querySelector('button[type="submit"]');
    const originalContent = submitButton.innerHTML;
    
    // Show loading state
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitButton.disabled = true;
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeAddContactModal();
            // Update conversations list
            loadConversations();
            showNotification('Kontak berhasil ditambahkan', 'success');
        } else {
            alert('Gagal menambahkan kontak: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menambahkan kontak');
    })
    .finally(() => {
        // Restore button state
        submitButton.innerHTML = originalContent;
        submitButton.disabled = false;
    });
});

// Close modal when clicking outside
document.getElementById('addContactModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddContactModal();
    }
});

// Delete message function
function deleteMessage(messageId) {
    if (!confirm('Apakah Anda yakin ingin menghapus pesan ini?')) {
        return;
    }
    
    const data = new FormData();
    data.append('action', 'delete_message');
    data.append('message_id', messageId);
    
    fetch('dashboard.php', {
        method: 'POST',
        body: data
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Hapus elemen pesan dari UI
            const messageElement = document.querySelector(`.message-item[data-message-id="${messageId}"]`);
            if (messageElement) {
                messageElement.remove();
            }
            
            // Jika tidak ada pesan lagi, tampilkan placeholder
            const messagesContainer = document.getElementById('messagesContainer');
            const remainingMessages = messagesContainer.querySelectorAll('.message-item');
            if (remainingMessages.length === 0) {
                messagesContainer.innerHTML = `
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-comments text-3xl mb-3 opacity-50"></i>
                        <p class="text-lg">Belum ada pesan</p>
                        <p class="text-sm mt-2">Mulai percakapan dengan mengirim pesan pertama!</p>
                    </div>
                `;
            }
            
            showNotification('Pesan berhasil dihapus', 'success');
        } else {
            alert('Gagal menghapus pesan: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menghapus pesan');
    });
}

// Export functions untuk debugging
window.dashboardApp = {
    loadMessages,
    loadConversations,
    updateUserStatus,
    updateUserStatusRealTime,
    sendMessage,
    sendFileMessage,
    deleteMessage,
    showNotification
};
</script>
</body>
</html>
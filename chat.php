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

// Handle AJAX requests
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
            // Verify user is member of conversation
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
            
            // Update conversation updated_at and last_message_id
            $updateConvStmt = $db->prepare("UPDATE conversations SET updated_at = NOW(), last_message_id = ? WHERE id = ?");
            $updateConvStmt->execute([$messageId, $conversationId]);
            
            // Create message receipts for all conversation members except sender
            $membersStmt = $db->prepare("SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?");
            $membersStmt->execute([$conversationId, $_SESSION['user_id']]);
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $receiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at) VALUES (?, ?, NOW())");
            foreach ($members as $member) {
                $receiptStmt->execute([$messageId, $member['user_id']]);
            }
            
            // Create receipt for sender (mark as delivered immediately)
            $senderReceiptStmt = $db->prepare("INSERT INTO message_receipts (message_id, recipient_id, delivered_at, read_at) VALUES (?, ?, NOW(), NOW())");
            $senderReceiptStmt->execute([$messageId, $_SESSION['user_id']]);
            
            // Get the newly created message with sender info
            $newMessageStmt = $db->prepare("
                SELECT m.*, u.name as sender_name, u.avatar_url 
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.id = ?
            ");
            $newMessageStmt->execute([$messageId]);
            $newMessage = $newMessageStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Pesan berhasil dikirim',
                'message_data' => $newMessage
            ]);
            
        } catch (PDOException $e) {
            error_log("Database error in chat.php: " . $e->getMessage());
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
            echo json_encode(['success' => false, 'message' => 'File upload failed']);
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
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save file']);
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
        // For PDF, documents, spreadsheets, presentations, text files, archives - use 'file'
        
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
            $fileTypeForDB, // Use truncated version
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
            $contentType, // Use only: 'text', 'image', 'file', 'audio', 'video'
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
        
        // Add file_name to response
        $newMessage['file_name'] = $file['name'];
        
        echo json_encode([
            'success' => true,
            'message' => 'File berhasil dikirim',
            'message_data' => $newMessage
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in chat.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
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
            // Verify user is member of conversation
            $checkStmt = $db->prepare("SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
            $checkStmt->execute([$conversationId, $_SESSION['user_id']]);
            
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Not a member of this conversation']);
                exit;
            }

            // Get messages for this conversation
            $messagesStmt = $db->prepare("
                SELECT 
                    m.*,
                    u.name as sender_name,
                    u.avatar_url,
                    CASE 
                        WHEN m.sender_id = ? THEN 'sent'
                        WHEN mr.read_at IS NOT NULL THEN 'read'
                        WHEN mr.delivered_at IS NOT NULL THEN 'delivered'
                        ELSE 'sent'
                    END as delivery_status
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN message_receipts mr ON m.id = mr.message_id AND mr.recipient_id = ?
                WHERE m.conversation_id = ? AND m.is_deleted = 0
                ORDER BY m.created_at ASC
            ");
            $messagesStmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $conversationId]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as read
            $markReadStmt = $db->prepare("
                UPDATE message_receipts mr
                JOIN messages m ON mr.message_id = m.id
                SET mr.read_at = NOW()
                WHERE m.conversation_id = ? AND mr.recipient_id = ? AND mr.read_at IS NULL
            ");
            $markReadStmt->execute([$conversationId, $_SESSION['user_id']]);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error loading messages: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

// Normal page load (GET request)
$conversationId = $_GET['conversation_id'] ?? '';

if (empty($conversationId)) {
    header('Location: dashboard.php');
    exit;
}

// Verify user is member of conversation
try {
    $checkStmt = $db->prepare("
        SELECT c.*, cm.role 
        FROM conversations c 
        JOIN conversation_members cm ON c.id = cm.conversation_id 
        WHERE c.id = ? AND cm.user_id = ?
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

// Get conversation details and other user info (for private chats)
$otherUser = null;
if ($conversation['type'] === 'private') {
    try {
        $otherUserStmt = $db->prepare("
            SELECT u.* 
            FROM conversation_members cm 
            JOIN users u ON cm.user_id = u.id 
            WHERE cm.conversation_id = ? AND cm.user_id != ?
        ");
        $otherUserStmt->execute([$conversationId, $_SESSION['user_id']]);
        $otherUser = $otherUserStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Continue even if error
    }
}

// Get initial messages for this conversation
try {
    $messagesStmt = $db->prepare("
        SELECT 
            m.*,
            u.name as sender_name,
            u.avatar_url,
            CASE 
                WHEN m.sender_id = ? THEN 'sent'
                WHEN mr.read_at IS NOT NULL THEN 'read'
                WHEN mr.delivered_at IS NOT NULL THEN 'delivered'
                ELSE 'sent'
            END as status
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN message_receipts mr ON m.id = mr.message_id AND mr.recipient_id = ?
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $messagesStmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $conversationId]);
    $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark messages as read
    $markReadStmt = $db->prepare("
        UPDATE message_receipts mr
        JOIN messages m ON mr.message_id = m.id
        SET mr.read_at = NOW()
        WHERE m.conversation_id = ? AND mr.recipient_id = ? AND mr.read_at IS NULL
    ");
    $markReadStmt->execute([$conversationId, $_SESSION['user_id']]);
} catch (PDOException $e) {
    $messages = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - <?php echo $conversation['type'] === 'private' ? htmlspecialchars($otherUser['name'] ?? 'Unknown') : htmlspecialchars($conversation['title'] ?? 'Group Chat'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-900 h-screen flex flex-col">
    <!-- Header -->
    <div class="bg-gray-800 border-b border-gray-700 flex-shrink-0">
        <div class="px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-blue-400 hover:text-blue-300 transition duration-200">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center">
                        <?php if ($conversation['type'] === 'private' && $otherUser && $otherUser['avatar_url']): ?>
                            <img src="<?php echo htmlspecialchars($otherUser['avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                        <?php else: ?>
                            <i class="fas fa-<?php echo $conversation['type'] === 'private' ? 'user' : 'users'; ?> text-white"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white">
                            <?php 
                            if ($conversation['type'] === 'private' && $otherUser) {
                                echo htmlspecialchars($otherUser['name']);
                            } else {
                                echo htmlspecialchars($conversation['title'] ?? 'Group Chat');
                            }
                            ?>
                        </h1>
                        <p class="text-gray-400 text-sm">
                            <?php 
                            if ($conversation['type'] === 'private' && $otherUser) {
                                echo isUserOnline($otherUser['last_seen']) ? 'Online' : 'Offline';
                            } else {
                                echo 'Group Chat';
                            }
                            ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-300"><?php echo htmlspecialchars($user['name']); ?></span>
                    <div class="flex items-center space-x-2">
                        <button class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition duration-200">
                            <i class="fas fa-phone"></i>
                        </button>
                        <button class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition duration-200">
                            <i class="fas fa-video"></i>
                        </button>
                        <button class="p-2 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition duration-200">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages Area -->
    <div id="messagesContainer" class="flex-1 bg-gray-900 p-4 overflow-y-auto">
        <?php if (empty($messages)): ?>
            <div class="text-center py-12 text-gray-500">
                <i class="fas fa-comments text-3xl mb-3 opacity-50"></i>
                <p class="text-lg">Belum ada pesan</p>
                <p class="text-sm mt-2">Mulai percakapan dengan mengirim pesan pertama!</p>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $message): ?>
                <?php
                $isOwnMessage = $message['sender_id'] === $_SESSION['user_id'];
                $time = formatMessageTime($message['created_at']);
                ?>
                <div class="flex <?php echo $isOwnMessage ? 'justify-end' : 'justify-start'; ?> mb-4">
                    <div class="max-w-xs lg:max-w-md <?php echo $isOwnMessage ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-gray-700 text-white rounded-2xl rounded-bl-none'; ?> px-4 py-2">
                        <?php if (!$isOwnMessage): ?>
                            <p class="text-xs text-blue-300 font-semibold mb-1"><?php echo htmlspecialchars($message['sender_name']); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($message['file_url']): ?>
                            <?php if ($message['content_type'] === 'image'): ?>
                                <div class="mb-2">
                                    <img src="<?php echo htmlspecialchars($message['file_url']); ?>" alt="Gambar" class="max-w-full max-h-64 rounded-lg cursor-pointer" onclick="openImageModal('<?php echo htmlspecialchars($message['file_url']); ?>')">
                                </div>
                            <?php elseif ($message['content_type'] === 'video'): ?>
                                <div class="mb-2">
                                    <video controls class="max-w-full max-h-64 rounded-lg">
                                        <source src="<?php echo htmlspecialchars($message['file_url']); ?>" type="video/mp4">
                                        Browser tidak mendukung video.
                                    </video>
                                </div>
                            <?php elseif ($message['content_type'] === 'audio'): ?>
                                <div class="mb-2">
                                    <audio controls class="w-full">
                                        <source src="<?php echo htmlspecialchars($message['file_url']); ?>" type="audio/mpeg">
                                        Browser tidak mendukung audio.
                                    </audio>
                                </div>
                            <?php else: ?>
                                <?php
                                $fileColor = $isOwnMessage ? 'bg-blue-500' : 'bg-gray-600';
                                $textColor = $isOwnMessage ? 'text-blue-100' : 'text-gray-300';
                                $fileIcon = getFileIcon($message['file_url']);
                                $fileType = getFileType($message['file_url']);
                                $fileSize = file_exists($message['file_url']) ? formatFileSize(filesize($message['file_url'])) : 'Unknown size';
                                ?>
                                <div class="mb-2">
                                    <a href="<?php echo htmlspecialchars($message['file_url']); ?>" target="_blank" class="flex items-center space-x-3 p-3 <?php echo $fileColor; ?> rounded-lg hover:opacity-80 transition duration-200">
                                        <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                                            <i class="<?php echo $fileIcon; ?> text-white"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-white text-sm font-medium truncate"><?php echo htmlspecialchars($message['content'] ?: basename($message['file_url'])); ?></p>
                                            <p class="<?php echo $textColor; ?> text-xs"><?php echo $fileType; ?> • <?php echo $fileSize; ?></p>
                                        </div>
                                        <i class="fas fa-download <?php echo $textColor; ?>"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($message['content'] && !$message['file_url']): ?>
                            <p class="break-words"><?php echo htmlspecialchars($message['content']); ?></p>
                        <?php endif; ?>
                        
                        <p class="text-xs <?php echo $isOwnMessage ? 'text-blue-200' : 'text-gray-400'; ?> text-right mt-1">
                            <?php echo $time; ?>
                            <?php if ($isOwnMessage): ?>
                                <i class="fas fa-check<?php echo $message['status'] === 'read' ? '-double' : ''; ?> ml-1"></i>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Message Input -->
    <div class="bg-gray-800 border-t border-gray-700 flex-shrink-0">
        <div class="p-4">
            <form id="messageForm" class="flex items-center space-x-3">
                <input type="hidden" name="conversation_id" value="<?php echo $conversationId; ?>">
                <input type="hidden" name="file_data" id="fileData">
                
                <button type="button" onclick="openFilePicker()" class="p-3 text-gray-400 hover:text-white hover:bg-gray-700 rounded-xl transition duration-200" title="Lampirkan File">
                    <i class="fas fa-paperclip"></i>
                </button>
                
                <div class="flex-1">
                    <input 
                        type="text" 
                        name="message" 
                        placeholder="Ketik pesan..." 
                        class="w-full bg-gray-700 border border-gray-600 rounded-xl px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        id="messageInput"
                        required
                    >
                </div>
                
                <button type="submit" class="p-3 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white rounded-xl transition duration-200">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Hidden File Input -->
    <input type="file" id="fileInput" class="hidden" accept="
        image/*,
        video/*,
        audio/*,
        .pdf,
        .doc,.docx,
        .xls,.xlsx,
        .ppt,.pptx,
        .txt,.csv,
        .zip,.rar,.7z
    ">

    <!-- File Preview Modal -->
    <div id="filePreviewModal" class="fixed inset-0 bg-black bg-opacity-70 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-2xl p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Preview File</h3>
                <button onclick="closeFilePreview()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="filePreviewContent" class="mb-4">
                <!-- Preview content will be inserted here -->
            </div>
            
            <div class="flex space-x-3">
                <button onclick="closeFilePreview()" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                    Batal
                </button>
                <button onclick="sendFileMessage()" class="flex-1 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white py-3 px-4 rounded-xl transition duration-200 font-medium">
                    Kirim File
                </button>
            </div>
        </div>
    </div>

    <script>
        // File attachment variables
        let selectedFiles = [];
        let currentFileIndex = 0;

        // Message form submission
        document.getElementById('messageForm').addEventListener('submit', function(e) {
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

            fetch('chat.php', {
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
            const time = new Date(messageData.created_at).toLocaleTimeString('id-ID', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });

            const messageElement = document.createElement('div');
            messageElement.className = `flex ${isOwnMessage ? 'justify-end' : 'justify-start'} mb-4`;
            messageElement.innerHTML = `
                <div class="max-w-xs lg:max-w-md ${isOwnMessage ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-gray-700 text-white rounded-2xl rounded-bl-none'} px-4 py-2">
                    ${!isOwnMessage ? `<p class="text-xs text-blue-300 font-semibold mb-1">${messageData.sender_name}</p>` : ''}
                    <p class="break-words">${messageData.content}</p>
                    <p class="text-xs ${isOwnMessage ? 'text-blue-200' : 'text-gray-400'} text-right mt-1">
                        ${time}
                        ${isOwnMessage ? '<i class="fas fa-check ml-1"></i>' : ''}
                    </p>
                </div>
            `;

            messagesContainer.appendChild(messageElement);
            
            // Remove "no messages" placeholder if it exists
            const noMessages = messagesContainer.querySelector('.text-center');
            if (noMessages) {
                noMessages.remove();
            }
            
            // Scroll to bottom
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }

        // Add file message to chat UI
        function addFileMessageToChat(messageData, isOwnMessage = false) {
            const messagesContainer = document.getElementById('messagesContainer');
            const time = new Date(messageData.created_at).toLocaleTimeString('id-ID', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });

            let fileContent = '';
            if (messageData.file_url) {
                if (messageData.content_type === 'image') {
                    fileContent = `
                        <div class="mb-2">
                            <img src="${messageData.file_url}" alt="Gambar" class="max-w-full max-h-64 rounded-lg cursor-pointer" onclick="openImageModal('${messageData.file_url}')">
                        </div>
                    `;
                } else if (messageData.content_type === 'video') {
                    fileContent = `
                        <div class="mb-2">
                            <video controls class="max-w-full max-h-64 rounded-lg">
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
                    const fileColor = isOwnMessage ? 'bg-blue-500' : 'bg-gray-600';
                    const textColor = isOwnMessage ? 'text-blue-100' : 'text-gray-300';
                    const fileName = messageData.content || 'File';
                    const fileIcon = getFileIcon(messageData.file_url || fileName);
                    const fileType = getFileType(messageData.file_url || fileName);
                    
                    fileContent = `
                        <div class="mb-2">
                            <a href="${messageData.file_url}" target="_blank" class="flex items-center space-x-3 p-3 ${fileColor} rounded-lg hover:opacity-80 transition duration-200">
                                <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                                    <i class="${fileIcon} text-white"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-white text-sm font-medium truncate">${fileName}</p>
                                    <p class="${textColor} text-xs">${fileType}</p>
                                </div>
                                <i class="fas fa-download ${textColor}"></i>
                            </a>
                        </div>
                    `;
                }
            }

            const messageElement = document.createElement('div');
            messageElement.className = `flex ${isOwnMessage ? 'justify-end' : 'justify-start'} mb-4`;
            messageElement.innerHTML = `
                <div class="max-w-md ${isOwnMessage ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-gray-700 text-white rounded-2xl rounded-bl-none'} px-4 py-2">
                    ${!isOwnMessage ? `<p class="text-xs text-blue-300 font-semibold mb-1">${messageData.sender_name}</p>` : ''}
                    ${fileContent}
                    ${messageData.content && !messageData.file_url ? `<p class="break-words">${messageData.content}</p>` : ''}
                    <p class="text-xs ${isOwnMessage ? 'text-blue-200' : 'text-gray-400'} text-right mt-1">
                        ${time}
                        ${isOwnMessage ? '<i class="fas fa-check ml-1"></i>' : ''}
                    </p>
                </div>
            `;

            messagesContainer.appendChild(messageElement);
            
            // Remove "no messages" placeholder if it exists
            const noMessages = messagesContainer.querySelector('.text-center');
            if (noMessages) {
                noMessages.remove();
            }
            
            // Scroll to bottom
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }

        // Load messages function
        function loadMessages() {
            const conversationId = document.querySelector('input[name="conversation_id"]')?.value;
            if (!conversationId) return;

            const data = new FormData();
            data.append('action', 'get_messages');
            data.append('conversation_id', conversationId);

            fetch('chat.php', {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const messagesContainer = document.getElementById('messagesContainer');
                    
                    if (data.messages) {
                        // Clear current messages
                        messagesContainer.innerHTML = '';
                        
                        if (data.messages.length === 0) {
                            messagesContainer.innerHTML = `
                                <div class="text-center py-12 text-gray-500">
                                    <i class="fas fa-comments text-3xl mb-3 opacity-50"></i>
                                    <p class="text-lg">Belum ada pesan</p>
                                    <p class="text-sm mt-2">Mulai percakapan dengan mengirim pesan pertama!</p>
                                </div>
                            `;
                        } else {
                            data.messages.forEach(message => {
                                const isOwnMessage = message.sender_id === <?php echo $_SESSION['user_id']; ?>;
                                if (message.file_url) {
                                    addFileMessageToChat(message, isOwnMessage);
                                } else {
                                    addMessageToChat(message, isOwnMessage);
                                }
                            });
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
            });
        }

        // File attachment functions
        function openFilePicker() {
            document.getElementById('fileInput').click();
        }

        // Handle file selection
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const files = e.target.files;
            if (files.length > 0) {
                selectedFiles = Array.from(files);
                currentFileIndex = 0;
                previewFile(0);
            }
        });

        // Preview selected file
        function previewFile(index) {
            if (index >= selectedFiles.length) return;
            
            const file = selectedFiles[index];
            const previewContent = document.getElementById('filePreviewContent');
            const reader = new FileReader();
            
            reader.onload = function(e) {
                let previewHTML = '';
                const fileType = getFileType(file.name);
                const fileIcon = getFileIcon(file.name);
                
                if (file.type.startsWith('image/')) {
                    previewHTML = `
                        <div class="text-center">
                            <img src="${e.target.result}" alt="Preview" class="max-h-64 mx-auto rounded-lg mb-4">
                            <p class="text-white font-medium truncate">${file.name}</p>
                            <p class="text-gray-400 text-sm">${formatFileSize(file.size)} • ${fileType}</p>
                        </div>
                    `;
                } else if (file.type.startsWith('video/')) {
                    previewHTML = `
                        <div class="text-center">
                            <video controls class="max-h-64 mx-auto rounded-lg mb-4">
                                <source src="${e.target.result}" type="${file.type}">
                                Browser tidak mendukung preview video.
                            </video>
                            <p class="text-white font-medium truncate">${file.name}</p>
                            <p class="text-gray-400 text-sm">${formatFileSize(file.size)} • ${fileType}</p>
                        </div>
                    `;
                } else if (file.type.startsWith('audio/')) {
                    previewHTML = `
                        <div class="text-center">
                            <audio controls class="w-full mb-4">
                                <source src="${e.target.result}" type="${file.type}">
                                Browser tidak mendukung preview audio.
                            </audio>
                            <p class="text-white font-medium truncate">${file.name}</p>
                            <p class="text-gray-400 text-sm">${formatFileSize(file.size)} • ${fileType}</p>
                        </div>
                    `;
                } else {
                    previewHTML = `
                        <div class="text-center">
                            <div class="w-16 h-16 bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="${fileIcon} text-2xl text-gray-400"></i>
                            </div>
                            <p class="text-white font-medium truncate">${file.name}</p>
                            <p class="text-gray-400 text-sm">${formatFileSize(file.size)} • ${fileType}</p>
                        </div>
                    `;
                }
                
                // Add multiple file navigation if more than one file
                if (selectedFiles.length > 1) {
                    previewHTML += `
                        <div class="flex justify-between items-center mt-4">
                            <button onclick="prevFile()" class="p-2 text-gray-400 hover:text-white ${index === 0 ? 'opacity-50 cursor-not-allowed' : ''}">
                                <i class="fas fa-chevron-left"></i> Sebelumnya
                            </button>
                            <span class="text-gray-400 text-sm">${index + 1} / ${selectedFiles.length}</span>
                            <button onclick="nextFile()" class="p-2 text-gray-400 hover:text-white ${index === selectedFiles.length - 1 ? 'opacity-50 cursor-not-allowed' : ''}">
                                Selanjutnya <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    `;
                }
                
                previewContent.innerHTML = previewHTML;
                document.getElementById('filePreviewModal').classList.remove('hidden');
            };
            
            reader.readAsDataURL(file);
        }

        // Navigate to previous file
        function prevFile() {
            if (currentFileIndex > 0) {
                currentFileIndex--;
                previewFile(currentFileIndex);
            }
        }

        // Navigate to next file
        function nextFile() {
            if (currentFileIndex < selectedFiles.length - 1) {
                currentFileIndex++;
                previewFile(currentFileIndex);
            }
        }

        // Close file preview
        function closeFilePreview() {
            document.getElementById('filePreviewModal').classList.add('hidden');
            document.getElementById('fileInput').value = '';
            selectedFiles = [];
        }

        // Send file message
        function sendFileMessage() {
            if (selectedFiles.length === 0) return;
            
            const file = selectedFiles[currentFileIndex];
            const formData = new FormData();
            formData.append('action', 'send_file_message');
            formData.append('conversation_id', document.querySelector('input[name="conversation_id"]').value);
            formData.append('message', document.getElementById('messageInput').value);
            formData.append('file', file);
            
            const submitButton = document.querySelector('#filePreviewModal button[onclick="sendFileMessage()"]');
            const originalContent = submitButton.innerHTML;
            
            // Show loading state
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengupload...';
            submitButton.disabled = true;
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear input and close modal
                    document.getElementById('messageInput').value = '';
                    closeFilePreview();
                    
                    // Add message to chat
                    if (data.message_data) {
                        addFileMessageToChat(data.message_data, true);
                    }
                } else {
                    alert('Gagal mengirim file: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat mengirim file');
            })
            .finally(() => {
                // Restore button state
                submitButton.innerHTML = originalContent;
                submitButton.disabled = false;
            });
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
                // Images
                'jpg': 'Gambar JPEG',
                'jpeg': 'Gambar JPEG', 
                'png': 'Gambar PNG',
                'gif': 'Gambar GIF',
                'webp': 'Gambar WEBP',
                'bmp': 'Gambar BMP',
                
                // Videos
                'mp4': 'Video MP4',
                'avi': 'Video AVI',
                'mov': 'Video MOV',
                'mkv': 'Video MKV',
                'webm': 'Video WEBM',
                'flv': 'Video FLV',
                'wmv': 'Video WMV',
                
                // Audio
                'mp3': 'Audio MP3',
                'wav': 'Audio WAV',
                'ogg': 'Audio OGG',
                'aac': 'Audio AAC',
                'flac': 'Audio FLAC',
                
                // Documents
                'pdf': 'Dokumen PDF',
                'doc': 'Dokumen Word',
                'docx': 'Dokumen Word',
                
                // Spreadsheets
                'xls': 'Spreadsheet Excel',
                'xlsx': 'Spreadsheet Excel',
                
                // Presentations
                'ppt': 'Presentasi PowerPoint',
                'pptx': 'Presentasi PowerPoint',
                
                // Text files
                'txt': 'File Teks',
                'csv': 'File CSV',
                
                // Archives
                'zip': 'Arsip ZIP',
                'rar': 'Arsip RAR',
                '7z': 'Arsip 7Z'
            };
            return fileTypes[ext] || 'File';
        }

        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const iconMap = {
                // Documents
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                
                // Spreadsheets
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                
                // Presentations
                'ppt': 'fas fa-file-powerpoint',
                'pptx': 'fas fa-file-powerpoint',
                
                // Archives
                'zip': 'fas fa-file-archive',
                'rar': 'fas fa-file-archive',
                '7z': 'fas fa-file-archive',
                
                // Text files
                'txt': 'fas fa-file-alt',
                'csv': 'fas fa-file-csv'
            };
            
            return iconMap[ext] || 'fas fa-file';
        }

        // Image modal for fullscreen view
        function openImageModal(imageUrl) {
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-90 z-50 flex items-center justify-center p-4';
            modal.innerHTML = `
                <div class="relative max-w-4xl max-h-full">
                    <button onclick="this.parentElement.parentElement.remove()" class="absolute -top-12 right-0 text-white text-2xl hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                    <img src="${imageUrl}" alt="Fullscreen" class="max-w-full max-h-screen object-contain">
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Auto-focus message input
        document.addEventListener('DOMContentLoaded', function() {
            const messageInput = document.querySelector('input[name="message"]');
            if (messageInput) {
                messageInput.focus();
            }
        });

        // Handle page visibility change to reduce load when tab is not active
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Clear interval when tab is not visible
                if (window.refreshInterval) {
                    clearInterval(window.refreshInterval);
                }
            } else {
                // Restart interval when tab becomes visible
                window.refreshInterval = setInterval(() => {
                    loadMessages();
                }, 3000);
                // Also load messages immediately
                loadMessages();
            }
        });
    </script>
</body>
</html>

<?php
// Helper functions untuk PHP
function getFileType($filename) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $fileTypes = [
        // Images
        'jpg' => 'Gambar JPEG',
        'jpeg' => 'Gambar JPEG', 
        'png' => 'Gambar PNG',
        'gif' => 'Gambar GIF',
        'webp' => 'Gambar WEBP',
        'bmp' => 'Gambar BMP',
        
        // Videos
        'mp4' => 'Video MP4',
        'avi' => 'Video AVI',
        'mov' => 'Video MOV',
        'mkv' => 'Video MKV',
        'webm' => 'Video WEBM',
        
        // Audio
        'mp3' => 'Audio MP3',
        'wav' => 'Audio WAV',
        'ogg' => 'Audio OGG',
        'aac' => 'Audio AAC',
        
        // Documents
        'pdf' => 'Dokumen PDF',
        'doc' => 'Dokumen Word',
        'docx' => 'Dokumen Word',
        
        // Spreadsheets
        'xls' => 'Spreadsheet Excel',
        'xlsx' => 'Spreadsheet Excel',
        
        // Presentations
        'ppt' => 'Presentasi PowerPoint',
        'pptx' => 'Presentasi PowerPoint',
        
        // Text files
        'txt' => 'File Teks',
        'csv' => 'File CSV',
        
        // Archives
        'zip' => 'Arsip ZIP',
        'rar' => 'Arsip RAR',
        '7z' => 'Arsip 7Z'
    ];
    return $fileTypes[$ext] ?? 'File';
}

function getFileIcon($filename) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $iconMap = [
        // Documents
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        
        // Spreadsheets
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        
        // Presentations
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        
        // Archives
        'zip' => 'fas fa-file-archive',
        'rar' => 'fas fa-file-archive',
        '7z' => 'fas fa-file-archive',
        
        // Text files
        'txt' => 'fas fa-file-alt',
        'csv' => 'fas fa-file-csv'
    ];
    
    return $iconMap[$ext] ?? 'fas fa-file';
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function formatMessageTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Baru saja';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    } elseif ($diff < 86400) {
        return date('H:i', $time);
    } else {
        return date('d M H:i', $time);
    }
}

function isUserOnline($lastSeen) {
    if (!$lastSeen) return false;
    $lastSeenTime = strtotime($lastSeen);
    $currentTime = time();
    return ($currentTime - $lastSeenTime) < 300; // 5 minutes
}
?>
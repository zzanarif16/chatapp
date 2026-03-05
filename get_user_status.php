<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get conversation ID from request
$conversationId = $_GET['conversation_id'] ?? '';

if (empty($conversationId)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
    exit;
}

try {
    // Verify user is member of conversation and get other user info
    $stmt = $db->prepare("
        SELECT 
            ou.id as user_id,
            ou.last_seen,
            -- Status online dengan logika yang sama seperti profil_lawan.php (5 menit = 300 detik)
            CASE 
                WHEN TIMESTAMPDIFF(SECOND, ou.last_seen, NOW()) < 300 THEN 'online'
                ELSE 'offline'
            END as online_status
        FROM conversation_members cm
        JOIN users ou ON cm.user_id = ou.id
        WHERE cm.conversation_id = ? AND cm.user_id != ?
    ");
    $stmt->execute([$conversationId, $_SESSION['user_id']]);
    $userStatus = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userStatus) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'user_status' => $userStatus
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
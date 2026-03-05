<?php
session_start();
require_once 'config/koneksi.php';
require_once 'config/helpers.php';

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    try {
        // Delete session from database
        if (isset($_SESSION['token'])) {
            $deleteStmt = $db->prepare("DELETE FROM session WHERE user_id = ? AND token = ?");
            $deleteStmt->execute([$_SESSION['user_id'], $_SESSION['token']]);
        }
        
        // Update last_seen before logging out
        $updateStmt = $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
        $updateStmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // Continue with logout even if database operations fail
    }
}

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: index.php');
exit;
?>
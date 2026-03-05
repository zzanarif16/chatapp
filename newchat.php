<?php
session_start();
require_once 'config/koneksi.php';

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

// Get user's contacts
try {
    $contactsStmt = $db->prepare("
        SELECT c.*, u.name as contact_user_name, u.avatar_url, u.about, u.last_seen
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

// Handle start new chat
if (isset($_GET['start_chat'])) {
    $contactPhone = $_GET['contact_phone'];
    
    try {
        // Get contact user ID
        $contactStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $contactStmt->execute([$contactPhone]);
        $contactUser = $contactStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($contactUser) {
            // Check if conversation already exists
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
                // Redirect to existing conversation
                header("Location: chat.php?conversation_id=" . $existingConv['id']);
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
                
                // Redirect to new conversation
                header("Location: chat.php?conversation_id=" . $conversationId);
                exit;
            }
        } else {
            $error = "Contact is not registered in the system. Please ask them to create an account first.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Chat - Chat App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #0d1117;
            color: #c9d1d9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .github-card {
            background-color: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
        }
        .github-input {
            background-color: #0d1117;
            border: 1px solid #30363d;
            color: #c9d1d9;
        }
        .github-input:focus {
            border-color: #1f6feb;
            box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.3);
        }
        .error-message {
            background-color: #da3633;
            border: 1px solid #f85149;
        }
        .success-message {
            background-color: #238636;
            border: 1px solid #2ea043;
        }
        .contact-item:hover {
            background-color: #1a1f29;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <header class="bg-gray-900 border-b border-gray-700">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="text-blue-400 hover:text-blue-300">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center">
                    <i class="fas fa-comments text-white"></i>
                </div>
                <h1 class="text-xl font-bold text-white">New Chat</h1>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-300"><?php echo htmlspecialchars($user['name']); ?></span>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <?php if (isset($error)): ?>
            <div class="error-message text-white p-3 rounded-md mb-6">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Quick Actions -->
            <!-- <div class="lg:col-span-1">
                <div class="github-card p-6 text-center">
                    <div class="w-16 h-16 rounded-full bg-green-600 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-plus text-white text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-white mb-2">Add New Contact</h3>
                    <p class="text-gray-400 text-sm mb-4">Add someone new to start chatting</p>
                    <a href="addcontact.php" class="w-full bg-green-600 hover:bg-green-700 text-white py-3 px-4 rounded-md transition duration-150 inline-block">
                        <i class="fas fa-plus mr-2"></i>Add Contact
                    </a>
                </div>
            </div> -->

            <!-- Contacts List -->
            <div class="lg:col-span-3 ">
                <div class="github-card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-white">Select Contact to Chat</h2>
                        <span class="text-gray-400 text-sm"><?php echo count($contacts); ?> contacts</span>
                    </div>

                    <div class="space-y-3">
                        <?php if (empty($contacts)): ?>
                            <div class="text-center py-12 text-gray-400">
                                <i class="fas fa-address-book text-5xl mb-4"></i>
                                <p class="text-lg">No contacts yet</p>
                                <p class="text-sm mt-2">Add contacts to start chatting!</p>
                                <a href="addcontact.php" class="inline-block mt-4 bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md transition duration-150">
                                    <i class="fas fa-user-plus mr-2"></i>Add Your First Contact
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <div class="contact-item p-4 rounded-md border border-gray-700 transition duration-150 hover:border-blue-500">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4">
                                            <div class="w-12 h-12 rounded-full bg-green-600 flex items-center justify-center">
                                                <?php if ($contact['avatar_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($contact['avatar_url']); ?>" alt="Avatar" class="w-12 h-12 rounded-full">
                                                <?php else: ?>
                                                    <i class="fas fa-user text-white"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-white"><?php echo htmlspecialchars($contact['contact_name']); ?></h3>
                                                <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($contact['contact_phone']); ?></p>
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <?php if ($contact['contact_user_name']): ?>
                                                        <span class="text-xs bg-green-600 text-white px-2 py-1 rounded">Registered</span>
                                                    <?php else: ?>
                                                        <span class="text-xs bg-yellow-600 text-white px-2 py-1 rounded">Not Registered</span>
                                                    <?php endif; ?>
                                                    <?php if ($contact['last_seen']): ?>
                                                        <span class="text-xs text-green-500">
                                                            <?php 
                                                            $lastSeen = strtotime($contact['last_seen']);
                                                            $now = time();
                                                            if (($now - $lastSeen) < 300) {
                                                                echo '🟢 Online';
                                                            } else {
                                                                echo 'Last seen ' . date('M j, g:i A', $lastSeen);
                                                            }
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <?php if ($contact['contact_user_name']): ?>
                                                <a href="newchat.php?start_chat=1&contact_phone=<?php echo urlencode($contact['contact_phone']); ?>" 
                                                   class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md transition duration-150 flex items-center space-x-2">
                                                    <i class="fas fa-comment"></i>
                                                    <span>Start Chat</span>
                                                </a>
                                            <?php else: ?>
                                                <button class="bg-gray-600 text-gray-400 px-4 py-2 rounded-md cursor-not-allowed flex items-center space-x-2" 
                                                        title="User not registered - ask them to create an account">
                                                    <i class="fas fa-comment"></i>
                                                    <span>Start Chat</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($contact['about']): ?>
                                        <p class="text-gray-400 text-sm mt-3 pl-16"><?php echo htmlspecialchars($contact['about']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Instructions -->
                <div class="github-card p-6 mt-6">
                    <h3 class="font-semibold text-white mb-3">How to start a chat:</h3>
                    <ol class="text-gray-400 text-sm space-y-2 list-decimal list-inside">
                        <li>Select a contact from the list above</li>
                        <li>If the contact is registered, click "Start Chat"</li>
                        <li>If not registered, ask them to create an account first</li>
                        <li>You'll be redirected to the chat interface</li>
                    </ol>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
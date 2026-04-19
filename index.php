<?php
$dbPath = __DIR__ . '/database.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $stmt = $pdo->prepare("INSERT INTO messages (content) VALUES (:content)");
    $stmt->bindValue(':content', htmlspecialchars($_POST['message']), PDO::PARAM_STR);
    $stmt->execute();
    header("Location: /");
    exit;
}

// Fetch messages
$stmt = $pdo->query("SELECT * FROM messages ORDER BY created_at DESC");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP & SQLite Server</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col items-center py-10">
    <div class="max-w-xl w-full bg-white shadow-md rounded-lg p-6">
        <h1 class="text-2xl font-bold mb-4 text-center text-blue-600">PHP & SQLite Demo</h1>
        
        <form method="POST" class="mb-6 flex gap-2">
            <input type="text" name="message" required placeholder="Type a message..." class="flex-1 border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">Send</button>
        </form>

        <div>
            <h2 class="text-xl font-semibold mb-3">Messages:</h2>
            <?php if (count($messages) > 0): ?>
                <ul class="space-y-3">
                    <?php foreach ($messages as $msg): ?>
                        <li class="bg-gray-50 p-3 rounded-lg border border-gray-200 shadow-sm">
                            <p class="text-gray-800"><?php echo htmlspecialchars($msg['content']); ?></p>
                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($msg['created_at']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-500 italic">No messages yet. Be the first to post!</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
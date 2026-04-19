<?php
session_start();
$dbPath = __DIR__ . '/database.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Update table if needed
try {
    $pdo->exec("ALTER TABLE messages ADD COLUMN author TEXT DEFAULT 'Anonymous'");
} catch (Exception $e) {
    // Column already exists, ignore
}

// Create table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author TEXT DEFAULT 'Anonymous',
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = :id");
        $stmt->bindValue(':id', $_POST['id'], PDO::PARAM_INT);
        if ($stmt->execute()) {
            $success = "Message deleted successfully!";
        } else {
            $error = "Failed to delete message.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'post') {
        if (!empty($_POST['message'])) {
            $author = !empty($_POST['author']) ? trim($_POST['author']) : 'Anonymous';
            $content = trim($_POST['message']);
            
            $stmt = $pdo->prepare("INSERT INTO messages (author, content) VALUES (:author, :content)");
            $stmt->bindValue(':author', $author, PDO::PARAM_STR); // Will be escaped in output
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                header("Location: /");
                exit;
            } else {
                $error = "Failed to post message.";
            }
        } else {
            $error = "Message content cannot be empty.";
        }
    }
}

// Fetch messages
$stmt = $pdo->query("SELECT * FROM messages ORDER BY created_at DESC");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function for time ago
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    // The created_at from SQLite is usually UTC, but we assume local here for simplicity unless configured
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Guestbook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 min-h-screen flex flex-col items-center py-10 font-sans">
    <div class="max-w-2xl w-full bg-white shadow-xl rounded-2xl p-8 border border-slate-100">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-extrabold text-indigo-600 tracking-tight flex items-center">
                <i class="fas fa-book-open mr-3"></i> Guestbook
            </h1>
            <span class="bg-indigo-100 text-indigo-800 text-xs font-semibold px-3 py-1 rounded-full">
                <?php echo count($messages); ?> Messages
            </span>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg" role="alert">
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg" role="alert">
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="mb-10 bg-slate-50 p-6 rounded-xl border border-slate-200 shadow-inner">
            <input type="hidden" name="action" value="post">
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Name (Optional)</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-user text-slate-400"></i>
                    </div>
                    <input type="text" name="author" placeholder="John Doe" class="w-full pl-10 border border-slate-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-shadow bg-white">
                </div>
            </div>
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Message <span class="text-red-500">*</span></label>
                <div class="relative">
                    <div class="absolute top-3 left-3 pointer-events-none">
                        <i class="fas fa-comment-alt text-slate-400"></i>
                    </div>
                    <textarea name="message" required rows="3" placeholder="What's on your mind?" class="w-full pl-10 border border-slate-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-shadow resize-none bg-white"></textarea>
                </div>
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white font-bold px-4 py-3 rounded-lg hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200 transition-all flex justify-center items-center gap-2 shadow-md hover:shadow-lg">
                <i class="fas fa-paper-plane"></i> Post Message
            </button>
        </form>

        <div>
            <h2 class="text-xl font-bold text-slate-800 mb-5 border-b pb-3"><i class="fas fa-history mr-2 text-slate-400"></i>Recent Posts</h2>
            <?php if (count($messages) > 0): ?>
                <div class="space-y-5">
                    <?php foreach ($messages as $msg): ?>
                        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-all duration-200 relative group">
                            <form method="POST" class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity" onsubmit="return confirm('Are you sure you want to delete this message?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $msg['id']; ?>">
                                <button type="submit" class="text-red-400 hover:text-red-600 p-2 bg-red-50 hover:bg-red-100 rounded-lg transition-colors" title="Delete message">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                            <div class="flex items-center gap-4 mb-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-100 to-indigo-200 text-indigo-700 flex items-center justify-center font-bold text-xl shadow-inner border border-indigo-100">
                                    <?php echo strtoupper(substr($msg['author'] ?? 'A', 0, 1)); ?>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-800"><?php echo htmlspecialchars($msg['author'] ?? 'Anonymous'); ?></h3>
                                    <p class="text-xs text-slate-500 font-medium" title="<?php echo htmlspecialchars($msg['created_at']); ?>">
                                        <i class="far fa-clock mr-1"></i> <?php echo time_elapsed_string($msg['created_at']); ?>
                                    </p>
                                </div>
                            </div>
                            <p class="text-slate-700 mt-2 whitespace-pre-wrap leading-relaxed bg-slate-50 p-4 rounded-lg border border-slate-100"><?php echo htmlspecialchars($msg['content']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
                    <i class="fas fa-inbox text-5xl text-slate-300 mb-4 block"></i>
                    <h3 class="text-lg font-semibold text-slate-700 mb-1">It's pretty quiet here</h3>
                    <p class="text-slate-500">Be the first to leave a message in the guestbook!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
/**
 * Telegram Group Management Bot - Installation Script
 * Run this script once to set up the bot files and database.
 */

// Check if the script has been run already (optional lock file)
if (file_exists(__DIR__ . '/.installed')) {
    die('Bot already installed. Delete .installed to reinstall.');
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bot_token = trim($_POST['bot_token']);
    $db_host = trim($_POST['db_host']);
    $db_name = trim($_POST['db_name']);
    $db_user = trim($_POST['db_user']);
    $db_pass = trim($_POST['db_pass']);
    $super_admins = array_map('trim', explode(',', $_POST['super_admins']));
    $super_admins = array_filter($super_admins, 'is_numeric');

    $errors = [];
    if (empty($bot_token)) $errors[] = 'Bot token is required.';
    if (empty($db_host)) $errors[] = 'Database host is required.';
    if (empty($db_name)) $errors[] = 'Database name is required.';
    if (empty($db_user)) $errors[] = 'Database user is required.';
    if (empty($super_admins)) $errors[] = 'At least one super admin ID is required.';

    if (empty($errors)) {
        // Test database connection
        try {
            $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
            $pdo->exec("USE `$db_name`");

            // Create tables
            $sql = "
            CREATE TABLE IF NOT EXISTS users (
                user_id BIGINT PRIMARY KEY,
                first_name VARCHAR(255),
                username VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS group_settings (
                group_id BIGINT PRIMARY KEY,
                flood_threshold INT DEFAULT 5,
                flood_time INT DEFAULT 5,
                warn_limit INT DEFAULT 3,
                mute_duration INT DEFAULT 3600,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS warns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                admin_id BIGINT NOT NULL,
                reason TEXT,
                warned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id, group_id)
            );

            CREATE TABLE IF NOT EXISTS mutes (
                user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                until_date TIMESTAMP NOT NULL,
                PRIMARY KEY (user_id, group_id),
                INDEX (until_date)
            );

            CREATE TABLE IF NOT EXISTS reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reporter_id BIGINT NOT NULL,
                reported_user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                message_id INT,
                reason TEXT,
                status ENUM('pending', 'resolved', 'dismissed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS flood_violations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                violation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            ";
            $pdo->exec($sql);

            // Generate config.php
            $config_content = <<<PHP
<?php
// Bot configuration
define('BOT_TOKEN', '$bot_token');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Database credentials
define('DB_HOST', '$db_host');
define('DB_NAME', '$db_name');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');

// Super admins (user IDs that can manage everything)
\$super_admins = [" . implode(', ', $super_admins) . "];

// Connect to database
try {
    \$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException \$e) {
    die("Database connection failed: " . \$e->getMessage());
}
?>
PHP;
            file_put_contents(__DIR__ . '/config.php', $config_content);

            // Generate functions.php (copy from provided code, but we need to ensure it uses $pdo from config)
            $functions_content = file_get_contents(__DIR__ . '/functions_stub.php'); // We'll embed the full code below
            // Actually we'll include the full code directly as heredoc.
            // To avoid duplication, we'll store the code as strings in this installer.

            // But for brevity, we'll embed the previously provided code with minor adjustments (remove config duplication).
            // We'll create a separate file for each.

            // Write functions.php
            $functions_content = <<<'FUNC'
<?php
require_once 'config.php';

/**
 * Send a message to a chat
 */
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ];
    if ($reply_markup) {
        $params['reply_markup'] = $reply_markup;
    }
    return callAPI('sendMessage', $params);
}

/**
 * Call Telegram API method
 */
function callAPI($method, $params) {
    $url = API_URL . $method;
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($params)
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true);
}

/**
 * Restrict chat member (mute/unmute)
 */
function restrictChatMember($chat_id, $user_id, $until_date = null, $can_send_messages = false) {
    $params = [
        'chat_id' => $chat_id,
        'user_id' => $user_id,
        'permissions' => json_encode([
            'can_send_messages' => $can_send_messages,
            'can_send_media_messages' => $can_send_messages,
            'can_send_polls' => $can_send_messages,
            'can_send_other_messages' => $can_send_messages,
            'can_add_web_page_previews' => $can_send_messages
        ])
    ];
    if ($until_date) {
        $params['until_date'] = $until_date;
    }
    return callAPI('restrictChatMember', $params);
}

/**
 * Delete a message
 */
function deleteMessage($chat_id, $message_id) {
    return callAPI('deleteMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
}

/**
 * Check if user is admin in the group
 */
function isAdmin($chat_id, $user_id, $pdo) {
    global $super_admins;
    if (in_array($user_id, $super_admins)) return true;

    $result = callAPI('getChatMember', [
        'chat_id' => $chat_id,
        'user_id' => $user_id
    ]);
    if (isset($result['ok']) && $result['ok']) {
        $status = $result['result']['status'];
        return in_array($status, ['creator', 'administrator']);
    }
    return false;
}

/**
 * Get group settings
 */
function getGroupSettings($group_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM group_settings WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        $stmt = $pdo->prepare("INSERT INTO group_settings (group_id) VALUES (?)");
        $stmt->execute([$group_id]);
        return [
            'flood_threshold' => 5,
            'flood_time' => 5,
            'warn_limit' => 3,
            'mute_duration' => 3600
        ];
    }
    return $settings;
}

/**
 * Flood protection check (stub)
 */
function checkFlood($user_id, $group_id, $pdo) {
    // Implement flood detection logic here
    return false;
}

/**
 * Anti-spam: check for repeated messages, links, etc.
 */
function isSpam($text, $user_id, $group_id, $pdo) {
    if (preg_match('/https?:\/\/[^\s]+/', $text)) {
        return true;
    }
    return false;
}

/**
 * Add warn for a user
 */
function addWarn($group_id, $user_id, $admin_id, $reason, $pdo) {
    $stmt = $pdo->prepare("INSERT INTO warns (user_id, group_id, admin_id, reason) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $group_id, $admin_id, $reason]);
    return $pdo->lastInsertId();
}

/**
 * Get warn count for a user in a group
 */
function getWarnCount($user_id, $group_id, $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM warns WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$user_id, $group_id]);
    return $stmt->fetchColumn();
}

/**
 * Clear warns for a user
 */
function clearWarns($user_id, $group_id, $pdo) {
    $stmt = $pdo->prepare("DELETE FROM warns WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$user_id, $group_id]);
}

/**
 * Mute a user
 */
function muteUser($group_id, $user_id, $duration, $pdo) {
    $until = time() + $duration;
    $until_date = date('Y-m-d H:i:s', $until);
    $stmt = $pdo->prepare("REPLACE INTO mutes (user_id, group_id, until_date) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $group_id, $until_date]);
    restrictChatMember($group_id, $user_id, $until, false);
    return $until;
}

/**
 * Unmute a user
 */
function unmuteUser($group_id, $user_id, $pdo) {
    $stmt = $pdo->prepare("DELETE FROM mutes WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$user_id, $group_id]);
    restrictChatMember($group_id, $user_id, null, true);
}

/**
 * Check if user is muted
 */
function isMuted($user_id, $group_id, $pdo) {
    $stmt = $pdo->prepare("SELECT until_date FROM mutes WHERE user_id = ? AND group_id = ? AND until_date > NOW()");
    $stmt->execute([$user_id, $group_id]);
    return $stmt->fetch() !== false;
}
?>
FUNC;
            file_put_contents(__DIR__ . '/functions.php', $functions_content);

            // Write commands.php
            $commands_content = <<<'CMD'
<?php
require_once 'functions.php';

function handleCommand($message, $pdo) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = trim($message['text']);
    $command = explode(' ', $text)[0];

    if ($message['chat']['type'] != 'group' && $message['chat']['type'] != 'supergroup') {
        sendMessage($chat_id, "This bot works only in groups.");
        return;
    }

    $is_admin = isAdmin($chat_id, $user_id, $pdo);

    switch ($command) {
        case '/start':
        case '/help':
            $help_text = "🤖 *Group Management Bot*\n\n"
                . "Available commands:\n"
                . "/warn @user [reason] - Warn a user\n"
                . "/mute @user [duration] - Mute a user (duration in minutes, default 60)\n"
                . "/unmute @user - Unmute a user\n"
                . "/report @user [reason] - Report a user\n"
                . "/warns @user - Show warns for a user\n"
                . "/delwarns @user - Clear all warns\n"
                . "/setwarnlimit [number] - Set warn limit\n"
                . "/setmuteduration [minutes] - Set default mute duration\n"
                . "/flood [threshold time] - Set flood settings";
            sendMessage($chat_id, $help_text);
            break;

        case '/warn':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ You need to be an admin to use this command.");
                break;
            }
            $parts = explode(' ', $text, 3);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /warn @username [reason]");
                break;
            }
            $target = $parts[1];
            $reason = $parts[2] ?? 'No reason provided';
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "⚠️ Please use the user's ID or reply to their message.");
                break;
            } else {
                $target_user_id = (int) $target;
                if ($target_user_id <= 0) {
                    sendMessage($chat_id, "Invalid user. Please mention or provide ID.");
                    break;
                }
                addWarn($chat_id, $target_user_id, $user_id, $reason, $pdo);
                $warn_count = getWarnCount($target_user_id, $chat_id, $pdo);
                $settings = getGroupSettings($chat_id, $pdo);
                $limit = $settings['warn_limit'];
                sendMessage($chat_id, "⚠️ User <a href='tg://user?id=$target_user_id'>has been warned</a> ($warn_count/$limit).\nReason: $reason");
                if ($warn_count >= $limit) {
                    muteUser($chat_id, $target_user_id, $settings['mute_duration'], $pdo);
                    sendMessage($chat_id, "🚫 User has reached warn limit and has been muted.");
                }
            }
            break;

        case '/mute':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ You need to be an admin to use this command.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /mute @username [minutes]");
                break;
            }
            $target = $parts[1];
            $duration = isset($parts[2]) ? (int) $parts[2] : 60;
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "Please provide user ID or reply to message.");
                break;
            }
            $target_user_id = (int) $target;
            if ($target_user_id <= 0) {
                sendMessage($chat_id, "Invalid user.");
                break;
            }
            muteUser($chat_id, $target_user_id, $duration * 60, $pdo);
            sendMessage($chat_id, "🔇 User muted for $duration minutes.");
            break;

        case '/unmute':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ You need to be an admin to use this command.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /unmute @username");
                break;
            }
            $target = $parts[1];
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "Please provide user ID.");
                break;
            }
            $target_user_id = (int) $target;
            unmuteUser($chat_id, $target_user_id, $pdo);
            sendMessage($chat_id, "🔊 User unmuted.");
            break;

        case '/report':
            $parts = explode(' ', $text, 3);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /report @username [reason]");
                break;
            }
            $target = $parts[1];
            $reason = $parts[2] ?? 'No reason';
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "Please provide user ID.");
                break;
            }
            $reported_user_id = (int) $target;
            $stmt = $pdo->prepare("INSERT INTO reports (reporter_id, reported_user_id, group_id, reason) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $reported_user_id, $chat_id, $reason]);
            sendMessage($chat_id, "📝 Report submitted. Admins will review.");
            break;

        case '/warns':
            $parts = explode(' ', $text);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /warns @username");
                break;
            }
            $target = $parts[1];
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "Please provide user ID.");
                break;
            }
            $target_user_id = (int) $target;
            $warn_count = getWarnCount($target_user_id, $chat_id, $pdo);
            sendMessage($chat_id, "User has $warn_count warns.");
            break;

        case '/delwarns':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ Admin only.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 2) {
                sendMessage($chat_id, "Usage: /delwarns @username");
                break;
            }
            $target = $parts[1];
            preg_match('/@(\w+)/', $target, $matches);
            if (isset($matches[1])) {
                sendMessage($chat_id, "Please provide user ID.");
                break;
            }
            $target_user_id = (int) $target;
            clearWarns($target_user_id, $chat_id, $pdo);
            sendMessage($chat_id, "Warns cleared.");
            break;

        case '/setwarnlimit':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ Admin only.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 2 || !is_numeric($parts[1])) {
                sendMessage($chat_id, "Usage: /setwarnlimit [number]");
                break;
            }
            $limit = (int) $parts[1];
            $stmt = $pdo->prepare("UPDATE group_settings SET warn_limit = ? WHERE group_id = ?");
            $stmt->execute([$limit, $chat_id]);
            sendMessage($chat_id, "Warn limit set to $limit.");
            break;

        case '/setmuteduration':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ Admin only.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 2 || !is_numeric($parts[1])) {
                sendMessage($chat_id, "Usage: /setmuteduration [minutes]");
                break;
            }
            $duration = (int) $parts[1] * 60;
            $stmt = $pdo->prepare("UPDATE group_settings SET mute_duration = ? WHERE group_id = ?");
            $stmt->execute([$duration, $chat_id]);
            sendMessage($chat_id, "Mute duration set to {$parts[1]} minutes.");
            break;

        case '/flood':
            if (!$is_admin) {
                sendMessage($chat_id, "⛔ Admin only.");
                break;
            }
            $parts = explode(' ', $text);
            if (count($parts) < 3 || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
                sendMessage($chat_id, "Usage: /flood [threshold] [time_window_seconds]");
                break;
            }
            $threshold = (int) $parts[1];
            $time = (int) $parts[2];
            $stmt = $pdo->prepare("UPDATE group_settings SET flood_threshold = ?, flood_time = ? WHERE group_id = ?");
            $stmt->execute([$threshold, $time, $chat_id]);
            sendMessage($chat_id, "Flood settings updated: $threshold messages in $time seconds.");
            break;

        default:
            if (checkFlood($user_id, $chat_id, $pdo)) {
                deleteMessage($chat_id, $message['message_id']);
                sendMessage($chat_id, "🚫 Flood detected! You have been warned.");
                addWarn($chat_id, $user_id, 0, "Flood", $pdo);
                $warn_count = getWarnCount($user_id, $chat_id, $pdo);
                $settings = getGroupSettings($chat_id, $pdo);
                if ($warn_count >= $settings['warn_limit']) {
                    muteUser($chat_id, $user_id, $settings['mute_duration'], $pdo);
                }
            }
            if (isSpam($text, $user_id, $chat_id, $pdo)) {
                deleteMessage($chat_id, $message['message_id']);
                sendMessage($chat_id, "🚫 Spam detected! Message deleted.");
            }
            break;
    }
}
?>
CMD;
            file_put_contents(__DIR__ . '/commands.php', $commands_content);

            // Write index.php (webhook handler)
            $index_content = <<<'INDEX'
<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'commands.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    if (isset($message['text'])) {
        handleCommand($message, $pdo);
    }
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    // Handle callbacks (e.g., report approval)
}

http_response_code(200);
?>
INDEX;
            file_put_contents(__DIR__ . '/index.php', $index_content);

            // Create admin_panel directory
            if (!is_dir(__DIR__ . '/admin_panel')) {
                mkdir(__DIR__ . '/admin_panel');
            }

            // Write admin_panel/index.php
            $admin_index_content = <<<'ADMIN'
<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_id'], $super_admins)) {
    die("Access denied.");
}

$reports = $pdo->query("SELECT * FROM reports ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$warns = $pdo->query("SELECT * FROM warns ORDER BY warned_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$mutes = $pdo->query("SELECT * FROM mutes WHERE until_date > NOW()")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram Bot Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding-top: 2rem; }
        .table-responsive { margin-top: 2rem; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="mb-4">🤖 Group Management Bot - Admin Panel</h1>

    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab">Reports</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="warns-tab" data-bs-toggle="tab" data-bs-target="#warns" type="button" role="tab">Warns</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="mutes-tab" data-bs-toggle="tab" data-bs-target="#mutes" type="button" role="tab">Active Mutes</button>
        </li>
    </ul>

    <div class="tab-content mt-3">
        <div class="tab-pane fade show active" id="reports" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr><th>ID</th><th>Reporter</th><th>Reported User</th><th>Group</th><th>Reason</th><th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            <td><?= $r['reporter_id'] ?></td>
                            <td><?= $r['reported_user_id'] ?></td>
                            <td><?= $r['group_id'] ?></td>
                            <td><?= htmlspecialchars($r['reason']) ?></td>
                            <td><?= $r['status'] ?></td>
                            <td><?= $r['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="warns" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Group</th><th>Admin</th><th>Reason</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($warns as $w): ?>
                        <tr>
                            <td><?= $w['id'] ?></td>
                            <td><?= $w['user_id'] ?></td>
                            <td><?= $w['group_id'] ?></td>
                            <td><?= $w['admin_id'] ?></td>
                            <td><?= htmlspecialchars($w['reason']) ?></td>
                            <td><?= $w['warned_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="mutes" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr><th>User ID</th><th>Group ID</th><th>Until Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mutes as $m): ?>
                        <tr>
                            <td><?= $m['user_id'] ?></td>
                            <td><?= $m['group_id'] ?></td>
                            <td><?= $m['until_date'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
ADMIN;
            file_put_contents(__DIR__ . '/admin_panel/index.php', $admin_index_content);

            // Optionally create a .htaccess to protect admin_panel
            $htaccess_content = <<<HTA
AuthType Basic
AuthName "Admin Panel"
AuthUserFile /dev/null
Require valid-user
HTA;
            file_put_contents(__DIR__ . '/admin_panel/.htaccess', $htaccess_content);

            // Create a lock file to prevent re-installation
            file_put_contents(__DIR__ . '/.installed', 'Installed on ' . date('Y-m-d H:i:s'));

            echo "<div class='alert alert-success'>Installation completed successfully!<br>Files created: config.php, functions.php, commands.php, index.php, admin_panel/</div>";
            echo "<p>Next steps: <br>1. Set the webhook: <code>https://api.telegram.org/bot$bot_token/setWebhook?url=https://yourdomain.com/path/to/index.php</code><br>2. Add the bot to your group as an administrator.<br>3. Secure the admin panel by editing <code>admin_panel/.htaccess</code> or implement proper login.</p>";
            exit;
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Telegram Bot Installer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Telegram Group Management Bot - Setup</div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $err): echo "<p>$err</p>"; endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label>Bot Token</label>
                            <input type="text" name="bot_token" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Database Host</label>
                            <input type="text" name="db_host" class="form-control" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label>Database Name</label>
                            <input type="text" name="db_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Database User</label>
                            <input type="text" name="db_user" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Database Password</label>
                            <input type="password" name="db_pass" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label>Super Admin IDs (comma separated)</label>
                            <input type="text" name="super_admins" class="form-control" placeholder="123456789,987654321" required>
                            <small class="text-muted">Your Telegram user ID(s). You can get it from @userinfobot.</small>
                        </div>
                        <button type="submit" class="btn btn-primary">Install</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

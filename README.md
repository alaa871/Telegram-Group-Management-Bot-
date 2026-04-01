# 🤖 Telegram Group Management Bot

A powerful and responsive Telegram bot written in PHP to manage groups with flood protection, anti‑spam, reporting, warnings, and muting. Includes an admin panel for easy oversight.

## ✨ Features

- **Flood Protection** – Limit how many messages a user can send in a given time window (stub – ready to extend).
- **Anti‑Spam** – Automatically deletes messages containing links or repeated text.
- **Warn System** – Admins can warn users; after a configurable limit, the user is automatically muted.
- **Mute / Unmute** – Temporarily restrict a user from sending messages.
- **Report System** – Users can report others; reports are stored and can be reviewed by admins.
- **Admin Panel** – Responsive web interface to view reports, warns, and active mutes.
- **Configurable** – Group‑specific settings (warn limit, mute duration, flood parameters) can be changed via commands.

## 📋 Requirements

- PHP 7.4 or higher with `PDO` and `MySQLi` extensions.
- MySQL database.
- A Telegram bot token (from [@BotFather](https://t.me/botfather)).
- Web server with HTTPS (recommended for webhooks).
- cURL or `file_get_contents` enabled for API calls.

## 🚀 Installation

### 1. Upload the Installer

Place the `install.php` script on your server (e.g., `https://yourdomain.com/bot/install.php`).

### 2. Run the Installer

Open the URL in your browser. Fill in:

- **Bot Token** – from BotFather.
- **Database Host** – usually `localhost`.
- **Database Name** – choose a name (will be created automatically).
- **Database User / Password** – credentials with create‑database permission.
- **Super Admin IDs** – your Telegram user ID(s) (comma‑separated). Find your ID via [@userinfobot](https://t.me/userinfobot).

Click **Install**. The script will:

- Create the database and tables.
- Generate all bot files (`config.php`, `functions.php`, `commands.php`, `index.php`, and the admin panel).
- Create a lock file (`.installed`) to prevent re‑installation.

### 3. Set the Webhook

After installation, set your bot’s webhook URL:

```

https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/bot/index.php

```

Replace `<YOUR_BOT_TOKEN>` with your actual token and the URL with the correct path to `index.php`.

### 4. Add the Bot to Your Group

- Add the bot as a member.
- **Make it an administrator** with the following permissions (required for the bot to work):
  - Delete messages
  - Restrict members
  - Ban users
  - Send messages

### 5. Secure the Admin Panel

The admin panel is located at `https://yourdomain.com/bot/admin_panel/`. By default, it uses a simple session check. **You must implement proper authentication** (e.g., using Telegram Login Widget or a password) before exposing it to the internet. A basic `.htaccess` file is created – you can modify it for HTTP authentication.

## 📖 Bot Commands

All commands must be used in a group.

| Command | Description | Admin Only |
|---------|-------------|------------|
| `/help` or `/start` | Shows available commands. | ❌ |
| `/warn @user [reason]` | Warns a user (provide user ID or reply). | ✅ |
| `/mute @user [minutes]` | Mutes a user for a given duration (default 60 min). | ✅ |
| `/unmute @user` | Unmutes a user. | ✅ |
| `/report @user [reason]` | Reports a user to admins. | ❌ |
| `/warns @user` | Displays how many warns a user has. | ❌ |
| `/delwarns @user` | Clears all warns for a user. | ✅ |
| `/setwarnlimit [number]` | Sets the number of warns before auto‑mute. | ✅ |
| `/setmuteduration [minutes]` | Sets the default mute duration in minutes. | ✅ |
| `/flood [threshold] [seconds]` | Sets flood protection: max messages per time window. | ✅ |

> **Note:** To use `@user` mentions, the bot needs to resolve the username to a user ID. In this version, you must provide the numeric user ID (you can get it from the admin panel or by replying to a message – this can be extended).

## 🖥️ Admin Panel

The admin panel provides an overview of:

- **Reports** – all submitted reports with status.
- **Warns** – history of warnings issued.
- **Active Mutes** – currently muted users.

Access it at `https://yourdomain.com/bot/admin_panel/`.  
**Important:** Add authentication before use.

## 🛠️ Extending the Bot

### Flood Protection

The function `checkFlood()` in `functions.php` is a stub. To enable it, implement a method to count messages per user in a time window. You can use:

- A database table `user_messages` with `user_id`, `group_id`, and `timestamp`.
- A cache like Redis or Memcached.

### Spam Detection

The `isSpam()` function currently detects URLs. You can extend it to check for repeated messages, banned words, or other patterns.

### Resolving Usernames to IDs

For a better user experience, modify commands to accept replies or resolve mentions using the `getChatMember` API call.

## 🔒 Security Considerations

- **Never commit** `config.php` to version control. It contains your bot token and database credentials.
- Place the bot files outside the web root if possible, or use `.htaccess` to deny direct access to sensitive files.
- Use HTTPS for the webhook to prevent token interception.
- Implement strong authentication for the admin panel.

---

## 📞 Support

For issues or suggestions, please open an issue on the project repository.

Happy moderating! 🎉

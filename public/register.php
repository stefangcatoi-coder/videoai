<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/register.php

require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email-ul este deja înregistrat.";
        } else {
            // Hash password and insert using password_hash column
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
            if ($stmt->execute([$email, $hashedPassword])) {
                header("Location: login.php");
                exit;
            } else {
                $error = "A apărut o eroare la înregistrare.";
            }
        }
    } else {
        $error = "Vă rugăm să completați toate câmpurile.";
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Înregistrare - Video AI</title>
    <style>
        body {
            background-color: #121212;
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #1e1e1e;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #ffffff;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        input {
            width: 100%;
            padding: 0.75rem;
            border-radius: 4px;
            border: 1px solid #333;
            background-color: #2c2c2c;
            color: #fff;
            box-sizing: border-box;
        }
        input:focus {
            outline: none;
            border-color: #bb86fc;
        }
        button {
            width: 100%;
            padding: 0.75rem;
            border: none;
            border-radius: 4px;
            background-color: #bb86fc;
            color: #121212;
            font-weight: bold;
            cursor: pointer;
            margin-top: 1rem;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #9965f4;
        }
        .error {
            color: #cf6679;
            background-color: rgba(207, 102, 121, 0.1);
            padding: 0.5rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            text-align: center;
        }
        .link {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.85rem;
        }
        .link a {
            color: #bb86fc;
            text-decoration: none;
        }
        .link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Înregistrare</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" required>
            </div>
            <div class="form-group">
                <label for="password">Parolă</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit">Creează cont</button>
        </form>
        <div class="link">
            Ai deja un cont? <a href="login.php">Autentifică-te</a>
        </div>
    </div>
</body>
</html>

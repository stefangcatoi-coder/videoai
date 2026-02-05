<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Security Middleware: Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT email, plan, monthly_limit, videos_used FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Fetch videos for this user
$stmt = $pdo->prepare("SELECT * FROM videos WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$videos = $stmt->fetchAll();

$limit_reached = ($user['videos_used'] >= $user['monthly_limit']);
$progress_percent = ($user['monthly_limit'] > 0) ? ($user['videos_used'] / $user['monthly_limit']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Video AI</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .btn-create { display: inline-block; padding: 0.75rem 1.5rem; border-radius: 4px; text-decoration: none; font-weight: bold; }
        .btn-green { background-color: #03dac6; color: #121212; }
        .card { background-color: #1e1e1e; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 1rem; border-bottom: 1px solid #333; }
        th { color: #bb86fc; text-transform: uppercase; font-size: 0.8rem; }
        .status-badge { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
        .status-draft { background-color: rgba(224, 224, 224, 0.2); color: #e0e0e0; border: 1px solid #e0e0e0; }
        .status-processing { background-color: rgba(255, 152, 0, 0.2); color: #ff9800; border: 1px solid #ff9800; }
        .status-assets { background-color: rgba(187, 134, 252, 0.2); color: #bb86fc; border: 1px solid #bb86fc; }
        .status-ready { background-color: rgba(3, 218, 198, 0.2); color: #03dac6; border: 1px solid #03dac6; }
        .ai-working { display: block; font-size: 0.7rem; margin-top: 4px; font-style: italic; opacity: 0.8; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div class="main-content">
        <?php if (isset($_GET['success'])): ?>
            <div style="background-color: rgba(3, 218, 198, 0.1); color: #03dac6; padding: 1rem; border-radius: 4px; margin-bottom: 2rem; border: 1px solid #03dac6; text-align: center;">
                <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <div class="header">
            <h1>Dashboard</h1>
            <div class="user-info">
                <strong><?php echo htmlspecialchars($user['email']); ?></strong> (<?php echo htmlspecialchars($user['plan']); ?>)
            </div>
        </div>

        <div class="card">
            <h3>Limită lunară</h3>
            <p><?php echo $user['videos_used']; ?> / <?php echo $user['monthly_limit']; ?> video-uri utilizate</p>
            <a href="generate.php" class="btn-create btn-green">+ Video Nou</a>
        </div>

        <div class="card">
            <h3>Video-urile tale</h3>
            <table>
                <thead>
                    <tr><th>Titlu</th><th>Creat la</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($videos as $v): ?>
                        <tr>
                            <td>
                                <?php if ($v['status'] === 'draft'): ?>
                                    <a href="edit_draft.php?id=<?php echo $v['id']; ?>" style="color: #bb86fc; text-decoration: none;"><strong><?php echo htmlspecialchars($v['title']); ?></strong></a>
                                <?php elseif ($v['status'] === 'ready_for_render'): ?>
                                    <a href="view.php?id=<?php echo $v['id']; ?>" style="color: #03dac6; text-decoration: none;"><strong><?php echo htmlspecialchars($v['title']); ?></strong></a>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars($v['title']); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($v['created_at'])); ?></td>
                            <td>
                                <?php if ($v['status'] === 'generating_assets'): ?>
                                    <span class="status-badge status-assets">Imagini AI</span>
                                    <span class="ai-working">Descărcăm imagini...</span>
                                <?php elseif ($v['status'] === 'draft'): ?>
                                    <span class="status-badge status-draft">Draft</span>
                                    <span class="ai-working">Așteaptă editare</span>
                                <?php elseif ($v['status'] === 'processing'): ?>
                                    <span class="status-badge status-processing">Procesare</span>
                                    <span class="ai-working">Generăm voce și video...</span>
                                <?php elseif ($v['status'] === 'ready_for_render'): ?>
                                    <span class="status-badge status-ready">Finalizat</span>
                                    <span class="ai-working">Gata de vizionat</span>
                                <?php else: ?>
                                    <span class="status-badge"><?php echo htmlspecialchars($v['status']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

<?php
// views/header.php
?>
<style>
    .sidebar {
        width: 250px;
        background-color: #1e1e1e;
        height: 100vh;
        position: fixed;
        padding: 2rem 1rem;
        box-shadow: 2px 0 5px rgba(0,0,0,0.5);
    }

    .sidebar h2 {
        color: #bb86fc;
        margin-bottom: 2rem;
        text-align: center;
    }

    .nav-link {
        display: block;
        padding: 0.75rem 1rem;
        color: #e0e0e0;
        text-decoration: none;
        border-radius: 4px;
        margin-bottom: 0.5rem;
        transition: background 0.3s;
    }

    .nav-link:hover, .nav-link.active {
        background-color: #2c2c2c;
        color: #bb86fc;
    }

    .logout-link {
        color: #cf6679;
        margin-top: 2rem;
    }
</style>

<div class="sidebar">
    <h2>Video AI</h2>
    <a href="dashboard.php" class="nav-link">Dashboard</a>
    <a href="generate.php" class="nav-link">Generare</a>
    <a href="profile.php" class="nav-link">Profil</a>
    <a href="logout.php" class="nav-link logout-link">Logout</a>
</div>

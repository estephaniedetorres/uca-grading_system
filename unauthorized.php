<?php
require_once 'config/session.php';
$pageTitle = 'Unauthorized';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="text-center">
        <h1 class="text-6xl font-bold text-gray-300">403</h1>
        <p class="text-2xl text-gray-600 mt-4">Unauthorized Access</p>
        <p class="text-gray-500 mt-2">You don't have permission to access this page.</p>
        <a href="/login.php" class="inline-block mt-6 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Return to Login
        </a>
    </div>
</body>
</html>

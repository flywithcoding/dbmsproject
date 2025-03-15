<?php
session_start();

// Dummy credentials (Replace with database check)
$valid_user = "user123";
$valid_password = "pass123";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_POST['user_id'];
    $password = $_POST['password'];

    if ($user_id === $valid_user && $password === $valid_password) {
        $_SESSION['user'] = $user_id;
        header("Location: dashboard.php"); // Redirect to dashboard
        exit();
    } else {
        echo "<script>alert('Invalid User ID or Password'); window.location.href='index.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Banking Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Animations */
        .fade-in { opacity: 0; transform: translateY(20px); animation: fadeIn 0.5s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .logo-animation { animation: zoomInOut 3s infinite ease-in-out; }
        @keyframes zoomInOut { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .slide-in { opacity: 0; transform: translateX(-50px); animation: slideIn 0.1s ease-in-out forwards; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-50px); } to { opacity: 1; transform: translateX(0); } }
        .marquee-container { overflow: hidden; white-space: nowrap; background-color:rgb(255, 255, 255); padding: 10px; width: 100%; position: relative; }
        .marquee-text { display: inline-block; animation: marquee 25s linear infinite; font-size: 16px; font-weight: bold; color: #333; }
        @keyframes marquee { from { transform: translateX(100%); } to { transform: translateX(-100%); } }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-100 bg-orange-400">
    <div class="w-full max-w-sm p-6 bg-white rounded-lg shadow-md border border-gray-200">
       <!-- Ubank Logo with Zoom Animation -->
       <div class="flex justify-center">
            <img src="./img/update logo.png" alt="Ubank Logo" class="w-32 h-32 logo-animation">
        </div>
        <h2 class="text-xl font-semibold text-center text-blue-600">Online Banking Login</h2>
        <div class="marquee-container">
            <span class="marquee-text">UBank is Bangladesh's most innovative and technologically advanced bank. UBank stands to give the most innovative and affordable banking products to Bangladesh.</span>
        </div>
        <form action="" method="POST" class="mt-4">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">User ID</label>
                <div class="relative">
                    <input type="text" name="user_id" placeholder="Enter User ID" required class="w-full px-4 py-2 mt-2 border rounded-md bg-yellow-100 focus:ring focus:ring-blue-300">
                    <span class="absolute inset-y-0 right-3 flex items-center">
                        🔒
                    </span>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <div class="relative">
                    <input type="password" name="password" required class="w-full px-4 py-2 mt-2 border rounded-md bg-yellow-100 focus:ring focus:ring-blue-300">
                    <span class="absolute inset-y-0 right-3 flex items-center">
                        🔒
                    </span>
                </div>
            </div>
            <button type="submit" class="w-full px-4 py-2 font-medium text-white bg-red-500 rounded-md hover:bg-red-600 flex items-center justify-center">
                Login
            </button>
            <div class="text-center mt-4 text-sm">
                <a href="#" class="text-blue-500 hover:underline">Forgot User ID</a> |
                <a href="#" class="text-blue-500 hover:underline">Forgot Password</a>
            </div>
            <div class="text-center mt-4 text-sm text-gray-600">
                Don't have a bank account? <a href="reg_from.php" class="text-blue-600 font-semibold hover:underline">Open now</a>
            </div>
        </form>
    </div>
</body>
</html>

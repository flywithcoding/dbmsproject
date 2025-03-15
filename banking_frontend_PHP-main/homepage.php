<?php
session_start();
include 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UBank Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .active {
            background-color: #3B82F6;
            color: white;
        }
    </style>
</head>
<body class="bg-gray-100 flex">
    <!-- Sidebar -->
    <div class="w-64 min-h-screen bg-white shadow-lg p-4">
        <h2 class="text-2xl font-bold text-blue-600">UBank Dashboard</h2>
        <ul class="mt-6 space-y-4">
            <li><a href="dashboard.php" class="block p-2 rounded hover:bg-blue-500 hover:text-white">Dashboard</a></li>
            <li><a href="account_overview.php" class="block p-2 rounded hover:bg-blue-500 hover:text-white">Account Overview</a></li>
            <li><a href="transactions.php" class="block p-2 rounded hover:bg-blue-500 hover:text-white">Transactions</a></li>
            <li><a href="payments.php" class="block p-2 rounded hover:bg-blue-500 hover:text-white">Payments</a></li>
            <li><a href="settings.php" class="block p-2 rounded hover:bg-blue-500 hover:text-white">Settings</a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="flex-1 p-8">
        <h1 class="text-3xl font-bold">Welcome to UBank</h1>
        
        <div class="grid grid-cols-4 gap-4 mt-6">
            <div class="bg-blue-500 p-4 text-white rounded shadow">
                <h3 class="text-xl">Total Balance</h3>
                <p class="text-2xl font-bold">$5000</p>
            </div>
            <div class="bg-yellow-400 p-4 text-white rounded shadow">
                <h3 class="text-xl">Recent Transactions</h3>
                <p class="text-2xl font-bold">10</p>
            </div>
            <div class="bg-green-500 p-4 text-white rounded shadow">
                <h3 class="text-xl">Payments This Month</h3>
                <p class="text-2xl font-bold">$1200</p>
            </div>
            <div class="bg-gray-500 p-4 text-white rounded shadow">
                <h3 class="text-xl">Total Users</h3>
                <p class="text-2xl font-bold">150</p>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <h2 class="text-2xl font-bold mt-8">Recent Transactions</h2>
        <table class="w-full bg-white shadow-lg rounded mt-4">
            <thead>
                <tr class="bg-blue-500 text-white">
                    <th class="p-2">Date</th>
                    <th class="p-2">Description</th>
                    <th class="p-2">Amount</th>
                    <th class="p-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b">
                    <td class="p-2">2025-03-10</td>
                    <td class="p-2">Payment Received</td>
                    <td class="p-2">$500</td>
                    <td class="p-2 text-green-500 font-bold">Completed</td>
                </tr>
                <tr>
                    <td class="p-2">2025-03-09</td>
                    <td class="p-2">Utility Bill</td>
                    <td class="p-2">$150</td>
                    <td class="p-2 text-yellow-500 font-bold">Pending</td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>

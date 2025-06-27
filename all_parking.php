<?php
// Start the session at the very top
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Function to get all parking locations from the database
function getAllParkingLocations() {
    $conn = $GLOBALS['conn'];
    
    // Query to get all parking spaces
    $query = "SELECT g.*, l.Latitude, l.Longitude, g.garage_id 
              FROM garage_information g
              LEFT JOIN garagelocation l ON g.username = l.username AND g.garage_id = l.garage_id
              ORDER BY g.created_at DESC";
    
    $result = $conn->query($query);
    
    $parkingLocations = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $parkingLocations[] = [
                'id' => $row['garage_id'],
                'name' => $row['Parking_Space_Name'],
                'address' => $row['Parking_Lot_Address'],
                'type' => $row['Parking_Type'],
                'dimensions' => $row['Parking_Space_Dimensions'],
                'capacity' => $row['Parking_Capacity'],
                'available' => $row['Availability'],
                'price' => $row['PriceperHour'],
                'lat' => $row['Latitude'],
                'lng' => $row['Longitude'],
                'username' => $row['username']
            ];
        }
    }
    
    return $parkingLocations;
}

// Get all parking locations
$parkingLocations = getAllParkingLocations();

// Get user information from database
$username = $_SESSION['username'];
$fullName = $username; // Default to username
$email = ""; // Default empty email

// Try to get user's personal information
$query = "SELECT * FROM personal_information WHERE email LIKE '%$username%' OR firstName LIKE '%$username%' OR lastName LIKE '%$username%'";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $fullName = $row['firstName'] . ' ' . $row['lastName'];
    $email = $row['email'];
    
    // Store in session for future use
    $_SESSION['fullName'] = $fullName;
    $_SESSION['email'] = $email;
} else {
    // Set defaults in session
    $_SESSION['fullName'] = $username;
    $_SESSION['email'] = "";
}

// Get first letter for avatar
$firstLetter = strtoupper(substr($fullName, 0, 1));
// Simple function to get level icon based on total earned points
function getUserLevelIcon($username, $conn) {
    // Get total earned points for this user
    $query = "SELECT COALESCE(SUM(points_amount), 0) as total_earned 
              FROM points_transactions 
              WHERE username = ? AND transaction_type = 'earned'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalEarned = 0;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $totalEarned = (int)$row['total_earned'];
    }
    
    // Determine level icon based on earned points
    if ($totalEarned >= 161) {
        return '💎'; // Diamond
    } elseif ($totalEarned >= 100) {
        return '🏆'; // Gold  
    } elseif ($totalEarned >= 15) {
        return '⭐'; // Bronze
    } else {
        return ''; // No icon for less than 15 earned
    }
}

// Get level icon for current user
$levelIcon = getUserLevelIcon($username, $conn);

// Function to get level name for tooltip/display
function getUserLevelName($username, $conn) {
    $query = "SELECT COALESCE(SUM(points_amount), 0) as total_earned 
              FROM points_transactions 
              WHERE username = ? AND transaction_type = 'earned'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $totalEarned = 0;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $totalEarned = (int)$row['total_earned'];
    }
    
    if ($totalEarned >= 161) {
        return 'Diamond';
    } elseif ($totalEarned >= 100) {
        return 'Gold';
    } elseif ($totalEarned >= 15) {
        return 'Bronze';
    } else {
        return 'New User';
    }
}

$levelName = getUserLevelName($username, $conn);
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পার্কিং লাগবে - All Parking Locations</title>
    <!-- Tailwind CSS and daisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f39c12',
                        'primary-dark': '#e67e22',
                    }
                }
            }
        }
    </script>
    <style>
        .rating-stars {
            color: #f39c12;
        }
        .card-img-top {
            height: 200px;
            object-fit: cover;
            background-color: #e5e5e5;
        }
        .location-marker {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
    </style>
</head>
<body class="relative min-h-screen">
    <!-- Background Image with Overlay -->
    <div class="fixed inset-0 bg-cover bg-center bg-no-repeat z-[-2]" 
         style="background-image: url('https://images.unsplash.com/photo-1573348722427-f1d6819fdf98?q=80&w=1374&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D')">
    </div>
    <div class="fixed inset-0 bg-black/50 z-[-1]"></div>
    
    <!-- Header -->
    <header class="sticky top-0 z-50 bg-black/50 backdrop-blur-md border-b border-white/20">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <a href="home.php" class="flex items-center gap-4 text-white">
                <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path></svg>
                </div>
                <h1 class="text-xl font-semibold drop-shadow-md">Car Parking System</h1>
            </a>
            
            <nav class="hidden md:block">
                <ul class="flex gap-8">
                    <li><a href="home.php" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Home</a></li>
                    <li><a href="all_parking.php" class="text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-full after:bg-primary after:transition-all">All Parking</a></li>
                    <li><a href="#contact" class="text-white/90 hover:text-primary transition-colors relative after:absolute after:bottom-0 after:left-0 after:h-0.5 after:w-0 hover:after:w-full after:bg-primary after:transition-all">Contact</a></li>
                </ul>
            </nav>
            
            <div class="hidden md:flex items-center gap-4">
                <a href="business_redirect.php" class="btn btn-outline btn-sm text-white border-primary hover:bg-primary hover:border-primary">
                    Switch To Business
                </a>
                
                <!-- Profile Dropdown with Dynamic Data -->
                <div class="relative">
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-circle avatar">
                            <div class="w-10 h-10 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center cursor-pointer">
                                <span class="text-xl font-bold text-primary"><?php echo $firstLetter; ?></span>
                            </div>
                        </div>
                        <ul tabindex="0" class="dropdown-content z-[1] menu p-0 shadow bg-base-100 rounded-box w-72 mt-2">
                            <!-- Profile Header -->
                            <li class="p-4 bg-gradient-to-r from-primary/10 to-primary/5 rounded-t-box border-b border-base-300">
                                <div class="flex items-start gap-3 hover:bg-transparent cursor-default w-full">
                                    <div class="w-12 h-12 rounded-full bg-primary/20 border-2 border-primary overflow-hidden flex items-center justify-center flex-shrink-0">
                                        <span class="text-lg font-bold text-primary"><?php echo $firstLetter; ?></span>
                                    </div>
                                    <div class="flex-1 min-w-0 w-full overflow-hidden">
                                        <div class="font-semibold text-base-content text-sm leading-tight mb-1 truncate flex items-center gap-1">
        <?php echo htmlspecialchars($fullName); ?>
        <?php if ($levelIcon): ?>
            <span title="<?php echo $levelName; ?> Level"><?php echo $levelIcon; ?></span>
        <?php endif; ?>
    </div>
                                        <div class="text-xs text-base-content/60 leading-tight break-words max-w-full overflow-wrap-anywhere"><?php echo htmlspecialchars($email); ?></div>
                                    </div>
                                </div>
                            </li>
                            
                            <!-- Menu Items -->
                            <div class="p-2">
                                <li><a href="my_profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    My Profile
                                </a></li>
                                <li><a href="booking.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    My Bookings
                                </a></li>
                                <li><a href="myvehicles.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    My Vehicles
                                </a></li>
                                <li><a href="payment_history.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-base-200">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                    Payment History
                                </a></li>
                                
                                <!-- Divider -->
                                <div class="divider my-2"></div>
                                
                                <li><a href="logout.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-error/10 hover:text-error">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                    Logout
                                </a></li>
                            </div>
                        </ul>
                    </div>
                </div>
            </div>
            
            <button class="md:hidden btn btn-ghost text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </header>
    
    <!-- Main Content -->
    <main class="container mx-auto px-4 py-10">
        <!-- Featured Section -->
        <section class="mb-16">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-4xl font-bold text-white drop-shadow-md">All Parking Locations</h2>
                <div class="flex gap-4">
                    <div class="form-control">
                        <div class="input-group">
                            <input type="text" placeholder="Search locations..." class="input input-bordered" />
                            <button class="btn btn-square bg-primary border-primary hover:bg-primary-dark">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-outline border-primary text-white m-1">Filter <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg></div>
                        <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                            <li><a>Price: Low to High</a></li>
                            <li><a>Price: High to Low</a></li>
                            <li><a>Availability</a></li>
                            <li><a>Distance</a></li>
                            <li><a>Rating</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Display the parking locations in a grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($parkingLocations)): ?>
                    <div class="col-span-3 bg-black/30 backdrop-blur-md rounded-lg shadow-xl p-8 text-center">
                        <h3 class="text-white text-xl font-semibold mb-4">No Parking Locations Found</h3>
                        <p class="text-white/80">There are currently no parking locations available in the system.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($parkingLocations as $index => $parking): ?>
                        <div class="card bg-black/30 backdrop-blur-md rounded-lg shadow-xl overflow-hidden border border-white/10 transition-all hover:-translate-y-1 hover:shadow-2xl">
                            <figure>
                                <!-- Use placeholder images with parking number for variation -->
                                <img src="https://placehold.co/600x400?text=Parking+<?php echo $index + 1; ?>" alt="<?php echo htmlspecialchars($parking['name']); ?>" class="card-img-top w-full" />
                            </figure>
                            <div class="card-body p-5">
                                <h3 class="card-title text-white text-xl mb-2"><?php echo htmlspecialchars($parking['name']); ?></h3>
                                
                                <div class="location-marker text-white/90 text-sm mb-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                    <?php echo htmlspecialchars($parking['address']); ?>
                                </div>
                                
                                <div class="grid grid-cols-3 gap-2 mb-4">
                                    <div class="text-center">
                                        <p class="text-white font-semibold">1</p>
                                        <p class="text-white/70 text-xs">Spaces</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-white font-semibold">24/7</p>
                                        <p class="text-white/70 text-xs">Hours</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-white font-semibold">4.6</p>
                                        <p class="text-white/70 text-xs">Rating</p>
                                    </div>
                                </div>
                                
                                <div class="flex justify-between items-center mt-auto">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 bg-green-400 rounded-full"></span>
                                        <span class="text-white/90 text-sm">Available (1 spots)</span>
                                    </div>
                                    <div class="text-primary font-semibold"><?php echo '$' . number_format($parking['price'], 2); ?>/hr</div>
                                </div>
                                
                                <div class="card-actions justify-end mt-4">
                                    <a href="booking.php?garage_id=<?php echo htmlspecialchars($parking['id']); ?>" class="btn bg-primary hover:bg-primary-dark text-white border-none w-full">Book Now</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        
        <!-- Large Map Section -->
        <section class="mb-16">
            <h2 class="text-3xl font-bold text-white drop-shadow-md mb-6">Parking Locations Map</h2>
            <div class="rounded-xl overflow-hidden border border-white/20 shadow-xl h-[500px]">
                <div id="parkingMap" class="w-full h-full"></div>
            </div>
        </section>
    </main>
    
    <!-- Footer -->
    <footer class="bg-black/70 backdrop-blur-md border-t border-white/10 pt-16 pb-8">
        <div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
            <!-- Company Info -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">About Us</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Our Story</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">How It Works</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Testimonials</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Press & Media</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Careers</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Partners</a></li>
                </ul>
            </div>
            
            <!-- Services -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Services</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Find Parking</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Monthly Passes</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Business Solutions</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Event Parking</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Airport Parking</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Valet Services</a></li>
                </ul>
            </div>
            
            <!-- Support -->
            <div>
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Support</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Help Center</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">FAQs</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Contact Us</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Refund Policy</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Terms of Service</a></li>
                    <li><a href="#" class="text-white/90 hover:text-primary transition-colors flex items-center gap-2">Privacy Policy</a></li>
                </ul>
            </div>
            
            <!-- Contact -->
            <div id="contact">
                <h3 class="text-white text-lg font-semibold mb-4 pb-2 border-b border-primary w-max">Contact Us</h3>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        123 Parking Avenue, City Center, State 12345
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        (123) 456-7890
                    </li>
                    <li class="flex items-start gap-3 text-white/90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 flex-shrink-0 mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        support@carparkingsystem.com
                    </li>
                </ul>
                
                <div class="flex gap-4 mt-6">
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex justify-center items-center transition-all hover:bg-primary hover:-translate-y-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path><rect x="2" y="9" width="4" height="12"></rect><circle cx="4" cy="4" r="2"></circle></svg>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container mx-auto px-4 mt-10 pt-6 border-t border-white/10 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-white/90 text-sm">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
            <div class="flex gap-6">
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Privacy Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Terms of Service</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Cookie Policy</a>
                <a href="#" class="text-white/90 text-sm hover:text-primary transition-colors">Sitemap</a>
            </div>
        </div>
    </footer>
    
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map centered on Dhaka, Bangladesh
            const map = L.map('parkingMap').setView([23.8103, 90.4125], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Create marker icon
            const parkingIcon = L.divIcon({
                html: `
                    <div style="
                        background-color: #f39c12;
                        color: white;
                        border-radius: 50%;
                        width: 30px;
                        height: 30px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                        border: 2px solid white;
                        box-shadow: 0 0 10px rgba(0,0,0,0.3);
                    ">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <path d="M9 18V6h4.5a2.5 2.5 0 0 1 0 5H9"></path>
                        </svg>
                    </div>
                `,
                className: "",
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15]
            });
            
            // Get parking locations from PHP
            const parkingLocations = <?php echo json_encode($parkingLocations); ?>;
            
            // Add markers for each location
            parkingLocations.forEach(location => {
                if (location.lat && location.lng) {
                    const marker = L.marker([location.lat, location.lng], { icon: parkingIcon }).addTo(map);
                    marker.bindPopup(`
                        <div class="w-52 p-2">
                            <h3 class="text-base font-semibold mb-1">${location.name}</h3>
                            <p class="text-sm mb-1">Address: ${location.address}</p>
                            <p class="text-sm mb-1">Type: ${location.type}</p>
                            <p class="text-sm mb-1">Price: $${location.price}/hour</p>
                            <p class="text-sm mb-1">Capacity: ${location.capacity}</p>
                            <a href="booking.php?garage_id=${location.id}" class="block w-full mt-2 py-1 px-3 bg-primary text-white rounded text-sm text-center hover:bg-primary-dark">Book Now</a>
                        </div>
                    `);
                }
            });
            
            // If no locations have coordinates, set a default marker
            if (!parkingLocations.some(loc => loc.lat && loc.lng)) {
                const marker = L.marker([23.8103, 90.4125]).addTo(map);
                marker.bindPopup("No parking locations with coordinates found").openPopup();
            }
            
            // Search functionality
            const searchInput = document.querySelector('input[placeholder="Search locations..."]');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        const searchValue = searchInput.value.toLowerCase();
                        
                        // Filter visible locations based on search
                        const cards = document.querySelectorAll('.card');
                        let found = false;
                        
                        cards.forEach(card => {
                            const title = card.querySelector('.card-title').innerText.toLowerCase();
                            const address = card.querySelector('.location-marker').innerText.toLowerCase();
                            
                            if (title.includes(searchValue) || address.includes(searchValue)) {
                                card.style.display = 'flex';
                                found = true;
                            } else {
                                card.style.display = 'none';
                            }
                        });
                        
                        // Show message if no results
                        const noResults = document.getElementById('noResults');
                        if (!found && !noResults) {
                            const message = document.createElement('div');
                            message.id = 'noResults';
                            message.className = 'col-span-3 bg-black/30 backdrop-blur-md rounded-lg shadow-xl p-8 text-center';
                            message.innerHTML = `
                                <h3 class="text-white text-xl font-semibold mb-4">No Results Found</h3>
                                <p class="text-white/80">No parking locations matching "${searchValue}" were found.</p>
                            `;
                            
                            const grid = document.querySelector('.grid');
                            grid.appendChild(message);
                        } else if (found && noResults) {
                            noResults.remove();
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
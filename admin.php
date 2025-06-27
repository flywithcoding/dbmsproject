<?php
// Start the session
session_start();

// For connecting to database
require_once("connection.php");

// Check if user is logged in as admin
// For simplicity, we'll assume admin has username 'admin'
// In a real application, you would have a proper admin role system
if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'admin') {
    // Redirect to login page with error message
    header("Location: login.php?error=admin_access_required");
    exit();
}

// This function gets counts of entities needing verification
function getNotificationCounts($conn) {
    $counts = [];
   
    // Count unverified users
    $userQuery = "SELECT COUNT(*) as total FROM account_information WHERE status != 'verified' OR status IS NULL";
    $result = $conn->query($userQuery);
    $counts['unverified_users'] = $result->fetch_assoc()['total'];
   
    // Count unverified garage owners
    $ownerQuery = "SELECT COUNT(*) as total FROM garage_owners WHERE is_verified = 0";
    $result = $conn->query($ownerQuery);
    $counts['unverified_owners'] = $result->fetch_assoc()['total'];
   
    // Count unauthorized garage owners (users who have garages but aren't registered owners)
    // FIXED: Check for users that don't exist in garage_owners OR dual_user tables
    $unauthorizedQuery = "SELECT COUNT(DISTINCT gi.username) as total
                         FROM garage_information gi
                         LEFT JOIN garage_owners go ON gi.username = go.username
                         LEFT JOIN dual_user du ON gi.username = du.username
                         WHERE go.username IS NULL AND du.username IS NULL";
    $result = $conn->query($unauthorizedQuery);
    $counts['unauthorized_owners'] = $result->fetch_assoc()['total'];
   
    // Count unverified garages
    $garageQuery = "SELECT COUNT(*) as total FROM garage_information WHERE is_verified = 0";
    $result = $conn->query($garageQuery);
    $counts['unverified_garages'] = $result->fetch_assoc()['total'];
   
    // Total count for notification badge
    $counts['total'] = $counts['unverified_users'] + $counts['unverified_owners'] +
                       $counts['unauthorized_owners'] + $counts['unverified_garages'];
   
    return $counts;
}


//AJAX handlers

// 1. NEW: Get user verification documents
if (isset($_POST['action']) && $_POST['action'] === 'get_user_verification_docs') {
    $response = ['success' => false, 'message' => 'Username is required'];
    
    if (isset($_POST['username']) && !empty(trim($_POST['username']))) {
        $username = trim($_POST['username']);
        
        try {
            // Get verification request
            $requestQuery = "SELECT vr.*, ai.status as account_status,
                                   CONCAT(pi.firstName, ' ', pi.lastName) as full_name,
                                   pi.email, pi.phone
                            FROM verification_requests vr
                            LEFT JOIN account_information ai ON vr.username = ai.username
                            LEFT JOIN personal_information pi ON vr.username = pi.username
                            WHERE vr.username = ?
                            ORDER BY vr.requested_at DESC LIMIT 1";
            
            $stmt = $conn->prepare($requestQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $requestResult = $stmt->get_result();
            
            $verificationRequest = null;
            if ($requestResult && $requestResult->num_rows > 0) {
                $verificationRequest = $requestResult->fetch_assoc();
            }
            
            // Get verification documents
            $docsQuery = "SELECT vd.*, 
                                CASE 
                                    WHEN vd.document_type = 'nid' THEN 'National ID'
                                    WHEN vd.document_type = 'driving_license' THEN 'Driving License'
                                    WHEN vd.document_type = 'passport' THEN 'Passport'
                                    WHEN vd.document_type = 'vehicle_registration' THEN 'Vehicle Registration'
                                    WHEN vd.document_type = 'vehicle_insurance' THEN 'Vehicle Insurance'
                                    ELSE UPPER(vd.document_type)
                                END as document_type_display
                         FROM verification_documents vd
                         WHERE vd.username = ?
                         ORDER BY vd.submitted_at DESC";
            
            $stmt = $conn->prepare($docsQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $docsResult = $stmt->get_result();
            
            $documents = [];
            if ($docsResult && $docsResult->num_rows > 0) {
                while ($row = $docsResult->fetch_assoc()) {
                    $documents[] = $row;
                }
            }
            
            $response = [
                'success' => true,
                'username' => $username,
                'verification_request' => $verificationRequest,
                'documents' => $documents,
                'total_documents' => count($documents)
            ];
            
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => 'Error retrieving verification documents'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. NEW: Review user verification
if (isset($_POST['action']) && $_POST['action'] === 'review_user_verification') {
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    if (isset($_POST['username']) && isset($_POST['decision']) && isset($_POST['admin_notes'])) {
        $username = trim($_POST['username']);
        $decision = $_POST['decision'];
        $adminNotes = trim($_POST['admin_notes']);
        $adminUsername = $_SESSION['username'] ?? 'admin';
        
        try {
            $conn->begin_transaction();
            
            if ($decision === 'approve') {
                // Update account status to verified
                $updateAccountQuery = "UPDATE account_information SET status = 'verified' WHERE username = ?";
                $stmt = $conn->prepare($updateAccountQuery);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                
                // Update documents to approved
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("ss", $adminUsername, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'approved', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "User {$username} has been verified successfully!",
                    'new_status' => 'verified'
                ];
                
            } elseif ($decision === 'reject') {
                // Update documents to rejected
                $updateDocsQuery = "UPDATE verification_documents 
                                   SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? 
                                   WHERE username = ? AND status = 'pending'";
                $stmt = $conn->prepare($updateDocsQuery);
                $stmt->bind_param("sss", $adminUsername, $adminNotes, $username);
                $stmt->execute();
                
                // Update verification request
                $updateRequestQuery = "UPDATE verification_requests 
                                      SET overall_status = 'rejected', completed_at = NOW(), admin_notes = ? 
                                      WHERE username = ? AND overall_status IN ('pending', 'under_review')";
                $stmt = $conn->prepare($updateRequestQuery);
                $stmt->bind_param("ss", $adminNotes, $username);
                $stmt->execute();
                
                $response = [
                    'success' => true,
                    'message' => "Verification request for {$username} has been rejected.",
                    'new_status' => 'unverified'
                ];
            }
            
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}
// Handler for revenue statistics
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_stats') {
    $revenueStats = getRevenueStats($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $revenueStats]);
    exit();
}

// Handler for payment method revenue breakdown
if (isset($_POST['action']) && $_POST['action'] === 'get_payment_method_revenue') {
    $paymentMethodData = getRevenueByPaymentMethod($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $paymentMethodData]);
    exit();
}

// Handler for top revenue garages
if (isset($_POST['action']) && $_POST['action'] === 'get_top_revenue_garages') {
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
    $topGarages = getTopRevenueGarages($conn, $limit);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $topGarages]);
    exit();
}

// Handler for revenue trends
if (isset($_POST['action']) && $_POST['action'] === 'get_revenue_trends') {
    $period = $_POST['period'] ?? 'last_30_days';
    $trends = getRevenueTrends($conn, $period);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $trends]);
    exit();
}
// Handle get user points history
if (isset($_POST['action']) && $_POST['action'] === 'get_user_points_history') {
    // Clean any previous output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = ['success' => false, 'message' => 'Username is required'];
    
    try {
        if (isset($_POST['username']) && !empty(trim($_POST['username']))) {
            $username = trim($_POST['username']);
            
            // Get user's current points
            $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
            $stmt = $conn->prepare($pointsQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $pointsResult = $stmt->get_result();
            
            $currentPoints = 0;
            if ($pointsResult && $pointsResult->num_rows > 0) {
                $currentPoints = (int)$pointsResult->fetch_assoc()['points'];
            }
            
            // Get points transaction history (last 20 transactions)
            $historyQuery = "SELECT 
                                id,
                                transaction_type,
                                points_amount,
                                description,
                                booking_id,
                                created_at
                            FROM points_transactions 
                            WHERE username = ? 
                            ORDER BY created_at DESC 
                            LIMIT 20";
            
            $stmt = $conn->prepare($historyQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $historyResult = $stmt->get_result();
            
            $history = [];
            if ($historyResult && $historyResult->num_rows > 0) {
                while ($row = $historyResult->fetch_assoc()) {
                    $history[] = [
                        'id' => $row['id'],
                        'transaction_type' => $row['transaction_type'],
                        'points_amount' => (int)$row['points_amount'],
                        'description' => $row['description'],
                        'booking_id' => $row['booking_id'],
                        'created_at' => $row['created_at']
                    ];
                }
            }
            
            $response = [
                'success' => true,
                'current_points' => $currentPoints,
                'history' => $history,
                'total_transactions' => count($history),
                'username' => $username
            ];
            
        } else {
            $response = ['success' => false, 'message' => 'Invalid or missing username'];
        }
        
    } catch (Exception $e) {
        error_log("Points history error: " . $e->getMessage());
        $response = [
            'success' => false, 
            'message' => 'Error retrieving points history'
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}
// Handle points adjustment
if (isset($_POST['action']) && $_POST['action'] === 'adjust_user_points') {
    // CRITICAL: Clean all output buffers and prevent any HTML output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Turn off error display to prevent contamination
    ini_set('display_errors', 0);
    
    // Set headers early
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    try {
        // Validate required fields
        if (!isset($_POST['username']) || !isset($_POST['points_change']) || !isset($_POST['reason'])) {
            $response = ['success' => false, 'message' => 'Missing required fields'];
        } else {
            $username = trim($_POST['username']);
            $pointsChange = (int)$_POST['points_change'];
            $reason = trim($_POST['reason']);
            $adminUsername = $_SESSION['username'] ?? 'admin';
            
            // Validate inputs
            if (empty($username)) {
                $response = ['success' => false, 'message' => 'Username cannot be empty'];
            } elseif ($pointsChange == 0) {
                $response = ['success' => false, 'message' => 'Points change cannot be zero'];
            } elseif (empty($reason)) {
                $response = ['success' => false, 'message' => 'Reason cannot be empty'];
            } else {
                // Database operations
                $conn->begin_transaction();
                
                // Get current points
                $stmt = $conn->prepare("SELECT points FROM account_information WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $currentPoints = (int)$result->fetch_assoc()['points'];
                    $newPoints = $currentPoints + $pointsChange;
                    
                    // Prevent negative points
                    if ($newPoints < 0) {
                        $conn->rollback();
                        $response = [
                            'success' => false, 
                            'message' => "Cannot reduce points below zero. Current: {$currentPoints}, Attempted change: {$pointsChange}"
                        ];
                    } else {
                        // Update points
                        $stmt = $conn->prepare("UPDATE account_information SET points = ? WHERE username = ?");
                        $stmt->bind_param("is", $newPoints, $username);
                        
                        if ($stmt->execute()) {
                            // Log transaction
                            $transactionType = $pointsChange > 0 ? 'bonus' : 'spent';
                            $description = "Admin adjustment by {$adminUsername}: {$reason}";
                            
                            $stmt = $conn->prepare("INSERT INTO points_transactions (username, transaction_type, points_amount, description) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("ssis", $username, $transactionType, abs($pointsChange), $description);
                            
                            if ($stmt->execute()) {
                                $conn->commit();
                                $response = [
                                    'success' => true,
                                    'message' => "Successfully updated {$username}'s points from {$currentPoints} to {$newPoints}",
                                    'old_points' => $currentPoints,
                                    'new_points' => $newPoints,
                                    'change' => $pointsChange
                                ];
                            } else {
                                $conn->rollback();
                                $response = ['success' => false, 'message' => 'Failed to log transaction'];
                            }
                        } else {
                            $conn->rollback();
                            $response = ['success' => false, 'message' => 'Failed to update points'];
                        }
                    }
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => "User '{$username}' not found"];
                }
            }
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        $response = [
            'success' => false, 
            'message' => 'Database error occurred'
        ];
        
        // Log the actual error (but don't send it to client)
        error_log("Points adjustment error: " . $e->getMessage());
    }
    
    // CRITICAL: Output ONLY the JSON, nothing else
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit; // Prevent any further output
}

// Handle get user points history
if (isset($_POST['action']) && $_POST['action'] === 'get_user_points_history') {
    $response = ['success' => false, 'message' => 'Username is required'];
    
    if (isset($_POST['username'])) {
        $username = $_POST['username'];
        
        // Get user's current points
        $pointsQuery = "SELECT points FROM account_information WHERE username = ?";
        $stmt = $conn->prepare($pointsQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $pointsResult = $stmt->get_result();
        
        $currentPoints = 0;
        if ($pointsResult && $pointsResult->num_rows > 0) {
            $currentPoints = $pointsResult->fetch_assoc()['points'];
        }
        
        // Get points transaction history
        $historyQuery = "SELECT * FROM points_transactions WHERE username = ? ORDER BY created_at DESC LIMIT 20";
        $stmt = $conn->prepare($historyQuery);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $historyResult = $stmt->get_result();
        
        $history = [];
        if ($historyResult && $historyResult->num_rows > 0) {
            while ($row = $historyResult->fetch_assoc()) {
                $history[] = $row;
            }
        }
        
        $response = [
            'success' => true,
            'current_points' => $currentPoints,
            'history' => $history
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Add this AJAX handler for garage reviews
if (isset($_POST['action']) && $_POST['action'] === 'get_garage_reviews') {
    $response = ['success' => false, 'message' => 'Garage ID is required'];
    
    if (isset($_POST['garage_id'])) {
        $garage_id = $_POST['garage_id'];
        
        try {
            // Get garage reviews
            $reviewsQuery = "SELECT 
                                r.id,
                                r.rating,
                                r.review_text,
                                r.rater_username,
                                r.created_at,
                                COALESCE(p.firstName, '') as firstName,
                                COALESCE(p.lastName, '') as lastName,
                                b.booking_date,
                                b.booking_time
                              FROM ratings r
                              LEFT JOIN personal_information p ON r.rater_username = p.username
                              LEFT JOIN bookings b ON r.booking_id = b.id
                              WHERE r.garage_id = ?
                              ORDER BY r.created_at DESC";
            
            $stmt = $conn->prepare($reviewsQuery);
            $stmt->bind_param("s", $garage_id);
            $stmt->execute();
            $reviewsResult = $stmt->get_result();
            
            $reviews = [];
            if ($reviewsResult && $reviewsResult->num_rows > 0) {
                while ($row = $reviewsResult->fetch_assoc()) {
                    $reviews[] = $row;
                }
            }
            
            // Get garage rating summary
            $summaryQuery = "SELECT 
                                garage_name,
                                total_ratings,
                                average_rating,
                                five_star,
                                four_star,
                                three_star,
                                two_star,
                                one_star
                              FROM garage_ratings_summary 
                              WHERE garage_id = ?";
            
            $stmt = $conn->prepare($summaryQuery);
            $stmt->bind_param("s", $garage_id);
            $stmt->execute();
            $summaryResult = $stmt->get_result();
            
            $summary = null;
            if ($summaryResult && $summaryResult->num_rows > 0) {
                $summary = $summaryResult->fetch_assoc();
            }
            
            $response = [
                'success' => true,
                'reviews' => $reviews,
                'summary' => $summary,
                'garage_id' => $garage_id // For debugging
            ];
            
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'message' => 'Error fetching reviews: ' . $e->getMessage()
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// SOLUTION 2: Also make sure this debug handler is temporarily added to see what's happening
if (isset($_POST['action']) && $_POST['action'] === 'debug_action') {
    $response = [
        'success' => true,
        'message' => 'Debug successful',
        'received_action' => $_POST['action'],
        'all_post_data' => $_POST
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}


// Get notification counts when the page loads
$notificationCounts = getNotificationCounts($conn);

// Add a new AJAX endpoint to get updated notification counts
if (isset($_POST['action']) && $_POST['action'] === 'get_notification_counts') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'counts' => getNotificationCounts($conn)]);
    exit();
}



// Add this to your AJAX handlers in admin.php

// Handle setting default commission for all garage owners
if (isset($_POST['action']) && $_POST['action'] === 'set_default_commission_for_all') {
    $response = ['success' => false, 'message' => 'Failed to update commission rates'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Default commission rate
        $defaultRate = 30.00;
        
        // Get all owner IDs from both garage_owners and dual_user tables
        $allOwnersQuery = "SELECT owner_id FROM garage_owners 
                          UNION 
                          SELECT owner_id FROM dual_user";
        
        $result = $conn->query($allOwnersQuery);
        $updateCount = 0;
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ownerId = $row['owner_id'];
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission record exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult && $checkResult->num_rows > 0) {
                    // Update existing record
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $defaultRate, $ownerId);
                    $stmt->execute();
                } else {
                    // Insert new record
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $defaultRate);
                    $stmt->execute();
                }
                
                $updateCount++;
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Successfully set 30% commission rate for $updateCount owners."
            ];
        } else {
            $response = [
                'success' => false, 
                'message' => 'No garage owners found to update.'
            ];
        }
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

//upadate commision rate

if (isset($_POST['action']) && $_POST['action'] === 'update_individual_commission') {
    $response = ['success' => false, 'message' => 'Failed to update commission rate'];
    
    if (isset($_POST['owner_id']) && isset($_POST['rate'])) {
        $ownerId = $_POST['owner_id'];
        $rate = (float)$_POST['rate'];
        
        // Validate rate
        if ($rate < 0 || $rate > 100) {
            $response = ['success' => false, 'message' => 'Commission rate must be between 0 and 100'];
        } else {
            try {
                // Determine owner type based on prefix
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission record exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult && $checkResult->num_rows > 0) {
                    // Update existing record
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $rate, $ownerId);
                    $stmt->execute();
                } else {
                    // Insert new record
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $rate);
                    $stmt->execute();
                }
                
                $response = [
                    'success' => true, 
                    'message' => "Commission rate updated to $rate% successfully",
                    'new_rate' => $rate
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false, 
                    'message' => 'Database error: ' . $e->getMessage()
                ];
            }
        }
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}


// Add with other 

// Handler for updating commission rate
if (isset($_POST['action']) && $_POST['action'] === 'update_commission') {
    $response = ['success' => false, 'message' => 'Missing required parameters'];
    
    if (isset($_POST['owner_id']) && isset($_POST['rate'])) {
        $ownerId = $_POST['owner_id'];
        $rate = (float) $_POST['rate'];
        
        if ($rate < 0 || $rate > 100) {
            $response = ['success' => false, 'message' => 'Rate must be between 0 and 100'];
        } else {
            // Determine owner type
            $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
            
            // Check if commission exists
            $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $ownerId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing commission
                $updateQuery = "UPDATE owner_commissions SET rate = ?, updated_at = NOW() WHERE owner_id = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("ds", $rate, $ownerId);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Commission rate updated successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update commission rate: ' . $conn->error];
                }
            } else {
                // Insert new commission
                $insertQuery = "INSERT INTO owner_commissions (owner_id, owner_type, rate) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ssd", $ownerId, $ownerType, $rate);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Commission rate set successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to set commission rate: ' . $conn->error];
                }
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handler for setting default commission for all owners
if (isset($_POST['action']) && $_POST['action'] === 'set_default_commission_for_all') {
    $response = ['success' => false, 'message' => 'Failed to update commission rates'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Default commission rate
        $defaultRate = 30.00;
        
        // Get all owner IDs from both tables
        $ownersQuery = "SELECT owner_id FROM garage_owners 
                       UNION 
                       SELECT owner_id FROM dual_user";
        $result = $conn->query($ownersQuery);
        $updateCount = 0;
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $ownerId = $row['owner_id'];
                $ownerType = (strpos($ownerId, 'U_owner_') === 0) ? 'dual' : 'garage';
                
                // Check if commission entry exists
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // Update existing entry
                    $updateQuery = "UPDATE owner_commissions 
                                   SET rate = ?, updated_at = NOW() 
                                   WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("ds", $defaultRate, $ownerId);
                } else {
                    // Insert new entry
                    $insertQuery = "INSERT INTO owner_commissions 
                                   (owner_id, owner_type, rate) 
                                   VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("ssd", $ownerId, $ownerType, $defaultRate);
                }
                
                $stmt->execute();
                $updateCount++;
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Successfully set 30% commission rate for $updateCount owners."
            ];
        } else {
            $response = [
                'success' => false, 
                'message' => 'No owners found to update.'
            ];
        }
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $response = [
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}



// Add a new endpoint to get users and owners needing verification

if (isset($_POST['action']) && $_POST['action'] === 'get_verification_items') {
    $response = ['success' => true, 'users' => [], 'owners' => [], 'unauthorized' => [], 'garages' => []];
   
    // Get unverified users
    $userQuery = "SELECT a.username, p.firstName, p.lastName, p.email
                 FROM account_information a
                 LEFT JOIN personal_information p ON a.username = p.username
                 WHERE a.status = 'unverified'
                 ORDER BY a.username
                 LIMIT 10";
    $result = $conn->query($userQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['users'][] = $row;
        }
    }
   
    // Get unverified garage owners
    $ownerQuery = "SELECT go.owner_id, go.username, p.firstName, p.lastName, p.email
                  FROM garage_owners go
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.is_verified = 0
                  ORDER BY go.registration_date DESC
                  LIMIT 10";
    $result = $conn->query($ownerQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['owners'][] = $row;
        }
    }
   
    // Get unauthorized garage owners - FIXED QUERY
    $unauthorizedQuery = "SELECT DISTINCT gi.username, p.firstName, p.lastName, p.email
                         FROM garage_information gi
                         LEFT JOIN garage_owners go ON gi.username = go.username
                         LEFT JOIN dual_user du ON gi.username = du.username
                         LEFT JOIN personal_information p ON gi.username = p.username
                         WHERE go.username IS NULL AND du.username IS NULL
                         ORDER BY gi.created_at DESC
                         LIMIT 10";
    $result = $conn->query($unauthorizedQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['unauthorized'][] = $row;
        }
    }
   
    // Get unverified garages
    $garageQuery = "SELECT garage_id, Parking_Space_Name, Parking_Lot_Address, username
                   FROM garage_information
                   WHERE is_verified = 0
                   ORDER BY created_at DESC
                   LIMIT 10";
    $result = $conn->query($garageQuery);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response['garages'][] = $row;
        }
    }
   
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}



// Function to get profit statistics
// Function to get profit statistics
function getProfitStats($conn) {
    $stats = [];
   
    // Total platform profit
    $profitQuery = "SELECT SUM(platform_profit) as total_profit FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['total_profit'] = $result->fetch_assoc()['total_profit'] ?? 0;
   
    // Total owner profits paid (updated column name)
    $profitQuery = "SELECT SUM(owner_profit) as total_owner_profits FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['total_owner_profits'] = $result->fetch_assoc()['total_owner_profits'] ?? 0;
   
    // Today's profit
    $todayProfitQuery = "SELECT SUM(pt.platform_profit) as today_profit 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE DATE(p.payment_date) = CURDATE()";
    $result = $conn->query($todayProfitQuery);
    $stats['today_profit'] = $result->fetch_assoc()['today_profit'] ?? 0;
   
    // This month's profit
    $monthProfitQuery = "SELECT SUM(pt.platform_profit) as month_profit 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE MONTH(p.payment_date) = MONTH(CURDATE()) 
                         AND YEAR(p.payment_date) = YEAR(CURDATE())";
    $result = $conn->query($monthProfitQuery);
    $stats['month_profit'] = $result->fetch_assoc()['month_profit'] ?? 0;
   
    // Profit by owner (top 5) - updated with garage information
    $ownerProfitQuery = "SELECT 
                            pt.owner_id,
                            COALESCE(go.username, du.username) as username,
                            SUM(pt.platform_profit) as total_profit,
                            SUM(pt.owner_profit) as total_owner_profit,
                            COUNT(*) as transaction_count,
                            COUNT(DISTINCT pt.garage_id) as garage_count
                         FROM profit_tracking pt
                         LEFT JOIN garage_owners go ON pt.owner_id = go.owner_id
                         LEFT JOIN dual_user du ON pt.owner_id = du.owner_id
                         GROUP BY pt.owner_id
                         ORDER BY total_profit DESC
                         LIMIT 5";
    $result = $conn->query($ownerProfitQuery);
    $stats['top_profitable_owners'] = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $stats['top_profitable_owners'][] = $row;
        }
    }
   
    return $stats;
}

// Function to get profit details for a specific time period
function getProfitByPeriod($conn, $period = 'last_7_days') {
    $dateCondition = '';
    
    switch ($period) {
        case 'today':
            $dateCondition = "DATE(p.payment_date) = CURDATE()";
            break;
        case 'yesterday':
            $dateCondition = "DATE(p.payment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last_7_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'last_30_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        default:
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    }
    
    $query = "SELECT 
                DATE(p.payment_date) as date,
                SUM(pt.total_amount) as total_revenue,
                SUM(pt.platform_profit) as platform_profit,
                SUM(pt.owner_profit) as owner_commission,
                COUNT(*) as transaction_count
              FROM profit_tracking pt
              INNER JOIN payments p ON pt.payment_id = p.payment_id
              WHERE {$dateCondition}
              GROUP BY DATE(p.payment_date)
              ORDER BY date ASC";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'total_revenue' => floatval($row['total_revenue']),
                'platform_profit' => floatval($row['platform_profit']),
                'owner_commission' => floatval($row['owner_commission']),
                'transaction_count' => intval($row['transaction_count'])
            ];
        }
    }
    
    return $data;
}

// Function to get detailed profit breakdown
function getProfitBreakdown($conn) {
    $query = "SELECT 
                pt.id,
                pt.payment_id,
                pt.booking_id,
                pt.owner_id,
                pt.garage_id,
                pt.garage_name,
                COALESCE(go.username, du.username) as owner_username,
                CONCAT(pi.firstName, ' ', pi.lastName) as owner_name,
                pt.total_amount,
                pt.commission_rate,
                pt.owner_profit,
                pt.platform_profit,
                p.payment_date,
                p.payment_method,
                b.username as customer_username
              FROM profit_tracking pt
              INNER JOIN payments p ON pt.payment_id = p.payment_id
              INNER JOIN bookings b ON pt.booking_id = b.id
              LEFT JOIN garage_owners go ON pt.owner_id = go.owner_id
              LEFT JOIN dual_user du ON pt.owner_id = du.owner_id
              LEFT JOIN personal_information pi ON COALESCE(go.username, du.username) = pi.username
              ORDER BY p.payment_date DESC
              LIMIT 50";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

// Add AJAX handler for profit data
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_stats') {
    $profitStats = getProfitStats($conn);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $profitStats]);
    exit();
}

if (isset($_POST['action']) && $_POST['action'] === 'get_profit_by_period') {
    $period = $_POST['period'] ?? 'last_7_days';
    $profitData = getProfitByPeriod($conn, $period);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $profitData]);
    exit();
}
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_by_period') {
    $period = $_POST['period'] ?? 'last_7_days';
    
    try {
        $profitData = getProfitByPeriod($conn, $period);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $profitData,
            'period' => $period,
            'count' => count($profitData)
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching profit data: ' . $e->getMessage()
        ]);
        exit();
    }
}
// Update your getDashboardStats function to include profit
function getDashboardStats($conn) {
    $stats = [];
   
    // Existing stats...
    $userQuery = "SELECT COUNT(*) as total FROM account_information";
    $result = $conn->query($userQuery);
    $stats['total_users'] = $result->fetch_assoc()['total'];
   
    $allOwnersQuery = "SELECT COUNT(DISTINCT username) as total FROM garage_information";
    $result = $conn->query($allOwnersQuery);
    $stats['all_garage_owners'] = $result->fetch_assoc()['total'];
    
    $registeredOwnersQuery = "SELECT COUNT(*) as total FROM garage_owners";
    $result = $conn->query($registeredOwnersQuery);
    $stats['registered_owners'] = $result->fetch_assoc()['total'];
    
    $stats['total_owners'] = $stats['all_garage_owners'];
   
    $garageQuery = "SELECT COUNT(*) as total FROM garage_information";
    $result = $conn->query($garageQuery);
    $stats['total_garages'] = $result->fetch_assoc()['total'];
   
    $bookingQuery = "SELECT COUNT(*) as total FROM bookings";
    $result = $conn->query($bookingQuery);
    $stats['total_bookings'] = $result->fetch_assoc()['total'];
   
    $activeQuery = "SELECT COUNT(*) as total FROM bookings WHERE status IN ('upcoming', 'active')";
    $result = $conn->query($activeQuery);
    $stats['active_bookings'] = $result->fetch_assoc()['total'];
   
    // Total revenue (unchanged)
    $paymentQuery = "SELECT SUM(amount) as total FROM payments WHERE payment_status = 'paid'";
    $result = $conn->query($paymentQuery);
    $stats['total_payments'] = $result->fetch_assoc()['total'] ?? 0;
   
    // ADD PROFIT STATS
    $profitQuery = "SELECT SUM(platform_profit) as total FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['total_profit'] = $result->fetch_assoc()['total'] ?? 0;
   
    $todayQuery = "SELECT COUNT(*) as total FROM bookings WHERE booking_date = CURDATE()";
    $result = $conn->query($todayQuery);
    $stats['today_bookings'] = $result->fetch_assoc()['total'];
   
    $todayRevenueQuery = "SELECT SUM(p.amount) as total FROM payments p
                          JOIN bookings b ON p.booking_id = b.id
                          WHERE DATE(p.payment_date) = CURDATE() AND p.payment_status = 'paid'";
    $result = $conn->query($todayRevenueQuery);
    $stats['today_revenue'] = $result->fetch_assoc()['total'] ?? 0;
   
    // ADD TODAY'S PROFIT
    $todayProfitQuery = "SELECT SUM(pt.platform_profit) as total 
                         FROM profit_tracking pt 
                         INNER JOIN payments p ON pt.payment_id = p.payment_id 
                         WHERE DATE(p.payment_date) = CURDATE()";
    $result = $conn->query($todayProfitQuery);
    $stats['today_profit'] = $result->fetch_assoc()['total'] ?? 0;
   
    return $stats;
}
// Function to get all users with their personal information
function getAllUsers($conn) {
    $query = "SELECT a.username, a.password, a.status, a.points, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM account_information a 
              LEFT JOIN personal_information p ON a.username = p.username
              ORDER BY a.username";
    $result = $conn->query($query);
    
    $users = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    return $users;
}

// Function to get single user
function getUser($conn, $username) {
    $query = "SELECT a.username, a.password, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM account_information a 
              LEFT JOIN personal_information p ON a.username = p.username
              WHERE a.username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all garage owners (official and unofficial)
function getAllGarageOwners($conn) {
    $query = "SELECT 
                go.owner_id,
                gi.username, 
                COALESCE(go.is_verified, 0) as is_verified,
                COALESCE(go.registration_date, gi.created_at) as registration_date,
                go.last_login,
                COALESCE(go.account_status, 'active') as account_status,
                p.firstName, p.lastName, p.email, p.phone,
                CASE 
                    WHEN go.owner_id LIKE 'G_owner_%' THEN 1
                    WHEN go.owner_id LIKE 'U_owner_%' THEN 0
                    ELSE 0
                END as is_official
              FROM (SELECT DISTINCT username, MIN(created_at) as created_at 
                   FROM garage_information GROUP BY username) gi
              LEFT JOIN garage_owners go ON gi.username = go.username
              LEFT JOIN personal_information p ON gi.username = p.username
              ORDER BY COALESCE(go.registration_date, gi.created_at) DESC";
    
    $result = $conn->query($query);
    
    $owners = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $owners[] = $row;
        }
    }
    
    return $owners;
}

// Function to get a single garage owner
function getGarageOwner($conn, $owner_id) {
    $query = "SELECT go.owner_id, go.username, go.is_verified, go.registration_date, 
              go.last_login, go.account_status, p.firstName, p.lastName, p.email, p.phone, p.address 
              FROM garage_owners go 
              LEFT JOIN personal_information p ON go.username = p.username
              WHERE go.owner_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $owner_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get owner garages
function getOwnerGarages($conn, $username) {
    $query = "SELECT * FROM garage_information WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}


function getOwnerDetails($conn, $ownerId) {
    // Check if this is a dual user (U_owner) or a garage owner (G_owner)
    if (strpos($ownerId, 'U_owner_') === 0) {
        // Dual user
        $query = "SELECT du.owner_id, du.username, du.is_verified, du.registration_date, 
                         du.last_login, du.account_status, 0 as is_official,
                         p.firstName, p.lastName, p.email, p.phone, p.address
                  FROM dual_user du
                  LEFT JOIN personal_information p ON du.username = p.username
                  WHERE du.owner_id = ?";
    } else {
        // Regular garage owner
        $query = "SELECT go.owner_id, go.username, go.is_verified, go.registration_date, 
                         go.last_login, go.account_status, 1 as is_official,
                         p.firstName, p.lastName, p.email, p.phone, p.address
                  FROM garage_owners go
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.owner_id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $ownerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $owner = null;
    if ($result && $result->num_rows > 0) {
        $owner = $result->fetch_assoc();
        
        // Get commission rate
        $commissionQuery = "SELECT rate FROM owner_commissions 
                           WHERE owner_id = ? 
                           ORDER BY created_at DESC LIMIT 1";
        $stmt = $conn->prepare($commissionQuery);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $commissionResult = $stmt->get_result();
        
        if ($commissionResult && $commissionResult->num_rows > 0) {
            $owner['commission_rate'] = $commissionResult->fetch_assoc()['rate'];
        } else {
            $owner['commission_rate'] = 10.00; // Default commission rate
        }
        
        // Get owner's garages
        $garagesQuery = "SELECT g.garage_id, g.Parking_Space_Name as name, 
                              g.Parking_Lot_Address as address, g.Parking_Type as type,
                              g.Parking_Capacity as capacity, g.PriceperHour as price
                         FROM garage_information g
                         WHERE g.username = ?";
        $stmt = $conn->prepare($garagesQuery);
        $stmt->bind_param("s", $owner['username']);
        $stmt->execute();
        $garagesResult = $stmt->get_result();
        
        $owner['garages'] = [];
        if ($garagesResult && $garagesResult->num_rows > 0) {
            while ($garage = $garagesResult->fetch_assoc()) {
                $owner['garages'][] = $garage;
            }
        }
    }
    
    return $owner;
}

// Add this to handle the AJAX request for owner details
if (isset($_POST['action']) && $_POST['action'] === 'get_owner_details') {
    $response = ['success' => false, 'message' => 'Owner not found'];
    
    if (isset($_POST['owner_id'])) {
        $ownerId = $_POST['owner_id'];
        $owner = getOwnerDetails($conn, $ownerId);
        
        if ($owner) {
            $response = ['success' => true, 'data' => $owner];
        }
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}



// Function to get unverified garages
function getUnverifiedGarages($conn) {
    $query = "SELECT g.id, g.username, g.Parking_Space_Name, g.Parking_Lot_Address, 
              g.Parking_Type, g.Parking_Space_Dimensions, g.Parking_Capacity, 
              g.Availability, g.PriceperHour, g.created_at, g.garage_id, g.is_verified, 
              gl.Latitude, gl.Longitude
              FROM garage_information g
              LEFT JOIN garagelocation gl ON g.garage_id = gl.garage_id
              WHERE g.is_verified = 0
              ORDER BY g.created_at DESC";
    
    $result = $conn->query($query);
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}
function getUnverifiedGaragesCount($conn) {
    $query = "SELECT COUNT(*) as count FROM garage_information WHERE is_verified = 0";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    
    return 0;
}
// Function to get all garages
function getAllGarages($conn) {
    $query = "SELECT g.id, g.username, g.Parking_Space_Name, g.Parking_Lot_Address, 
              g.Parking_Type, g.Parking_Space_Dimensions, g.Parking_Capacity, 
              g.Availability, g.PriceperHour, g.created_at, g.garage_id,
              gl.Latitude, gl.Longitude
              FROM garage_information g
              LEFT JOIN garagelocation gl ON g.garage_id = gl.garage_id
              ORDER BY g.created_at DESC";
    
    $result = $conn->query($query);
    
    $garages = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $garages[] = $row;
        }
    }
    
    return $garages;
}

// Function to get all bookings
function getAllBookings($conn) {
    $query = "SELECT b.id, b.username, b.garage_id, b.licenseplate, b.booking_date, 
              b.booking_time, b.duration, b.status, b.payment_status, b.created_at,
              g.Parking_Space_Name, v.make, v.model, v.color
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
              ORDER BY b.booking_date DESC, b.booking_time DESC";
    $result = $conn->query($query);
    
    $bookings = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    }
    
    return $bookings;
}

// Function to get a single booking with all details
function getBooking($conn, $booking_id) {
    $query = "SELECT b.id, b.username, b.garage_id, b.licenseplate, b.booking_date, 
              b.booking_time, b.duration, b.status, b.payment_status, b.created_at, b.updated_at,
              g.Parking_Space_Name, g.Parking_Lot_Address, g.PriceperHour,
              v.make, v.model, v.color, v.vehicleType,
              p.firstName, p.lastName, p.email, p.phone
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN vehicle_information v ON b.licenseplate = v.licensePlate
              LEFT JOIN personal_information p ON b.username = p.username
              WHERE b.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all payments
function getAllPayments($conn) {
    $query = "SELECT 
                b.id AS booking_id,
                b.username,
                b.garage_id,
                b.licenseplate,
                b.booking_date,
                b.booking_time,
                b.duration,
                b.status AS booking_status,
                b.payment_status AS booking_payment_status,
                b.created_at AS booking_created_at,
                b.updated_at AS booking_updated_at,
                p.payment_id,
                p.transaction_id,
                p.amount,
                p.payment_method,
                p.payment_status,
                p.payment_date,
                g.Parking_Space_Name,
                g.PriceperHour,
                CASE
                    WHEN p.payment_id IS NOT NULL THEN p.payment_status
                    ELSE b.payment_status
                END AS effective_payment_status,
                CASE
                    WHEN p.amount IS NOT NULL THEN p.amount
                    ELSE (g.PriceperHour * b.duration)
                END AS effective_amount
            FROM 
                bookings b
            LEFT JOIN 
                payments p ON b.id = p.booking_id
            LEFT JOIN 
                garage_information g ON b.garage_id = g.garage_id
            ORDER BY 
                COALESCE(p.payment_date, b.updated_at) DESC";
                
    $result = $conn->query($query);
    
    $payments = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
    }
    
    return $payments;
}

// Function to get a single payment with full details
function getPayment($conn, $payment_id) {
    $query = "SELECT p.payment_id, p.booking_id, p.transaction_id, p.amount, 
              p.payment_method, p.payment_status, p.payment_date,
              b.username, b.garage_id, b.booking_date, b.booking_time, b.duration, b.status, 
              g.Parking_Space_Name, g.Parking_Lot_Address,
              pi.firstName, pi.lastName, pi.email, pi.phone
              FROM payments p
              LEFT JOIN bookings b ON p.booking_id = b.id
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              LEFT JOIN personal_information pi ON b.username = pi.username
              WHERE p.payment_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get all vehicles
function getAllVehicles($conn) {
    $query = "SELECT v.licensePlate, v.vehicleType, v.make, v.model, v.color, v.username,
              p.firstName, p.lastName
              FROM vehicle_information v
              LEFT JOIN personal_information p ON v.username = p.username
              ORDER BY v.username";
    $result = $conn->query($query);
    
    $vehicles = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $vehicles[] = $row;
        }
    }
    
    return $vehicles;
}

// Function to get a single vehicle with owner details
function getVehicle($conn, $licensePlate) {
    $query = "SELECT v.licensePlate, v.vehicleType, v.make, v.model, v.color, v.username,
              p.firstName, p.lastName, p.email, p.phone, p.address
              FROM vehicle_information v
              LEFT JOIN personal_information p ON v.username = p.username
              WHERE v.licensePlate = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $licensePlate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to get booking history for a vehicle
function getVehicleBookingHistory($conn, $licensePlate) {
    $query = "SELECT b.id, b.booking_date, b.booking_time, b.duration, b.status, b.payment_status,
              g.Parking_Space_Name, g.Parking_Lot_Address
              FROM bookings b
              LEFT JOIN garage_information g ON b.garage_id = g.garage_id
              WHERE b.licenseplate = ?
              ORDER BY b.booking_date DESC, b.booking_time DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $licensePlate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    }
    
    return $bookings;
}

// Handle AJAX requests for CRUD operations
if (isset($_POST['action'])) {
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    switch ($_POST['action']) {
        case 'delete_user':
            if (isset($_POST['username'])) {
                $username = $_POST['username'];
                $query = "DELETE FROM account_information WHERE username = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User deleted successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error deleting user: ' . $stmt->error];
                }
            }
            break;
        case 'verify_user':
    if (isset($_POST['username'])) {
        $username = $_POST['username'];
        
        $query = "UPDATE account_information SET status = 'verified' WHERE username = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'User verified successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error verifying user: ' . $stmt->error];
        }
    }
    break;

    
            
        case 'verify_owner':
    if (isset($_POST['owner_id'])) {
        $ownerId = $_POST['owner_id'];
        
        // Check if we need to register the owner first
        if (isset($_POST['register_first']) && $_POST['register_first'] === 'true' && isset($_POST['username'])) {
            $username = $_POST['username'];
            $newOwnerId = "G_owner_" . $username;
            
            // First check if the owner already exists
            $checkQuery = "SELECT * FROM garage_owners WHERE username = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                // Insert new garage owner
                $insertQuery = "INSERT INTO garage_owners (owner_id, username, is_verified, registration_date, account_status, original_type) 
                                VALUES (?, ?, 1, NOW(), 'active', 'user')";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ss", $newOwnerId, $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner registered and verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error registering garage owner: ' . $stmt->error];
                }
            } else {
                // Owner exists, just update verification
                $updateQuery = "UPDATE garage_owners SET is_verified = 1 WHERE username = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("s", $username);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
                }
            }
        } else {
            // Original verification code for existing owners
            $query = "UPDATE garage_owners SET is_verified = 1 WHERE owner_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $ownerId);
            
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Garage owner verified successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Error verifying garage owner: ' . $stmt->error];
            }
        }
    }
    break;


    case 'verify_garage':
    if (isset($_POST['garage_id'])) {
        $garageId = $_POST['garage_id'];
        
        // ডিবাগিং লগ
        error_log("Admin is verifying garage: " . $garageId);
        
        $query = "UPDATE garage_information SET is_verified = 1 WHERE garage_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $garageId);
        
        if ($stmt->execute()) {
            error_log("Garage verification successful for: " . $garageId);
            $response = ['success' => true, 'message' => 'Garage verified successfully'];
        } else {
            error_log("Garage verification failed for: " . $garageId . ". Error: " . $stmt->error);
            $response = ['success' => false, 'message' => 'Error verifying garage: ' . $stmt->error];
        }
    } else {
        error_log("Garage ID not provided for verification");
        $response = ['success' => false, 'message' => 'Garage ID is required'];
    }
    break;

            case 'update_owner_status':
    if (isset($_POST['owner_id']) && isset($_POST['status'])) {
        $ownerId = $_POST['owner_id'];
        $status = $_POST['status']; // 'active', 'suspended', 'inactive'
        
        $query = "UPDATE garage_owners SET account_status = ? WHERE owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $status, $ownerId);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Owner status updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error updating owner status: ' . $stmt->error];
        }
    }
    break;
    case 'update_commission_rate':
    if (isset($_POST['owner_id']) && isset($_POST['commission_rate'])) {
        $ownerId = $_POST['owner_id'];
        $commissionRate = $_POST['commission_rate'];
        
        // First check if a commission record exists
        $checkQuery = "SELECT * FROM owner_commissions WHERE owner_id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing record
            $updateQuery = "UPDATE owner_commissions SET rate = ?, updated_at = NOW() WHERE owner_id = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ds", $commissionRate, $ownerId);
        } else {
            // Insert new record
            $insertQuery = "INSERT INTO owner_commissions (owner_id, rate) VALUES (?, ?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("sd", $ownerId, $commissionRate);
        }
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Commission rate updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error updating commission rate: ' . $stmt->error];
        }
    }
    break;

    case 'send_owner_message':
    if (isset($_POST['owner_id']) && isset($_POST['message'])) {
        $ownerId = $_POST['owner_id'];
        $message = $_POST['message'];
        $subject = $_POST['subject'] ?? 'Message from Admin';
        
        // Get owner email from database
        $query = "SELECT p.email FROM garage_owners go 
                  LEFT JOIN personal_information p ON go.username = p.username
                  WHERE go.owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $email = $result->fetch_assoc()['email'];
            
            // In a real application, use mail() or PHPMailer to send an actual email
            // For demonstration, we'll just simulate success
            $response = ['success' => true, 'message' => 'Message sent successfully to ' . $email];
        } else {
            $response = ['success' => false, 'message' => 'Owner email not found'];
        }
    }
    break;
            
        case 'update_garage':
            if (isset($_POST['garage_id']) && isset($_POST['price'])) {
                $garageId = $_POST['garage_id'];
                $price = $_POST['price'];
                $query = "UPDATE garage_information SET PriceperHour = ? WHERE garage_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ds", $price, $garageId);
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Garage price updated successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Error updating garage price: ' . $stmt->error];
                }
            }
            break;
            
        case 'cancel_booking':
            if (isset($_POST['booking_id'])) {
                $bookingId = $_POST['booking_id'];
                
                // First get the garage_id to update availability
                $getGarageQuery = "SELECT garage_id FROM bookings WHERE id = ?";
                $stmt = $conn->prepare($getGarageQuery);
                $stmt->bind_param("i", $bookingId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $garageId = $result->fetch_assoc()['garage_id'];
                    
                    // Update booking status
                    $updateQuery = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("i", $bookingId);
                    
                    if ($stmt->execute()) {
                        // Update garage availability
                        $updateGarageQuery = "UPDATE garage_information SET Availability = Availability + 1 WHERE garage_id = ?";
                        $stmt = $conn->prepare($updateGarageQuery);
                        $stmt->bind_param("s", $garageId);
                        $stmt->execute();
                        
                        $response = ['success' => true, 'message' => 'Booking cancelled successfully'];
                    } else {
                        $response = ['success' => false, 'message' => 'Error cancelling booking: ' . $stmt->error];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Booking not found'];
                }
            }
            break;
            
        case 'add_user':
            if (isset($_POST['username']) && isset($_POST['password'])) {
                $username = $_POST['username'];
                $password = $_POST['password'];
                $firstName = $_POST['firstName'] ?? '';
                $lastName = $_POST['lastName'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                
                // Check if username exists
                $checkQuery = "SELECT username FROM account_information WHERE username = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    $response = ['success' => false, 'message' => 'Username already exists'];
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Insert account info
                        $accountQuery = "INSERT INTO account_information (username, password) VALUES (?, ?)";
                        $stmt = $conn->prepare($accountQuery);
                        $stmt->bind_param("ss", $username, $password);
                        $stmt->execute();
                        
                        // Insert personal info if email provided
                        if (!empty($email)) {
                            $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                                              VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = $conn->prepare($personalQuery);
                            $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                            $stmt->execute();
                        }
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $response = ['success' => true, 'message' => 'User added successfully'];
                    } catch (Exception $e) {
                        // Rollback on error
                        $conn->rollback();
                        $response = ['success' => false, 'message' => 'Error adding user: ' . $e->getMessage()];
                    }
                }
            }
            break;
            
        case 'update_user':
            if (isset($_POST['username']) && isset($_POST['password'])) {
                $username = $_POST['username'];
                $password = $_POST['password'];
                $firstName = $_POST['firstName'] ?? '';
                $lastName = $_POST['lastName'] ?? '';
                $email = $_POST['email'] ?? '';
                $phone = $_POST['phone'] ?? '';
                $address = $_POST['address'] ?? '';
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Update account info
                    $accountQuery = "UPDATE account_information SET password = ? WHERE username = ?";
                    $stmt = $conn->prepare($accountQuery);
                    $stmt->bind_param("ss", $password, $username);
                    $stmt->execute();
                    
                    // Check if personal info exists
                    $checkQuery = "SELECT email FROM personal_information WHERE username = ?";
                    $stmt = $conn->prepare($checkQuery);
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $checkResult = $stmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Update personal info
                        $personalQuery = "UPDATE personal_information SET firstName = ?, lastName = ?, 
                                          phone = ?, address = ? WHERE username = ?";
                        $stmt = $conn->prepare($personalQuery);
                        $stmt->bind_param("sssss", $firstName, $lastName, $phone, $address, $username);
                        $stmt->execute();
                    } else if (!empty($email)) {
                        // Insert personal info
                        $personalQuery = "INSERT INTO personal_information (firstName, lastName, email, phone, address, username) 
                                          VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($personalQuery);
                        $stmt->bind_param("ssssss", $firstName, $lastName, $email, $phone, $address, $username);
                        $stmt->execute();
                    }
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $response = ['success' => true, 'message' => 'User updated successfully'];
                } catch (Exception $e) {
                    // Rollback on error
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()];
                }
            }
            break;
            
        case 'refund_payment':
            if (isset($_POST['payment_id'])) {
                $paymentId = $_POST['payment_id'];
                
                // First get the payment and booking details
                $getPaymentQuery = "SELECT p.booking_id, b.garage_id 
                                    FROM payments p 
                                    JOIN bookings b ON p.booking_id = b.id 
                                    WHERE p.payment_id = ?";
                $stmt = $conn->prepare($getPaymentQuery);
                $stmt->bind_param("i", $paymentId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $paymentData = $result->fetch_assoc();
                    $bookingId = $paymentData['booking_id'];
                    $garageId = $paymentData['garage_id'];
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Update payment status
                        $updatePaymentQuery = "UPDATE payments SET payment_status = 'refunded' WHERE payment_id = ?";
                        $stmt = $conn->prepare($updatePaymentQuery);
                        $stmt->bind_param("i", $paymentId);
                        $stmt->execute();
                        
                        // Update booking payment status
                        $updateBookingQuery = "UPDATE bookings SET payment_status = 'refunded' WHERE id = ?";
                        $stmt = $conn->prepare($updateBookingQuery);
                        $stmt->bind_param("i", $bookingId);
                        $stmt->execute();
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $response = ['success' => true, 'message' => 'Payment refunded successfully'];
                    } catch (Exception $e) {
                        // Rollback on error
                        $conn->rollback();
                        $response = ['success' => false, 'message' => 'Error refunding payment: ' . $e->getMessage()];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Payment not found'];
                }
            }
            break;
            
        case 'get_user':
            if (isset($_POST['username'])) {
                $username = $_POST['username'];
                $user = getUser($conn, $username);
                
                if ($user) {
                    $response = ['success' => true, 'data' => $user];
                } else {
                    $response = ['success' => false, 'message' => 'User not found'];
                }
            }
            break;
            
        case 'get_owner':
            if (isset($_POST['owner_id'])) {
                $ownerId = $_POST['owner_id'];
                $owner = getGarageOwner($conn, $ownerId);
                
                if ($owner) {
                    // Get owner's garages
                    $owner['garages'] = getOwnerGarages($conn, $owner['username']);
                    $response = ['success' => true, 'data' => $owner];
                } else {
                    $response = ['success' => false, 'message' => 'Garage owner not found'];
                }
            }
            break;
            
        case 'get_booking':
            if (isset($_POST['booking_id'])) {
                $bookingId = $_POST['booking_id'];
                $booking = getBooking($conn, $bookingId);
                
                if ($booking) {
                    // Get payment details if any
                    $paymentQuery = "SELECT * FROM payments WHERE booking_id = ?";
                    $stmt = $conn->prepare($paymentQuery);
                    $stmt->bind_param("i", $bookingId);
                    $stmt->execute();
                    $paymentResult = $stmt->get_result();
                    
                    if ($paymentResult && $paymentResult->num_rows > 0) {
                        $booking['payment'] = $paymentResult->fetch_assoc();
                    }
                    
                    $response = ['success' => true, 'data' => $booking];
                } else {
                    $response = ['success' => false, 'message' => 'Booking not found'];
                }
            }
            break;

        case 'get_commission_rate':
    if (isset($_POST['owner_id'])) {
        $ownerId = $_POST['owner_id'];
        
        // Check if commission record exists
        $query = "SELECT rate FROM owner_commissions WHERE owner_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response = ['success' => true, 'rate' => $row['rate']];
        } else {
            $response = ['success' => false, 'message' => 'No commission rate found for this owner'];
        }
    } else {
        $response = ['success' => false, 'message' => 'Owner ID is required'];
    }
    break;
            
        case 'get_payment':
            if (isset($_POST['payment_id'])) {
                $paymentId = $_POST['payment_id'];
                $payment = getPayment($conn, $paymentId);
                
                if ($payment) {
                    $response = ['success' => true, 'data' => $payment];
                } else {
                    $response = ['success' => false, 'message' => 'Payment not found'];
                }
            }
            break;
            
        case 'get_vehicle':
            if (isset($_POST['license_plate'])) {
                $licensePlate = $_POST['license_plate'];
                $vehicle = getVehicle($conn, $licensePlate);
                
                if ($vehicle) {
                    // Get booking history
                    $vehicle['booking_history'] = getVehicleBookingHistory($conn, $licensePlate);
                    $response = ['success' => true, 'data' => $vehicle];
                } else {
                    $response = ['success' => false, 'message' => 'Vehicle not found'];
                }
            }
            break;
        case 'set_default_commission_for_all':
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // First, get all garage owners
        $ownersQuery = "SELECT owner_id FROM garage_owners";
        $ownersResult = $conn->query($ownersQuery);
        
        $updated = 0;
        $added = 0;
        
        if ($ownersResult) {
            while ($owner = $ownersResult->fetch_assoc()) {
                $ownerId = $owner['owner_id'];
                
                // Check if this owner already has a commission entry
                $checkQuery = "SELECT id FROM owner_commissions WHERE owner_id = ?";
                $stmt = $conn->prepare($checkQuery);
                $stmt->bind_param("s", $ownerId);
                $stmt->execute();
                $checkResult = $stmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    // Update existing commission
                    $updateQuery = "UPDATE owner_commissions SET rate = 30.00, updated_at = NOW() WHERE owner_id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("s", $ownerId);
                    $stmt->execute();
                    $updated++;
                } else {
                    // Insert new commission
                    $insertQuery = "INSERT INTO owner_commissions (owner_id, rate) VALUES (?, 30.00)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("s", $ownerId);
                    $stmt->execute();
                    $added++;
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $response = [
                'success' => true, 
                'message' => "Commission rates updated successfully! Updated: $updated, Added: $added"
            ];
        } else {
            throw new Exception("Error fetching garage owners: " . $conn->error);
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    break;
            
        
    }
    
    // Return JSON response for AJAX requests
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Get data for dashboard
$stats = getDashboardStats($conn);
$users = getAllUsers($conn);
$owners = getAllGarageOwners($conn);
$garages = getAllGarages($conn);
$bookings = getAllBookings($conn);
$payments = getAllPayments($conn);
$vehicles = getAllVehicles($conn);

// Get active tab from query parameter or default to dashboard
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Function to get garage reviews
function getGarageReviews($conn, $garage_id) {
    $query = "SELECT 
                r.id,
                r.rating,
                r.review_text,
                r.rater_username,
                r.created_at,
                p.firstName,
                p.lastName,
                b.booking_date,
                b.booking_time
              FROM ratings r
              LEFT JOIN personal_information p ON r.rater_username = p.username
              LEFT JOIN bookings b ON r.booking_id = b.id
              WHERE r.garage_id = ?
              ORDER BY r.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reviews = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }
    }
    
    return $reviews;
}

// Function to get garage rating summary
function getGarageRatingSummary($conn, $garage_id) {
    $query = "SELECT 
                garage_name,
                total_ratings,
                average_rating,
                five_star,
                four_star,
                three_star,
                two_star,
                one_star
              FROM garage_ratings_summary 
              WHERE garage_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $garage_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Add this AJAX handler to your existing AJAX handlers section
if (isset($_POST['action']) && $_POST['action'] === 'get_garage_reviews') {
    $response = ['success' => false, 'message' => 'Garage ID is required'];
    
    if (isset($_POST['garage_id'])) {
        $garage_id = $_POST['garage_id'];
        
        try {
            $reviews = getGarageReviews($conn, $garage_id);
            $summary = getGarageRatingSummary($conn, $garage_id);
            
            $response = [
                'success' => true,
                'reviews' => $reviews,
                'summary' => $summary
            ];
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'message' => 'Error fetching reviews: ' . $e->getMessage()
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
// Handle get profit by period - THIS WAS MISSING!
if (isset($_POST['action']) && $_POST['action'] === 'get_profit_by_period') {
    $period = $_POST['period'] ?? 'last_7_days';
    
    try {
        $profitData = getProfitByPeriod($conn, $period);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'data' => $profitData,
            'period' => $period,
            'count' => count($profitData)
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching profit data: ' . $e->getMessage()
        ]);
        exit();
    }
}
function getRevenueStats($conn) {
    $stats = [];
    
    // Total Revenue (all paid payments)
    $revenueQuery = "SELECT SUM(amount) as total_revenue FROM payments WHERE payment_status = 'paid'";
    $result = $conn->query($revenueQuery);
    $stats['total_revenue'] = $result->fetch_assoc()['total_revenue'] ?? 0;
    
    // Platform Profit (from profit_tracking table)
    $profitQuery = "SELECT SUM(platform_profit) as total_profit FROM profit_tracking";
    $result = $conn->query($profitQuery);
    $stats['total_profit'] = $result->fetch_assoc()['total_profit'] ?? 0;
    
    // Owner Earnings (total owner profits)
    $ownerQuery = "SELECT SUM(owner_profit) as total_owner_earnings FROM profit_tracking";
    $result = $conn->query($ownerQuery);
    $stats['total_owner_earnings'] = $result->fetch_assoc()['total_owner_earnings'] ?? 0;
    
    // Pending Revenue (unpaid bookings)
    $pendingQuery = "SELECT SUM(g.PriceperHour * b.duration) as pending_revenue
                     FROM bookings b 
                     JOIN garage_information g ON b.garage_id = g.garage_id
                     WHERE b.payment_status = 'pending'";
    $result = $conn->query($pendingQuery);
    $stats['pending_revenue'] = $result->fetch_assoc()['pending_revenue'] ?? 0;
    
    // Today's Revenue
    $todayQuery = "SELECT SUM(amount) as today_revenue 
                   FROM payments 
                   WHERE payment_status = 'paid' AND DATE(payment_date) = CURDATE()";
    $result = $conn->query($todayQuery);
    $stats['today_revenue'] = $result->fetch_assoc()['today_revenue'] ?? 0;
    
    // This Month's Revenue
    $monthQuery = "SELECT SUM(amount) as month_revenue 
                   FROM payments 
                   WHERE payment_status = 'paid' 
                   AND MONTH(payment_date) = MONTH(CURDATE()) 
                   AND YEAR(payment_date) = YEAR(CURDATE())";
    $result = $conn->query($monthQuery);
    $stats['month_revenue'] = $result->fetch_assoc()['month_revenue'] ?? 0;
    
    return $stats;
}

// Function to get revenue by payment method
function getRevenueByPaymentMethod($conn) {
    $query = "SELECT 
                payment_method,
                COUNT(*) as transactions,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount
              FROM payments 
              WHERE payment_status = 'paid' 
              GROUP BY payment_method 
              ORDER BY total_amount DESC";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

// Function to get top revenue generating garages
function getTopRevenueGarages($conn, $limit = 10) {
    $query = "SELECT 
                pt.garage_id,
                pt.garage_name,
                pt.owner_id,
                COALESCE(go.username, du.username) as owner_username,
                COUNT(*) as total_bookings,
                SUM(pt.total_amount) as total_revenue,
                SUM(pt.platform_profit) as total_profit,
                SUM(pt.owner_profit) as owner_earnings,
                AVG(pt.total_amount) as avg_booking_value
              FROM profit_tracking pt
              LEFT JOIN garage_owners go ON pt.owner_id = go.owner_id
              LEFT JOIN dual_user du ON pt.owner_id = du.owner_id
              GROUP BY pt.garage_id
              ORDER BY total_revenue DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

// Function to get revenue trends by time period
function getRevenueTrends($conn, $period = 'last_30_days') {
    $dateCondition = '';
    
    switch ($period) {
        case 'last_7_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'last_30_days':
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $dateCondition = "MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())";
            break;
        case 'this_year':
            $dateCondition = "YEAR(p.payment_date) = YEAR(CURDATE())";
            break;
        default:
            $dateCondition = "p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    }
    
    $query = "SELECT 
                DATE(p.payment_date) as date,
                COUNT(*) as transactions,
                SUM(p.amount) as daily_revenue,
                SUM(COALESCE(pt.platform_profit, 0)) as daily_profit
              FROM payments p
              LEFT JOIN profit_tracking pt ON p.payment_id = pt.payment_id
              WHERE p.payment_status = 'paid' AND {$dateCondition}
              GROUP BY DATE(p.payment_date)
              ORDER BY date ASC";
    
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'transactions' => (int)$row['transactions'],
                'revenue' => (float)$row['daily_revenue'],
                'profit' => (float)$row['daily_profit']
            ];
        }
    }
    
    return $data;
}
?>

<!DOCTYPE html>
<html class="bg-gray-900" lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Car Parking System</title>
    <!-- Tailwind CSS and daisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.7.3/dist/full.min.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .status-active {
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.status-suspended {
    background-color: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}
.status-inactive {
    background-color: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}
    </style>
    <script src="https://cdn.tailwindcss.com"></script>
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
    <!-- Chart.js for dashboard charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .data-table {
            width: 100%;
            overflow-x: auto;
        }
        .data-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        /* অ্যালগুলো মার্জ করে একটি .status-badge বানান */
.status-badge {
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-block;
}

/* স্ট্যাটাস ক্লাসগুলো ঠিক আছে */
.status-active { 
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
/* অন্যান্য স্ট্যাটাস ক্লাস... */

/* ভেরিফিকেশন স্ট্যাটাস - HTML এ টেক্সট রাখবেন না */
.status-verified {
    background-color: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.status-verified::before {
    content: "";
}

.status-unverified {
    background-color: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    cursor: pointer;
    transition: all 0.3s ease;
}
.status-unverified::before {
    content: "";
}

.status-unverified:hover::before {
    content: "";
}
        .modal {
            transition: opacity 0.2s ease;
        }
        .modal-box {
            max-width: 90%;
            max-height: 90%;
        }
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-section h4 {
            font-weight: 600;
            margin-bottom: 10px;
            color: #f39c12;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        .detail-item {
            margin-bottom: 10px;
        }
        .detail-label {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 4px;
        }
        .detail-value {
            font-weight: 500;
        }

         html, body {
        height: 100%;
        margin: 0;
    }
    body {
        display: flex;
        flex-direction: column;
    }
    .main-content {
        flex: 1 0 auto;
    }
        footer {
        flex-shrink: 0;
        margin-top: auto;
    }

    /* Status indicator styles */
.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: #10b981; /* Green color for verified status */
    display: inline-block;
}

.status-text {
    font-size: 0.875rem;
    color: #10b981;
    opacity: 0; /* Initially hidden */
    transition: opacity 0.3s ease;
}

tr:hover .status-text {
    opacity: 1; /* Show on row hover */
}

.status-verified .status-text {
    display: inline;
}

/* Animation when status changes */
@keyframes statusChange {
    0% { transform: scale(1); }
    50% { transform: scale(1.5); }
    100% { transform: scale(1); }
}

.status-changed {
    animation: statusChange 0.5s ease;
}

    </style>
</head>
<body class="min-h-screen bg-gray-900">
    <!-- Header -->
    <!-- Replace your existing header code with this: -->
<header class="bg-gray-800 shadow-md">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 bg-primary rounded-full flex justify-center items-center overflow-hidden">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <h1 class="text-xl font-semibold text-white">Admin Dashboard</h1>
        </div>
        
        <div class="flex items-center gap-4">
            <span class="text-white/80">Welcome, Admin</span>
            
            <!-- Notification Icon - Now positioned between Welcome and Logout -->
            <div class="relative">
                <button id="notification-button" class="btn btn-sm btn-ghost text-white relative">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span id="notification-count" class="absolute -top-1 -right-1 bg-primary text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center"><?php echo $notificationCounts['total']; ?></span>
                </button>
                
                <div id="notification-dropdown" class="absolute right-0 mt-2 w-80 bg-gray-800 shadow-lg rounded-lg z-50 hidden">
                    <div class="p-3 border-b border-gray-700">
                        <h3 class="font-bold text-white">Notifications</h3>
                        <p class="text-xs text-white/70">Items needing verification</p>
                    </div>
                    
                    <div id="notification-content" class="max-h-96 overflow-y-auto">
                        <div class="p-6 text-center text-white/70">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <p>Loading notifications...</p>
                        </div>
                    </div>
                    
                    <div class="p-3 border-t border-gray-700 text-center">
                        <button id="refresh-notifications" class="btn btn-sm btn-ghost text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                            </svg>
                            Refresh
                        </button>
                    </div>
                </div>
            </div>
            
            <a href="logout.php" class="btn btn-sm btn-outline text-white border-white/30 hover:bg-white/10 hover:border-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Logout
            </a>
        </div>
    </div>
</header>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Sidebar Navigation -->
            <div class="w-full md:w-64 bg-gray-800 rounded-lg p-4">
                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="?tab=dashboard" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'dashboard' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="?tab=users" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'users' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                                Users
                            </a>
                        </li>
                        <li>
                            <a href="?tab=owners" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'owners' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                                Garage Owners
                            </a>
                        </li>
                        <li>
                            <a href="?tab=garages" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'garages' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                                Garages
                            </a>
                        </li>
                        <li>
    <a href="?tab=unverified_garages" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'unverified_garages' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <polyline points="9 22 9 12 15 12 15 22"></polyline>
        </svg>
        Unverified Garages
    </a>
</li>
                        <li>
                            <a href="?tab=bookings" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'bookings' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                Bookings
                            </a>
                        </li>
                        <li>
                            <a href="?tab=payments" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'payments' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                    <line x1="1" y1="10" x2="23" y2="10"></line>
                                </svg>
                                Payments
                            </a>
                        </li>
                        <li>
                            <a href="?tab=vehicles" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'vehicles' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="1" y="3" width="15" height="13"></rect>
                                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                    <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                    <circle cx="18.5" cy="18.5" r="2.5"></circle>
                                </svg>
                                Vehicles
                            </a>
                        </li>
                        <li>
            <a href="?tab=revenue" class="flex items-center gap-3 p-3 rounded-lg <?php echo $activeTab === 'revenue' ? 'bg-primary text-white' : 'text-white/80 hover:bg-gray-700'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
                Revenue & Profit
            </a>
        </li>
                    </ul>
                </nav>
            </div>
            
            <!-- Main Content Area -->
            <div class="flex-1">
                <!-- Dashboard Tab -->
                <!-- Replace your entire dashboard tab content with this dynamic version -->
<div id="dashboard-tab" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
    <h2 class="text-2xl font-bold text-white mb-6">Dashboard Overview</h2>
    
    <!-- Main Stats Cards - Responsive Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
        <!-- Total Users -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Users</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white"><?php echo number_format($stats['total_users']); ?></h3>
                    <p class="text-xs text-blue-400 mt-1">Registered accounts</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Total Garages -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Garages</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white"><?php echo number_format($stats['total_garages']); ?></h3>
                    <p class="text-xs text-green-400 mt-1">Active locations</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Total Revenue -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Total Revenue</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-white">৳<?php echo number_format($stats['total_payments'], 2); ?></h3>
                    <p class="text-xs text-yellow-400 mt-1">All payments</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-yellow-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Platform Profit -->
        <div class="bg-gray-800 rounded-lg p-4 lg:p-6 shadow-lg hover:shadow-xl transition-shadow border border-emerald-500/20">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-white/60 text-xs lg:text-sm mb-1">Platform Profit</p>
                    <h3 class="text-xl lg:text-2xl font-bold text-emerald-400">৳<?php echo number_format($stats['total_profit'], 2); ?></h3>
                    <p class="text-xs text-emerald-300 mt-1">
                        <?php 
                        $profit_margin = $stats['total_payments'] > 0 ? ($stats['total_profit'] / $stats['total_payments']) * 100 : 0;
                        echo number_format($profit_margin, 1) . '% margin';
                        ?>
                    </p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-emerald-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Stats - Responsive Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 lg:gap-4 mb-6">
        <!-- Today's Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-blue-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['today_bookings']); ?></h4>
            </div>
        </div>

        <!-- Today's Revenue -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Revenue</p>
                <h4 class="text-lg lg:text-xl font-bold text-white">৳<?php echo number_format($stats['today_revenue'], 2); ?></h4>
            </div>
        </div>

        <!-- Today's Profit -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg border border-emerald-500/20">
            <div class="text-center">
                <div class="w-8 h-8 bg-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Today's Profit</p>
                <h4 class="text-lg lg:text-xl font-bold text-emerald-400">৳<?php echo number_format($stats['today_profit'], 2); ?></h4>
            </div>
        </div>

        <!-- Active Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-orange-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12,6 12,12 16,14"></polyline>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Active Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['active_bookings']); ?></h4>
            </div>
        </div>

        <!-- Garage Owners -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-purple-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Garage Owners</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['total_owners']); ?></h4>
            </div>
        </div>

        <!-- Total Bookings -->
        <div class="bg-gray-800 rounded-lg p-3 lg:p-4 shadow-lg">
            <div class="text-center">
                <div class="w-8 h-8 bg-indigo-500/20 rounded-full flex items-center justify-center mx-auto mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14,2 14,8 20,8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10,9 9,9 8,9"></polyline>
                    </svg>
                </div>
                <p class="text-xs text-white/60 mb-1">Total Bookings</p>
                <h4 class="text-lg lg:text-xl font-bold text-white"><?php echo number_format($stats['total_bookings']); ?></h4>
            </div>
        </div>
    </div>

    <!-- Charts and Analytics -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Revenue vs Profit Chart -->
        <div class="lg:col-span-2 bg-gray-800 rounded-lg p-6 shadow-lg">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold text-white">Revenue vs Profit Trend</h4>
                <button id="refresh-profit-chart" class="btn btn-sm btn-ghost text-white/60 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                    </svg>
                </button>
            </div>
            <div class="h-64">
                <canvas id="revenueProfitChart"></canvas>
            </div>
        </div>
        
        <!-- Top Contributing Owners -->
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold text-white">Top Contributing Owners</h4>
                <span class="text-xs text-white/60">by profit generated</span>
            </div>
            <div class="space-y-3 max-h-80 overflow-y-auto">
                <?php 
                $profitStats = getProfitStats($conn);
                if (!empty($profitStats['top_profitable_owners'])): 
                    foreach ($profitStats['top_profitable_owners'] as $index => $owner): 
                ?>
                <div class="flex items-center justify-between p-3 bg-gray-700/50 rounded-lg hover:bg-gray-700/70 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-primary/20 rounded-full flex items-center justify-center text-primary font-bold text-sm">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-medium truncate"><?php echo htmlspecialchars($owner['username']); ?></p>
                            <p class="text-white/60 text-xs"><?php echo $owner['transaction_count']; ?> transactions</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-emerald-400 font-bold text-sm">৳<?php echo number_format($owner['total_profit'], 0); ?></p>
                        <p class="text-white/60 text-xs">profit</p>
                    </div>
                </div>
                <?php 
                    endforeach; 
                else: 
                ?>
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/20 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                    </svg>
                    <p class="text-white/60 text-sm">No profit data available yet</p>
                    <button class="btn btn-sm btn-primary mt-2" onclick="calculateMissingProfits()">Calculate Profits</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Booking Status Chart -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <h4 class="text-lg font-semibold text-white mb-4">Booking Status Distribution</h4>
            <div class="h-64">
                <canvas id="bookingStatusChart"></canvas>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
            <h4 class="text-lg font-semibold text-white mb-4">Quick Actions</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button class="btn btn-primary btn-sm" onclick="calculateMissingProfits()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                    Calculate Profits
                </button>
                
                <button class="btn btn-secondary btn-sm" onclick="refreshDashboard()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                    </svg>
                    Refresh Data
                </button>
                
                <a href="?tab=users" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    Manage Users
                </a>
                
                <a href="?tab=owners" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    Manage Owners
                </a>
            </div>
            
            <!-- System Status -->
            <div class="mt-6 p-4 bg-gray-700/30 rounded-lg">
                <h5 class="text-sm font-semibold text-white mb-3">System Status</h5>
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Database</span>
                        <span class="text-green-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            Online
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Payment System</span>
                        <span class="text-green-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            Active
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-white/60">Profit Tracking</span>
                        <span class="text-<?php echo !empty($profitStats['top_profitable_owners']) ? 'green' : 'yellow'; ?>-400 flex items-center gap-1">
                            <div class="w-2 h-2 bg-<?php echo !empty($profitStats['top_profitable_owners']) ? 'green' : 'yellow'; ?>-400 rounded-full"></div>
                            <?php echo !empty($profitStats['top_profitable_owners']) ? 'Active' : 'Pending'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Bookings -->
    <div class="bg-gray-800 rounded-lg p-6 shadow-lg">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h4 class="text-lg font-semibold text-white">Recent Bookings</h4>
            <div class="flex gap-2">
                <a href="?tab=bookings" class="text-primary hover:text-primary-dark text-sm flex items-center gap-1">
                    View All
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-700">
                        <th class="text-white/70 pb-3 text-sm">ID</th>
                        <th class="text-white/70 pb-3 text-sm">User</th>
                        <th class="text-white/70 pb-3 text-sm">Garage</th>
                        <th class="text-white/70 pb-3 text-sm">Date & Time</th>
                        <th class="text-white/70 pb-3 text-sm">Status</th>
                        <th class="text-white/70 pb-3 text-sm">Payment</th>
                        <th class="text-white/70 pb-3 text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Display only the 5 most recent bookings
                    $recentBookings = array_slice($bookings, 0, 5);
                    if (!empty($recentBookings)):
                        foreach ($recentBookings as $booking): 
                            $bookingDateTime = date('d M Y', strtotime($booking['booking_date'])) . ' at ' . date('h:i A', strtotime($booking['booking_time']));
                            
                            // Determine status class
                            $statusClass = '';
                            switch ($booking['status']) {
                                case 'upcoming': $statusClass = 'bg-blue-500/20 text-blue-400'; break;
                                case 'active': $statusClass = 'bg-green-500/20 text-green-400'; break;
                                case 'completed': $statusClass = 'bg-gray-500/20 text-gray-400'; break;
                                case 'cancelled': $statusClass = 'bg-red-500/20 text-red-400'; break;
                            }
                            
                            // Determine payment status class
                            $paymentClass = '';
                            switch ($booking['payment_status']) {
                                case 'paid': $paymentClass = 'bg-green-500/20 text-green-400'; break;
                                case 'pending': $paymentClass = 'bg-yellow-500/20 text-yellow-400'; break;
                                case 'refunded': $paymentClass = 'bg-purple-500/20 text-purple-400'; break;
                            }
                    ?>
                    <tr class="border-b border-gray-700/50 hover:bg-gray-700/20 transition-colors">
                        <td class="py-3 text-sm text-white">#<?php echo $booking['id']; ?></td>
                        <td class="py-3 text-sm text-white"><?php echo htmlspecialchars($booking['username']); ?></td>
                        <td class="py-3 text-sm text-white truncate max-w-32"><?php echo htmlspecialchars($booking['Parking_Space_Name']); ?></td>
                        <td class="py-3 text-sm text-white/80"><?php echo $bookingDateTime; ?></td>
                        <td class="py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $statusClass; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $paymentClass; ?>">
                                <?php echo ucfirst($booking['payment_status']); ?>
                            </span>
                        </td>
                        <td class="py-3">
                            <button class="btn btn-xs btn-ghost text-white/60 hover:text-white" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </button>
                        </td>
                    </tr>
                    <?php 
                        endforeach; 
                    else:
                    ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center">
                            <div class="text-white/60">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-white/20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <p class="text-sm">No recent bookings found</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
                <!-- Users Tab -->
                <div id="users-tab" class="tab-content <?php echo $activeTab === 'users' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">User Management</h2>
        <div class="flex gap-4">
            <div class="relative">
                <input type="text" id="user-search" placeholder="Search users..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <button class="btn btn-primary" onclick="openAddUserModal()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add User
            </button>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Points</th>
                        <th>Address</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?php if ($user['status'] == 'verified'): ?>
                                <span class="status-badge status-verified">Verified</span>
                            <?php else: ?>
                                <span class="status-badge status-unverified" onclick="verifyUser('<?php echo $user['username']; ?>')">Unverified</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['firstName'] . ' ' . $user['lastName']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['phone']); ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <span class="text-primary font-bold"><?php echo number_format($user['points']); ?></span>
                                <button class="btn btn-xs btn-outline btn-warning" onclick="openPointsModal('<?php echo $user['username']; ?>', <?php echo $user['points']; ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="btn btn-xs btn-outline btn-info" onclick="viewPointsHistory('<?php echo $user['username']; ?>')" title="View Points History">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12,6 12,12 16,14"></polyline>
            </svg>
        </button>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($user['address']); ?></td>
                        <td>
                            <div class="flex gap-2">
                                <button class="btn btn-sm btn-outline btn-info" onclick="editUser('<?php echo $user['username']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="btn btn-sm btn-outline btn-error" onclick="deleteUser('<?php echo $user['username']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
                <!-- Garage Owners Tab -->
<div id="owners-tab" class="tab-content <?php echo $activeTab === 'owners' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Garage Owner Management</h2>
        <div class="flex gap-4">
            <button class="btn btn-primary" onclick="setDefaultCommissionForAll()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"></path>
                </svg>
                Set 30% Commission for All
            </button>
            <div class="relative">
                <input type="text" id="owner-search" placeholder="Search owners..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Owner ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Registration Date</th>
                        <th>Verification</th> <!-- Changed from Status -->
                        <th>Account Status</th> <!-- New column -->
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Get all owners including dual users
                    $ownersQuery = "SELECT 
                        go.owner_id, 
                        go.username, 
                        go.is_verified, 
                        go.registration_date, 
                        go.last_login, 
                        go.account_status,
                        p.firstName, 
                        p.lastName, 
                        p.email, 
                        p.phone, 
                        1 as is_official
                      FROM garage_owners go
                      LEFT JOIN personal_information p ON go.username = p.username
                      
                      UNION
                      
                      SELECT 
                        du.owner_id, 
                        du.username, 
                        du.is_verified, 
                        du.registration_date, 
                        du.last_login, 
                        du.account_status,
                        p.firstName, 
                        p.lastName, 
                        p.email, 
                        p.phone, 
                        0 as is_official
                      FROM dual_user du
                      LEFT JOIN personal_information p ON du.username = p.username
                      
                      ORDER BY registration_date DESC";
                    
                    $ownersResult = $conn->query($ownersQuery);
                    $allOwners = [];
                    
                    if ($ownersResult && $ownersResult->num_rows > 0) {
                        while ($row = $ownersResult->fetch_assoc()) {
                            $allOwners[] = $row;
                        }
                    }
                    
                    foreach ($allOwners as $owner): 
                        $verifiedClass = $owner['is_verified'] ? 'status-verified' : 'status-unverified';
                        $verifiedText = $owner['is_verified'] ? 'Verified' : 'Unverified';
                        $registrationDate = date('d M Y', strtotime($owner['registration_date']));
                    ?>
                    <tr>
                        <td>
                            <?php echo $owner['owner_id']; ?>
                            <?php if ($owner['is_official'] == 0): ?>
                                <!-- Yellow for regular users -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-yellow-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php elseif ($owner['is_official'] == 2): ?>
                                <!-- Green for converted users -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-green-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php else: ?>
                                <!-- Blue for original professional owners -->
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline h-5 w-5 text-blue-500 ml-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $owner['username']; ?></td>
                        <td><?php echo $owner['firstName'] . ' ' . $owner['lastName']; ?></td>
                        <td><?php echo $owner['email']; ?></td>
                        <td><?php echo $owner['phone']; ?></td>
                        <td><?php echo $registrationDate; ?></td>
                        
                        <td>
                            <span class="status-badge <?php echo $verifiedClass; ?>"><?php echo $verifiedText; ?></span>
                        </td>
                        <td>
                            <?php if ($owner['account_status'] == 'active'): ?>
                                <span class="status-badge status-active">Active</span>
                            <?php elseif ($owner['account_status'] == 'suspended'): ?>
                                <span class="status-badge status-suspended">Suspended</span>
                            <?php elseif ($owner['account_status'] == 'inactive'): ?>
                                <span class="status-badge status-inactive">Deactivated</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <?php if (!$owner['is_verified']): ?>
                                <button class="btn btn-sm btn-outline btn-success" onclick="verifyOwner('<?php echo $owner['owner_id']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                    Verify
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline btn-info" onclick="viewOwnerDetails('<?php echo $owner['owner_id']; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </button>
                                <div class="dropdown dropdown-end">
                                    <button tabindex="0" class="btn btn-sm btn-outline">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1"></circle>
                                            <circle cx="12" cy="5" r="1"></circle>
                                            <circle cx="12" cy="19" r="1"></circle>
                                        </svg>
                                    </button>
                                    <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-gray-700 rounded-box w-52">
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'active')">Activate Account</a></li>
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'suspended')">Suspend Account</a></li>
                                        <li><a onclick="updateOwnerStatus('<?php echo $owner['owner_id']; ?>', 'inactive')">Deactivate Account</a></li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Garages Tab -->
<div id="garages-tab" class="tab-content <?php echo $activeTab === 'garages' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Garage Management</h2>
        <div class="flex gap-4">
            <div class="relative">
                <input type="text" id="garage-search" placeholder="Search garages..." class="input input-bordered bg-gray-700 text-white w-64">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Owner</th>
                        <th>Type</th>
                        <th>Capacity</th>
                        <th>Available</th>
                        <th>Price/Hour</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($garages as $garage): 
                        // Make sure values are properly escaped for JavaScript
                        $latitudeJS = isset($garage['Latitude']) ? (float)$garage['Latitude'] : 'null';
                        $longitudeJS = isset($garage['Longitude']) ? (float)$garage['Longitude'] : 'null';
                        $nameJS = addslashes($garage['Parking_Space_Name']);
                    ?>
                    <tr>
                        <td><?php echo $garage['garage_id']; ?></td>
                        <td><?php echo $garage['Parking_Space_Name']; ?></td>
                        <td><?php echo $garage['Parking_Lot_Address']; ?></td>
                        <td><?php echo $garage['username']; ?></td>
                        <td><?php echo $garage['Parking_Type']; ?></td>
                        <td><?php echo $garage['Parking_Capacity']; ?></td>
                        <td><?php echo $garage['Availability']; ?></td>
                        <td>৳<?php echo $garage['PriceperHour']; ?></td>
                        <td>
                            <div class="flex gap-2">
                                <button class="btn btn-sm btn-outline btn-info" onclick="editGarage('<?php echo $garage['garage_id']; ?>', <?php echo $garage['PriceperHour']; ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="btn btn-sm btn-outline btn-success" 
                                        onclick="viewGarageLocation(<?php echo $latitudeJS; ?>, <?php echo $longitudeJS; ?>, '<?php echo $nameJS; ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </button>
                                <!-- NEW: Review button -->
        <button class="btn btn-sm btn-outline btn-warning hover:btn-warning" onclick="viewGarageReviews('<?php echo $garage['garage_id']; ?>', '<?php echo addslashes($garage['Parking_Space_Name']); ?>')" title="View Reviews & Ratings">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26 12,2"></polygon>
    </svg>
</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
                
<!-- Unverified Garages Tab -->
    <div id="unverified_garages-tab" class="tab-content <?php echo $activeTab === 'unverified_garages' ? 'active' : ''; ?>">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-white">Unverified Garages</h2>
        </div>
        
        <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Owner</th>
                            <th>Owner Status</th>
                            <th>Garage Status</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Price/Hour</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $unverifiedGarages = getUnverifiedGarages($conn);
                        foreach ($unverifiedGarages as $garage): 
                            // Get owner verification status
                            $ownerVerified = false;
                            $ownerQuery = "SELECT is_verified FROM garage_owners WHERE username = ?";
                            $stmt = $conn->prepare($ownerQuery);
                            $stmt->bind_param("s", $garage['username']);
                            $stmt->execute();
                            $ownerResult = $stmt->get_result();
                            if ($ownerResult && $ownerResult->num_rows > 0) {
                                $ownerRow = $ownerResult->fetch_assoc();
                                $ownerVerified = $ownerRow['is_verified'] == 1;
                            }
                        ?>
                        <tr>
    <td><?php echo $garage['garage_id']; ?></td>
    <td><?php echo $garage['Parking_Space_Name']; ?></td>
    <td><?php echo $garage['Parking_Lot_Address']; ?></td>
    <td><?php echo $garage['username']; ?></td>
    <td>
        <?php if ($ownerVerified): ?>
            <span class="status-badge status-verified">Verified</span>
        <?php else: ?>
            <span class="status-badge status-unverified">Unverified</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($garage['is_verified'] == 1): ?>
            <span class="status-badge status-verified">Verified</span>
        <?php else: ?>
            <span class="status-badge status-unverified">Unverified</span>
        <?php endif; ?>
    </td>
    <td><?php echo $garage['Parking_Type']; ?></td>
    <td><?php echo $garage['Parking_Capacity']; ?></td>
    <td>৳<?php echo $garage['PriceperHour']; ?></td>
    <td>
        <div class="flex gap-2">
            <button class="btn btn-sm btn-outline btn-success" onclick="verifyGarage('<?php echo $garage['garage_id']; ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Verify
            </button>
            <button class="btn btn-sm btn-outline btn-info" onclick="viewGarageDetails('<?php echo $garage['garage_id']; ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </button>
        </div>
    </td>
</tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


                <!-- Bookings Tab -->
                <div id="bookings-tab" class="tab-content <?php echo $activeTab === 'bookings' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Booking Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="booking-search" placeholder="Search bookings..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                            <select id="booking-status-filter" class="select select-bordered bg-gray-700 text-white">
                                <option value="all">All Statuses</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Garage</th>
                                        <th>Vehicle</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $booking): 
                                        $bookingDate = date('d M Y', strtotime($booking['booking_date']));
                                        $bookingTime = date('h:i A', strtotime($booking['booking_time']));
                                        $vehicleInfo = $booking['make'] . ' ' . $booking['model'] . ' (' . $booking['color'] . ')';
                                        
                                        // Determine status class
                                        $statusClass = '';
                                        switch ($booking['status']) {
                                            case 'upcoming':
                                                $statusClass = 'status-upcoming';
                                                break;
                                            case 'active':
                                                $statusClass = 'status-active';
                                                break;
                                            case 'completed':
                                                $statusClass = 'status-completed';
                                                break;
                                            case 'cancelled':
                                                $statusClass = 'status-cancelled';
                                                break;
                                        }
                                        
                                        // Determine payment status class
                                        $paymentClass = '';
                                        switch ($booking['payment_status']) {
                                            case 'paid':
                                                $paymentClass = 'status-paid';
                                                break;
                                            case 'pending':
                                                $paymentClass = 'status-pending';
                                                break;
                                            case 'refunded':
                                                $paymentClass = 'status-refunded';
                                                break;
                                        }
                                    ?>
                                    <tr data-status="<?php echo $booking['status']; ?>">
                                        <td>#<?php echo $booking['id']; ?></td>
                                        <td><?php echo $booking['username']; ?></td>
                                        <td><?php echo $booking['Parking_Space_Name']; ?></td>
                                        <td><?php echo $vehicleInfo; ?></td>
                                        <td><?php echo $bookingDate; ?></td>
                                        <td><?php echo $bookingTime; ?></td>
                                        <td><?php echo $booking['duration']; ?> hours</td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($booking['status']); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $paymentClass; ?>"><?php echo ucfirst($booking['payment_status']); ?></span>
                                        </td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                                <?php if ($booking['status'] === 'upcoming' || $booking['status'] === 'active'): ?>
                                                <button class="btn btn-sm btn-outline btn-error" onclick="cancelBooking(<?php echo $booking['id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <line x1="15" y1="9" x2="9" y2="15"></line>
                                                        <line x1="9" y1="9" x2="15" y2="15"></line>
                                                    </svg>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Payments Tab -->
                <div id="payments-tab" class="tab-content <?php echo $activeTab === 'payments' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Payment Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="payment-search" placeholder="Search payments..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                            <select id="payment-status-filter" class="select select-bordered bg-gray-700 text-white">
                                <option value="all">All Statuses</option>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Transaction ID</th>
                                        <th>Booking ID</th>
                                        <th>User</th>
                                        <th>Garage</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($payments as $payment):
                                        // Only process payments that have a payment record
                                        if (!empty($payment['payment_id'])):
                                            $paymentDate = !empty($payment['payment_date']) ? date('d M Y h:i A', strtotime($payment['payment_date'])) : 'N/A';
                                            
                                            // Determine payment status class
                                            $paymentClass = '';
                                            switch ($payment['payment_status']) {
                                                case 'paid':
                                                    $paymentClass = 'status-paid';
                                                    break;
                                                case 'pending':
                                                    $paymentClass = 'status-pending';
                                                    break;
                                                case 'refunded':
                                                    $paymentClass = 'status-refunded';
                                                    break;
                                            }
                                    ?>
                                    <tr data-status="<?php echo $payment['payment_status']; ?>">
                                        <td>#<?php echo $payment['payment_id']; ?></td>
                                        <td><?php echo $payment['transaction_id']; ?></td>
                                        <td>#<?php echo $payment['booking_id']; ?></td>
                                        <td><?php echo $payment['username']; ?></td>
                                        <td><?php echo $payment['Parking_Space_Name']; ?></td>
                                        <td>৳<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $paymentClass; ?>"><?php echo ucfirst($payment['payment_status']); ?></span>
                                        </td>
                                        <td><?php echo $paymentDate; ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewPaymentDetails(<?php echo $payment['payment_id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                                <?php if ($payment['payment_status'] === 'paid'): ?>
                                                <button class="btn btn-sm btn-outline btn-warning hover:btn-warning" onclick="refundPayment(<?php echo $payment['payment_id']; ?>)" title="Refund Payment">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 2v6h6"></path>
        <path d="M21 12A9 9 0 0 0 6 5.3L3 8"></path>
        <path d="M21 22v-6h-6"></path>
        <path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"></path>
    </svg>
</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                        endif;
                                    endforeach;
                                    
                                    // Now show pending payments from bookings that don't have payment records
                                    foreach ($payments as $payment):
                                        if (empty($payment['payment_id']) && $payment['booking_payment_status'] === 'pending'):
                                            $paymentDate = !empty($payment['booking_updated_at']) ? date('d M Y h:i A', strtotime($payment['booking_updated_at'])) : 'N/A';
                                    ?>
                                    <tr data-status="pending">
                                        <td>N/A</td>
                                        <td>N/A</td>
                                        <td>#<?php echo $payment['booking_id']; ?></td>
                                        <td><?php echo $payment['username']; ?></td>
                                        <td><?php echo $payment['Parking_Space_Name']; ?></td>
                                        <td>৳<?php echo number_format($payment['effective_amount'], 2); ?></td>
                                        <td>Not paid</td>
                                        <td>
                                            <span class="status-badge status-pending">Pending</span>
                                        </td>
                                        <td><?php echo $paymentDate; ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewBookingDetails(<?php echo $payment['booking_id']; ?>)">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Vehicles Tab -->
                <div id="vehicles-tab" class="tab-content <?php echo $activeTab === 'vehicles' ? 'active' : ''; ?>">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-white">Vehicle Management</h2>
                        <div class="flex gap-4">
                            <div class="relative">
                                <input type="text" id="vehicle-search" placeholder="Search vehicles..." class="input input-bordered bg-gray-700 text-white w-64">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 absolute right-3 top-3 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
                        <div class="data-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>License Plate</th>
                                        <th>Owner</th>
                                        <th>Owner Name</th>
                                        <th>Type</th>
                                        <th>Make</th>
                                        <th>Model</th>
                                        <th>Color</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                    <tr>
                                        <td><?php echo $vehicle['licensePlate']; ?></td>
                                        <td><?php echo $vehicle['username']; ?></td>
                                        <td><?php echo $vehicle['firstName'] . ' ' . $vehicle['lastName']; ?></td>
                                        <td><?php echo ucfirst($vehicle['vehicleType']); ?></td>
                                        <td><?php echo $vehicle['make']; ?></td>
                                        <td><?php echo $vehicle['model']; ?></td>
                                        <td><?php echo ucfirst($vehicle['color']); ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <button class="btn btn-sm btn-outline btn-info" onclick="viewVehicleDetails('<?php echo $vehicle['licensePlate']; ?>')">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="11" cy="11" r="8"></circle>
                                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Revenue & Profit Tab -->
<div id="revenue-tab" class="tab-content <?php echo $activeTab === 'revenue' ? 'active' : ''; ?>">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-white">Revenue & Profit Analytics</h2>
        <div class="flex gap-4">
            <!-- Time Period Selector -->
            <select id="revenue-period-filter" class="select select-bordered bg-gray-700 text-white">
                <option value="today">Today</option>
                <option value="last_7_days" selected>Last 7 Days</option>
                <option value="last_30_days">Last 30 Days</option>
                <option value="this_month">This Month</option>
                <option value="this_year">This Year</option>
            </select>
            <button class="btn btn-primary" onclick="exportRevenueReport()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                </svg>
                Export Report
            </button>
        </div>
    </div>
    
    <!-- Revenue Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
        <!-- Total Revenue Card -->
        <div class="bg-gradient-to-br from-blue-500/20 to-blue-600/20 border border-blue-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-blue-300 text-xs lg:text-sm mb-1">Total Revenue</p>
                    <h3 id="total-revenue" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="revenue-change" class="text-xs text-blue-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Platform Profit Card -->
        <div class="bg-gradient-to-br from-emerald-500/20 to-emerald-600/20 border border-emerald-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-emerald-300 text-xs lg:text-sm mb-1">Platform Profit</p>
                    <h3 id="platform-profit" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="profit-margin" class="text-xs text-emerald-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-emerald-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 1 8 0 4 4 0 0 1-8 0M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Owner Earnings Card -->
        <div class="bg-gradient-to-br from-purple-500/20 to-purple-600/20 border border-purple-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-purple-300 text-xs lg:text-sm mb-1">Owner Earnings</p>
                    <h3 id="owner-earnings" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="owner-percentage" class="text-xs text-purple-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-purple-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM7 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- Pending Revenue Card -->
        <div class="bg-gradient-to-br from-amber-500/20 to-amber-600/20 border border-amber-500/30 rounded-lg p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <p class="text-amber-300 text-xs lg:text-sm mb-1">Pending Revenue</p>
                    <h3 id="pending-revenue" class="text-xl lg:text-2xl font-bold text-white">৳0</h3>
                    <p id="pending-count" class="text-xs text-amber-400 mt-1">Loading...</p>
                </div>
                <div class="w-10 h-10 lg:w-12 lg:h-12 bg-amber-500/20 rounded-full flex items-center justify-center ml-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 lg:h-6 lg:w-6 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12,6 12,12 16,14"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Revenue Trends Chart -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h4 class="text-lg font-semibold text-white mb-4">Revenue Trends</h4>
            <div class="h-64">
                <canvas id="revenueTrendsChart"></canvas>
            </div>
        </div>
        
        <!-- Payment Methods Chart -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h4 class="text-lg font-semibold text-white mb-4">Revenue by Payment Method</h4>
            <div class="h-64">
                <canvas id="paymentMethodsChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Top Revenue Garages Table -->
    <div class="bg-gray-800 rounded-lg p-6">
        <h4 class="text-lg font-semibold text-white mb-4">Top Revenue Generating Garages</h4>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-700">
                        <th class="text-left py-3 text-gray-300">Garage</th>
                        <th class="text-left py-3 text-gray-300">Owner</th>
                        <th class="text-right py-3 text-gray-300">Revenue</th>
                        <th class="text-right py-3 text-gray-300">Profit</th>
                        <th class="text-right py-3 text-gray-300">Bookings</th>
                        <th class="text-right py-3 text-gray-300">Avg/Booking</th>
                    </tr>
                </thead>
                <tbody id="top-garages-table">
                    <tr>
                        <td colspan="6" class="text-center py-8 text-white/60">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Edit Garage Modal -->
    <div id="editGarageModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 class="text-xl font-bold text-white mb-4">Edit Garage Price</h3>
            <form id="editGarageForm">
                <input type="hidden" id="edit_garage_id" name="garage_id">
                <input type="hidden" name="action" value="update_garage">
                
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text text-white">Price per Hour (৳)</span>
                    </label>
                    <input type="number" id="edit_price" name="price" class="input input-bordered bg-gray-700 text-white" min="0" step="0.01" required>
                </div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeEditGarageModal()">Cancel</button>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="viewLocationModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white" id="location_title">Garage Location</h3>
            <button onclick="closeLocationModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="mapContainer" style="height: 400px; width: 100%; border-radius: 0.5rem;"></div>
        <div class="flex justify-end mt-4">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeLocationModal()">Close</button>
        </div>
    </div>
</div>
    
    <!-- Add/Edit User Modal -->
    <div id="userModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white" id="userModalTitle">Add New User</h3>
                <button onclick="closeUserModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form id="userForm">
                <input type="hidden" id="user_action" name="action" value="add_user">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Username</span>
                        </label>
                        <input type="text" id="username" name="username" class="input input-bordered bg-gray-700 text-white" required>
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Password</span>
                        </label>
                        <input type="password" id="password" name="password" class="input input-bordered bg-gray-700 text-white" required>
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">First Name</span>
                        </label>
                        <input type="text" id="firstName" name="firstName" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Last Name</span>
                        </label>
                        <input type="text" id="lastName" name="lastName" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Email</span>
                        </label>
                        <input type="email" id="email" name="email" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text text-white">Phone</span>
                        </label>
                        <input type="text" id="phone" name="phone" class="input input-bordered bg-gray-700 text-white">
                    </div>
                    
                    <div class="form-control md:col-span-2">
                        <label class="label">
                            <span class="label-text text-white">Address</span>
                        </label>
                        <input type="text" id="address" name="address" class="input input-bordered bg-gray-700 text-white">
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeUserModal()">Cancel</button>
                    <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Garage Owner Details Modal -->
    <div id="ownerDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Garage Owner Details</h3>
                <button onclick="closeOwnerDetailsModal()" class="text-white/70 hover:text-white">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
</button>
            </div>
            
            <div id="ownerDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6">
                <button class="btn btn-outline border-white/20 text-white" onclick="closeOwnerDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Booking Details Modal -->
    <div id="bookingDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Booking Details</h3>
                <button onclick="closeBookingDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="bookingDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6 gap-3">
                <div id="bookingActionButtons" class="hidden">
                    <!-- Action buttons will be added dynamically -->
                </div>
                <button class="btn btn-outline border-white/20 text-white" onclick="closeBookingDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Payment Details Modal -->
    <div id="paymentDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Payment Details</h3>
                <button onclick="closePaymentDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="paymentDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6 gap-3">
                <div id="paymentActionButtons" class="hidden">
                    <!-- Action buttons will be added dynamically -->
                </div>
                <button class="btn btn-outline border-white/20 text-white" onclick="closePaymentDetailsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- View Vehicle Details Modal -->
    <div id="vehicleDetailsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Vehicle Details</h3>
                <button onclick="closeVehicleDetailsModal()" class="text-white/70 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <div id="vehicleDetailsContent" class="text-white">
                <!-- Content will be loaded dynamically -->
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            </div>
            
            <div class="flex justify-end mt-6">
                <button class="btn btn-outline border-white/20 text-white" onclick="closeVehicleDetailsModal()">Close</button>
            </div>
        </div>
    </div>
     <!-- Garage Reviews Modal -->
<div id="garageReviewsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white" id="reviewsModalTitle">Garage Reviews</h3>
            <button onclick="closeGarageReviewsModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="garageReviewsContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            </div>
        </div>
        
        <div class="flex justify-end mt-6">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeGarageReviewsModal()">Close</button>
        </div>
    </div>
</div>                                   
    <!-- Footer -->
    <footer class="bg-gray-800 py-6">
        <div class="container mx-auto px-4 text-center">
            <p class="text-white/70">&copy; <?php echo date('Y'); ?> Car Parking System. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
    // Global variables for map
let leafletMap = null;
let leafletMarker = null;

// Function to view garage location on map
function viewGarageLocation(lat, lng, name) {
    console.log("Button clicked with:", lat, lng, name);
    
    // Show the modal first
    document.getElementById('viewLocationModal').classList.remove('hidden');
    
    // Convert coordinates to numbers
    lat = parseFloat(lat);
    lng = parseFloat(lng);
    
    if (isNaN(lat) || isNaN(lng)) {
        console.error("Invalid coordinates:", lat, lng);
        return;
    }
    
    // Initialize map after a short delay to ensure modal is visible
    setTimeout(function() {
        try {
            // Create map if not exists or reset
            if (window.leafletMap) {
                window.leafletMap.remove();
            }
            
            // Initialize map
            window.leafletMap = L.map('mapContainer').setView([lat, lng], 15);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(window.leafletMap);
            
            // Add marker
            L.marker([lat, lng]).addTo(window.leafletMap)
                .bindPopup("<b>" + name + "</b><br>Location: " + lat.toFixed(6) + ", " + lng.toFixed(6))
                .openPopup();
            
            console.log("Map initialized successfully");
        } catch (error) {
            console.error("Error initializing map:", error);
        }
    }, 300);
}

// Close location modal
function closeLocationModal() {
    document.getElementById('viewLocationModal').classList.add('hidden');
}
        
        // Function to show tab content based on URL parameter
        function showTabContent() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'dashboard';
            
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tab + '-tab').classList.add('active');
        }
        
        // Call showTabContent on page load
        document.addEventListener('DOMContentLoaded', function() {
            showTabContent();
            
            // Initialize charts for dashboard
            if (document.getElementById('bookingStatusChart')) {
                initBookingStatusChart();
            }
            
            if (document.getElementById('revenueChart')) {
                initRevenueChart();
            }
            if (document.getElementById('revenueProfitChart')) {
        initRevenueProfitChart();
    }
            // Add event listeners for search inputs
            document.querySelectorAll('input[id$="-search"]').forEach(input => {
                input.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const tableId = this.id.split('-')[0];
                    
                    document.querySelectorAll(`#${tableId}-tab table tbody tr`).forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            });
            
            // Add event listeners for status filters
            document.querySelectorAll('select[id$="-status-filter"]').forEach(select => {
                select.addEventListener('change', function() {
                    const status = this.value;
                    const tableId = this.id.split('-')[0];
                    
                    document.querySelectorAll(`#${tableId}-tab table tbody tr`).forEach(row => {
                        if (status === 'all') {
                            row.style.display = '';
                        } else {
                            const rowStatus = row.getAttribute('data-status');
                            row.style.display = rowStatus === status ? '' : 'none';
                        }
                    });
                });
            });
            
            // Add event listener for edit garage form
            document.getElementById('editGarageForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeEditGarageModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
            
            // Add event listener for user form
            document.getElementById('userForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeUserModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
        });
        
        // Function to initialize booking status chart
        function initBookingStatusChart() {
            const ctx = document.getElementById('bookingStatusChart').getContext('2d');
            
            // Sample data - in a real application, you would get this from the server
            const data = {
                labels: ['Upcoming', 'Active', 'Completed', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php 
                        $upcomingCount = 0;
                        $activeCount = 0;
                        $completedCount = 0;
                        $cancelledCount = 0;
                        
                        foreach ($bookings as $booking) {
                            switch ($booking['status']) {
                                case 'upcoming':
                                    $upcomingCount++;
                                    break;
                                case 'active':
                                    $activeCount++;
                                    break;
                                case 'completed':
                                    $completedCount++;
                                    break;
                                case 'cancelled':
                                    $cancelledCount++;
                                    break;
                            }
                        }
                        
                        echo $upcomingCount . ', ' . $activeCount . ', ' . $completedCount . ', ' . $cancelledCount;
                        ?>
                    ],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(243, 156, 18, 0.7)',
                        'rgba(239, 68, 68, 0.7)'
                    ],
                    borderColor: [
                        'rgba(59, 130, 246, 1)',
                        'rgba(16, 185, 129, 1)',
                        'rgba(243, 156, 18, 1)',
                        'rgba(239, 68, 68, 1)'
                    ],
                    borderWidth: 1
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: 'white'
                            }
                        }
                    }
                }
            });
        }
        
        // Function to initialize revenue chart
        function initRevenueChart() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            // Sample data - in a real application, you would get this from the server
            const data = {
                labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Today'],
                datasets: [{
                    label: 'Revenue',
                    data: [150, 200, 175, 300, 225, 250, <?php echo $stats['today_revenue']; ?>],
                    backgroundColor: 'rgba(243, 156, 18, 0.2)',
                    borderColor: 'rgba(243, 156, 18, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            };
            
            new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: 'white'
                            }
                        }
                    }
                }
            });
        }
        
        // Function to edit garage price
        function editGarage(garageId, price) {
            document.getElementById('edit_garage_id').value = garageId;
            document.getElementById('edit_price').value = price;
            document.getElementById('editGarageModal').classList.remove('hidden');
        }
        
        // Function to close edit garage modal
        function closeEditGarageModal() {
            document.getElementById('editGarageModal').classList.add('hidden');
        }
        
        
        
        // Function to delete user
        function deleteUser(username) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('username', username);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Function to verify garage owner
        function verifyOwner(ownerId) {
    if (confirm('Are you sure you want to verify this garage owner?')) {
        const formData = new FormData();
        formData.append('action', 'verify_owner');
        formData.append('owner_id', ownerId);
        
        // Check if it's a user-owner that needs registration
        if (ownerId.startsWith('U_owner_')) {
            // Extract username from the user-owner ID
            const username = ownerId.replace('U_owner_', '');
            formData.append('username', username);
            formData.append('register_first', 'true');
        }
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}


function verifyGarage(garageId) {
    console.log("Verifying garage:", garageId); // ডিবাগিং লগ
    
    if (confirm('Are you sure you want to verify this garage?')) {
        const formData = new FormData();
        formData.append('action', 'verify_garage');
        formData.append('garage_id', garageId);
        
        // আরেকটি ডিবাগিং লগ
        console.log("Sending AJAX request with:", formData.get('action'), formData.get('garage_id'));
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log("Raw response:", response); // ডিবাগিং লগ
            return response.json();
        })
        .then(data => {
            console.log("Response data:", data); // ডিবাগিং লগ
            if (data.success) {
                alert(data.message);
                window.location.reload(); // পেজ রিলোড
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please check console for details.');
        });
    }
}
        
        // Function to register user as an official garage owner
function registerAsOwner(username) {
    if (confirm('আপনি কি নিশ্চিত যে আপনি এই ইউজারকে অফিসিয়াল গ্যারেজ ওনার হিসেবে রেজিস্টার করতে চান?')) {
        const formData = new FormData();
        formData.append('action', 'register_as_owner');
        formData.append('username', username);
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            // Return the text first to see what's wrong
            return response.text();
        })
        .then(text => {
            console.log('Raw response from server:', text);
            // Now try to parse it as JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                alert('Server response is not valid JSON. Check console for details.');
                throw e;
            }
        })
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error details:', error);
            alert('একটি ত্রুটি ঘটেছে। অনুগ্রহ করে আবার চেষ্টা করুন। Details: ' + error.message);
        });
    }
}
        
        // Function to cancel booking
        function cancelBooking(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                const formData = new FormData();
                formData.append('action', 'cancel_booking');
                formData.append('booking_id', bookingId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Function to refund payment
        function refundPayment(paymentId) {
            if (confirm('Are you sure you want to refund this payment?')) {
                const formData = new FormData();
                formData.append('action', 'refund_payment');
                formData.append('payment_id', paymentId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for user management
        function openAddUserModal() {
            // Reset the form
            document.getElementById('userForm').reset();
            document.getElementById('user_action').value = 'add_user';
            document.getElementById('userModalTitle').textContent = 'Add New User';
            
            // Enable username field for new users
            document.getElementById('username').removeAttribute('readonly');
            
            // Show the modal
            document.getElementById('userModal').classList.remove('hidden');
        }
        
        function closeUserModal() {
            document.getElementById('userModal').classList.add('hidden');
        }
        
        function editUser(username) {
            // Set action to update
            document.getElementById('user_action').value = 'update_user';
            document.getElementById('userModalTitle').textContent = 'Edit User';
            
            // Disable username field for existing users
            document.getElementById('username').setAttribute('readonly', 'readonly');
            
            // Get user data
            const formData = new FormData();
            formData.append('action', 'get_user');
            formData.append('username', username);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Fill the form with user data
                    const user = data.data;
                    document.getElementById('username').value = user.username;
                    document.getElementById('password').value = user.password;
                    document.getElementById('firstName').value = user.firstName || '';
                    document.getElementById('lastName').value = user.lastName || '';
                    document.getElementById('email').value = user.email || '';
                    document.getElementById('phone').value = user.phone || '';
                    document.getElementById('address').value = user.address || '';
                    
                    // Show the modal
                    document.getElementById('userModal').classList.remove('hidden');
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
        
        // Functions for garage owner details
function viewOwnerDetails(ownerId) {
    const formData = new FormData();
    formData.append('action', 'get_owner');
    formData.append('owner_id', ownerId);
    
    // Show loading state
    document.getElementById('ownerDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
        </div>
    `;
    
    // Show the modal
    document.getElementById('ownerDetailsModal').classList.remove('hidden');
    
    // First, get commission rate
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_commission_rate&owner_id=${ownerId}`
    })
    .then(response => response.json())
    .then(commissionData => {
        // Default commission rate is 30% if not set
        const commissionRate = commissionData.success ? commissionData.rate : 30;
        
        // Now get owner details
        return fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const owner = data.data;
                const verifiedBadge = owner.is_verified ? 
                    `<span class="status-badge status-verified"></span>` : 
                    `<span class="status-badge status-unverified"></span>`;
                
                let garageListing = '';
                if (owner.garages && owner.garages.length > 0) {
                    garageListing = `
                        <div class="detail-section mt-6">
                            <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr>
                                            <th class="text-left px-4 py-2 bg-gray-700">Name</th>
                                            <th class="text-left px-4 py-2 bg-gray-700">Address</th>
                                            <th class="text-left px-4 py-2 bg-gray-700">Type</th>
                                            <th class="text-left px-4 py-2 bg-gray-700">Capacity</th>
                                            <th class="text-left px-4 py-2 bg-gray-700">Price/Hour</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    owner.garages.forEach(garage => {
                        garageListing += `
                            <tr>
                                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Space_Name}</td>
                                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Lot_Address}</td>
                                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Type}</td>
                                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Capacity}</td>
                                <td class="px-4 py-2 border-t border-gray-700">৳${garage.PriceperHour}</td>
                            </tr>
                        `;
                    });
                    
                    garageListing += `
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                } else {
                    garageListing = `
                        <div class="detail-section mt-6">
                            <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                            <p class="text-white/70">No garages registered yet.</p>
                        </div>
                    `;
                }
                
                const registrationDate = new Date(owner.registration_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                const lastLogin = owner.last_login ? new Date(owner.last_login).toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : 'Never';
                
                // Commission section
                const commissionSection = `
                    <div class="detail-section mt-6">
                        <h4 class="text-lg font-semibold text-primary mb-3">Commission Settings</h4>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/70 text-sm">Current Commission Rate</p>
                                    <p class="text-2xl font-bold text-white mt-1">${commissionRate}%</p>
                                </div>
                                <button class="btn btn-primary" onclick="openCommissionModal('${owner.owner_id}', ${commissionRate})">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                    Update Commission Rate
                                </button>
                            </div>
                            <p class="mt-2 text-sm text-white/60">
                                Commission rate determines the percentage of booking revenue the owner receives. The remaining amount goes to the platform.
                            </p>
                        </div>
                    </div>
                `;
                
                // Create HTML for owner details
                const ownerDetailsHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="detail-section">
                            <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="detail-item">
                                    <p class="detail-label">Owner ID</p>
                                    <p class="detail-value">${owner.owner_id}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Username</p>
                                    <p class="detail-value">${owner.username}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Status</p>
                                    <p class="detail-value">${verifiedBadge}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Account Status</p>
                                    <p class="detail-value capitalize">${owner.account_status}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Registration Date</p>
                                    <p class="detail-value">${registrationDate}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Last Login</p>
                                    <p class="detail-value">${lastLogin}</p>
                                </div>
                            </div>
                            
                            <!-- Add action buttons -->
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button class="btn btn-sm btn-warning" onclick="openMessageModal('${owner.owner_id}')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                        <polyline points="22,6 12,13 2,6"></polyline>
                                    </svg>
                                    Send Message
                                </button>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h4 class="text-lg font-semibold text-primary mb-3">Personal Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="detail-item">
                                    <p class="detail-label">Name</p>
                                    <p class="detail-value">${owner.firstName} ${owner.lastName}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Email</p>
                                    <p class="detail-value">${owner.email || 'Not provided'}</p>
                                </div>
                                <div class="detail-item">
                                    <p class="detail-label">Phone</p>
                                    <p class="detail-value">${owner.phone || 'Not provided'}</p>
                                </div>
                                <div class="detail-item md:col-span-2">
                                    <p class="detail-label">Address</p>
                                    <p class="detail-value">${owner.address || 'Not provided'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    ${commissionSection}
                    
                    ${garageListing}
                `;
                
                document.getElementById('ownerDetailsContent').innerHTML = ownerDetailsHTML;
            } else {
                document.getElementById('ownerDetailsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p>${data.message || 'An error occurred while fetching owner details. Please try again.'}</p>
                    </div>
                `;
            }
        });
    })
    .catch(error => {
        console.error('Error details:', error);
        document.getElementById('ownerDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p>An error occurred while fetching owner details. Please try again.</p>
                <p class="mt-2 text-sm">Error details: ${error.message}</p>
            </div>
        `;
    });
}

// Helper functions for owner details tabs
function generateOwnerProfileHTML(owner) {
    const verifiedBadge = owner.is_verified ? 
        `<span class="status-badge status-verified"></span>` : 
        `<span class="status-badge status-unverified"></span>`;
        
    const registrationDate = new Date(owner.registration_date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const lastLogin = owner.last_login ? new Date(owner.last_login).toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }) : 'Never';
    
    return `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="detail-item">
                        <p class="detail-label">Owner ID</p>
                        <p class="detail-value">${owner.owner_id}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Username</p>
                        <p class="detail-value">${owner.username}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Status</p>
                        <p class="detail-value">${verifiedBadge}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Account Status</p>
                        <p class="detail-value capitalize">${owner.account_status}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Registration Date</p>
                        <p class="detail-value">${registrationDate}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Last Login</p>
                        <p class="detail-value">${lastLogin}</p>
                    </div>
                </div>
                
                <!-- Add actions buttons -->
                <div class="mt-4 flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" onclick="resetOwnerPassword('${owner.owner_id}')">
                        Reset Password
                    </button>
                    <button class="btn btn-sm btn-info" onclick="openMessageModal('${owner.owner_id}')">
                        Send Message
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="openCommissionModal('${owner.owner_id}', 10)">
                        Set Commission
                    </button>
                    <div class="form-control mt-2">
                        <label class="label cursor-pointer flex justify-start gap-2">
                            <input type="checkbox" class="toggle toggle-success" onchange="updateFeaturedStatus('${owner.owner_id}', this.checked)" ${owner.is_featured ? 'checked' : ''}>
                            <span class="label-text text-white">Featured Owner</span>
                        </label>
                    </div>
                    <button class="btn btn-sm btn-error mt-2" onclick="openDeleteOwnerModal('${owner.owner_id}')">
                        Delete Account
                    </button>
                </div>
            </div>
            
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Personal Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="detail-item">
                        <p class="detail-label">Name</p>
                        <p class="detail-value">${owner.firstName} ${owner.lastName}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Email</p>
                        <p class="detail-value">${owner.email || 'Not provided'}</p>
                    </div>
                    <div class="detail-item">
                        <p class="detail-label">Phone</p>
                        <p class="detail-value">${owner.phone || 'Not provided'}</p>
                    </div>
                    <div class="detail-item md:col-span-2">
                        <p class="detail-label">Address</p>
                        <p class="detail-value">${owner.address || 'Not provided'}</p>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function generateOwnerGaragesHTML(owner) {
    if (!owner.garages || owner.garages.length === 0) {
        return `
            <div class="detail-section">
                <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                <p class="text-white/70">No garages registered yet.</p>
            </div>
        `;
    }
    
    let garageListing = `
        <div class="detail-section">
            <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-2 bg-gray-700">Name</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Address</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Type</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Capacity</th>
                            <th class="text-left px-4 py-2 bg-gray-700">Price/Hour</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    owner.garages.forEach(garage => {
        garageListing += `
            <tr>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Space_Name}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Lot_Address}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Type}</td>
                <td class="px-4 py-2 border-t border-gray-700">${garage.Parking_Capacity}</td>
                <td class="px-4 py-2 border-t border-gray-700">৳${garage.PriceperHour}</td>
            </tr>
        `;
    });
    
    garageListing += `
                    </tbody>
                </table>
            </div>
        </div>
    `;
    
    return garageListing;
}

function showOwnerTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.owner-tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Deactivate all tab buttons
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('tab-active');
    });
    
    // Show selected tab
    document.getElementById(`${tabName}-tab`).classList.remove('hidden');
    
    // Activate selected tab button
    document.querySelector(`.tab[onclick="showOwnerTab('${tabName}')"]`).classList.add('tab-active');
}
        
        // Functions for booking details
        function viewBookingDetails(bookingId) {
            const formData = new FormData();
            formData.append('action', 'get_booking');
            formData.append('booking_id', bookingId);
            
            // Show loading state
            document.getElementById('bookingDetailsContent').innerHTML = `
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            `;
            
            // Hide action buttons initially
            document.getElementById('bookingActionButtons').classList.add('hidden');
            
            // Show the modal
            document.getElementById('bookingDetailsModal').classList.remove('hidden');
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const booking = data.data;
                    
                    // Determine status classes
                    let statusClass = '';
                    switch (booking.status) {
                        case 'upcoming':
                            statusClass = 'status-upcoming';
                            break;
                        case 'active':
                            statusClass = 'status-active';
                            break;
                        case 'completed':
                            statusClass = 'status-completed';
                            break;
                        case 'cancelled':
                            statusClass = 'status-cancelled';
                            break;
                    }
                    
                    let paymentClass = '';
                    switch (booking.payment_status) {
                        case 'paid':
                            paymentClass = 'status-paid';
                            break;
                        case 'pending':
                            paymentClass = 'status-pending';
                            break;
                        case 'refunded':
                            paymentClass = 'status-refunded';
                            break;
                    }
                    
                    // Format dates
                    const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    const bookingTime = new Date(`${booking.booking_date}T${booking.booking_time}`).toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    const createdAt = new Date(booking.created_at).toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    const updatedAt = new Date(booking.updated_at).toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Calculate total amount
                    const totalAmount = parseFloat(booking.PriceperHour) * parseInt(booking.duration);
                    
                    // Payment information
                    let paymentInfo = '';
                    if (booking.payment) {
                        paymentInfo = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Payment Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="detail-item">
                                        <p class="detail-label">Transaction ID</p>
                                        <p class="detail-value">${booking.payment.transaction_id}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Amount</p>
                                        <p class="detail-value">৳${parseFloat(booking.payment.amount).toFixed(2)}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Payment Method</p>
                                        <p class="detail-value capitalize">${booking.payment.payment_method}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Payment Date</p>
                                        <p class="detail-value">${new Date(booking.payment.payment_date).toLocaleString('en-US', {
                                            year: 'numeric',
                                            month: 'long',
                                            day: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}</p>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        paymentInfo = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Payment Information</h4>
                                <p class="text-white/70">No payment record found.</p>
                            </div>
                        `;
                    }
                    
                    // Create HTML for booking details
                    const bookingDetailsHTML = `
                        <div class="grid grid-cols-1 gap-6">
                            <div class="flex flex-wrap gap-4 justify-between items-center">
                                <div>
                                    <h3 class="text-xl font-bold">Booking #${booking.id}</h3>
                                    <p class="text-white/70 text-sm">Created: ${createdAt}</p>
                                </div>
                                <div class="flex gap-3">
                                    <span class="status-badge ${statusClass}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>
                                    <span class="status-badge ${paymentClass}">${booking.payment_status.charAt(0).toUpperCase() + booking.payment_status.slice(1)}</span>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Booking Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Date</p>
                                            <p class="detail-value">${bookingDate}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Time</p>
                                            <p class="detail-value">${bookingTime}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Duration</p>
                                            <p class="detail-value">${booking.duration} hours</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Price per Hour</p>
                                            <p class="detail-value">৳${parseFloat(booking.PriceperHour).toFixed(2)}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Total Amount</p>
                                            <p class="detail-value">৳${totalAmount.toFixed(2)}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Last Updated</p>
                                            <p class="detail-value">${updatedAt}</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">User Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Username</p>
                                            <p class="detail-value">${booking.username}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Name</p>
                                            <p class="detail-value">${booking.firstName} ${booking.lastName}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value">${booking.email || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value">${booking.phone || 'Not provided'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Garage Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item md:col-span-2">
                                            <p class="detail-label">Name</p>
                                            <p class="detail-value">${booking.Parking_Space_Name}</p>
                                        </div>
                                        <div class="detail-item md:col-span-2">
                                            <p class="detail-label">Address</p>
                                            <p class="detail-value">${booking.Parking_Lot_Address}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Garage ID</p>
                                            <p class="detail-value">${booking.garage_id}</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Vehicle Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">License Plate</p>
                                            <p class="detail-value">${booking.licenseplate}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Type</p>
                                            <p class="detail-value capitalize">${booking.vehicleType || 'Not specified'}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Make & Model</p>
                                            <p class="detail-value">${booking.make} ${booking.model}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Color</p>
                                            <p class="detail-value capitalize">${booking.color}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            ${paymentInfo}
                        </div>
                    `;
                    
                    document.getElementById('bookingDetailsContent').innerHTML = bookingDetailsHTML;
                    
                    // Show action buttons if booking is upcoming or active
                    if (booking.status === 'upcoming' || booking.status === 'active') {
                        document.getElementById('bookingActionButtons').innerHTML = `
                            <button class="btn btn-error" onclick="cancelBookingFromDetails(${booking.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                                Cancel Booking
                            </button>
                        `;
                        document.getElementById('bookingActionButtons').classList.remove('hidden');
                    }
                } else {
                    document.getElementById('bookingDetailsContent').innerHTML = `
                        <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                            <p>${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('bookingDetailsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p>An error occurred while fetching booking details. Please try again.</p>
                    </div>
                `;
            });
        }
        
        function closeBookingDetailsModal() {
            document.getElementById('bookingDetailsModal').classList.add('hidden');
        }
        
        function cancelBookingFromDetails(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                const formData = new FormData();
                formData.append('action', 'cancel_booking');
                formData.append('booking_id', bookingId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closeBookingDetailsModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for payment details
        function viewPaymentDetails(paymentId) {
            const formData = new FormData();
            formData.append('action', 'get_payment');
            formData.append('payment_id', paymentId);
            
            // Show loading state
            document.getElementById('paymentDetailsContent').innerHTML = `
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            `;
            
            // Hide action buttons initially
            document.getElementById('paymentActionButtons').classList.add('hidden');
            
            // Show the modal
            document.getElementById('paymentDetailsModal').classList.remove('hidden');
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const payment = data.data;
                    
                    // Determine payment status class
                    let paymentClass = '';
                    switch (payment.payment_status) {
                        case 'paid':
                            paymentClass = 'status-paid';
                            break;
                        case 'pending':
                            paymentClass = 'status-pending';
                            break;
                        case 'refunded':
                            paymentClass = 'status-refunded';
                            break;
                    }
                    
                    // Format dates
                    const paymentDate = new Date(payment.payment_date).toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    const bookingDate = new Date(payment.booking_date).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    const bookingTime = new Date(`${payment.booking_date}T${payment.booking_time}`).toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Create HTML for payment details
                    const paymentDetailsHTML = `
                        <div class="grid grid-cols-1 gap-6">
                            <div class="flex flex-wrap gap-4 justify-between items-center">
                                <div>
                                    <h3 class="text-xl font-bold">Payment #${payment.payment_id}</h3>
                                    <p class="text-white/70 text-sm">Transaction ID: ${payment.transaction_id}</p>
                                </div>
                                <div>
                                    <span class="status-badge ${paymentClass}">${payment.payment_status.charAt(0).toUpperCase() + payment.payment_status.slice(1)}</span>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Payment Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Amount</p>
                                            <p class="detail-value">৳${parseFloat(payment.amount).toFixed(2)}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Payment Method</p>
                                            <p class="detail-value capitalize">${payment.payment_method}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Payment Date</p>
                                            <p class="detail-value">${paymentDate}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Booking ID</p>
                                            <p class="detail-value">#${payment.booking_id}</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">User Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Username</p>
                                            <p class="detail-value">${payment.username}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Name</p>
                                            <p class="detail-value">${payment.firstName} ${payment.lastName}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value">${payment.email || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value">${payment.phone || 'Not provided'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4 class="text-lg font-semibold text-primary mb-3">Booking Information</h4>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="detail-item">
                                        <p class="detail-label">Booking Date</p>
                                        <p class="detail-value">${bookingDate}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Booking Time</p>
                                        <p class="detail-value">${bookingTime}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Duration</p>
                                        <p class="detail-value">${payment.duration} hours</p>
                                    </div>
                                    <div class="detail-item md:col-span-2">
                                        <p class="detail-label">Garage</p>
                                        <p class="detail-value">${payment.Parking_Space_Name}</p>
                                    </div>
                                    <div class="detail-item">
                                        <p class="detail-label">Booking Status</p>
                                        <p class="detail-value capitalize">${payment.status}</p>
                                    </div>
                                    <div class="detail-item md:col-span-3">
                                        <p class="detail-label">Garage Address</p>
                                        <p class="detail-value">${payment.Parking_Lot_Address}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('paymentDetailsContent').innerHTML = paymentDetailsHTML;
                    
                    // Show action buttons if payment is paid
                    if (payment.payment_status === 'paid') {
                        document.getElementById('paymentActionButtons').innerHTML = `
                            <button class="btn btn-warning" onclick="refundPaymentFromDetails(${payment.payment_id})">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                                Refund Payment
                            </button>
                        `;
                        document.getElementById('paymentActionButtons').classList.remove('hidden');
                    }
                } else {
                    document.getElementById('paymentDetailsContent').innerHTML = `
                        <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                            <p>${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('paymentDetailsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p>An error occurred while fetching payment details. Please try again.</p>
                    </div>
                `;
            });
        }
        
        function closePaymentDetailsModal() {
            document.getElementById('paymentDetailsModal').classList.add('hidden');
        }
        
        function refundPaymentFromDetails(paymentId) {
            if (confirm('Are you sure you want to refund this payment?')) {
                const formData = new FormData();
                formData.append('action', 'refund_payment');
                formData.append('payment_id', paymentId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        closePaymentDetailsModal();
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Functions for vehicle details
        function viewVehicleDetails(licensePlate) {
            const formData = new FormData();
            formData.append('action', 'get_vehicle');
            formData.append('license_plate', licensePlate);
            
            // Show loading state
            document.getElementById('vehicleDetailsContent').innerHTML = `
                <div class="flex justify-center items-center h-40">
                    <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                </div>
            `;
            
            // Show the modal
            document.getElementById('vehicleDetailsModal').classList.remove('hidden');
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const vehicle = data.data;
                    
                    // Booking history table
                    let bookingHistoryHTML = '';
                    if (vehicle.booking_history && vehicle.booking_history.length > 0) {
                        bookingHistoryHTML = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Booking History</h4>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr>
                                                <th class="text-left px-4 py-2 bg-gray-700">Booking ID</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Date</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Time</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Duration</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Garage</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Status</th>
                                                <th class="text-left px-4 py-2 bg-gray-700">Payment</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        vehicle.booking_history.forEach(booking => {
                            // Determine status class
                            let statusClass = '';
                            switch (booking.status) {
                                case 'upcoming':
                                    statusClass = 'status-upcoming';
                                    break;
                                case 'active':
                                    statusClass = 'status-active';
                                    break;
                                case 'completed':
                                    statusClass = 'status-completed';
                                    break;
                                case 'cancelled':
                                    statusClass = 'status-cancelled';
                                    break;
                            }
                            
                            // Determine payment status class
                            let paymentClass = '';
                            switch (booking.payment_status) {
                                case 'paid':
                                    paymentClass = 'status-paid';
                                    break;
                                case 'pending':
                                    paymentClass = 'status-pending';
                                    break;
                                case 'refunded':
                                    paymentClass = 'status-refunded';
                                    break;
                            }
                            
                            const bookingDate = new Date(booking.booking_date).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric'
                            });
                            
                            const bookingTime = new Date(`${booking.booking_date}T${booking.booking_time}`).toLocaleTimeString('en-US', {
                                hour: '2-digit',
                                minute: '2-digit'
                            });
                            
                            bookingHistoryHTML += `
                                <tr>
                                    <td class="px-4 py-2 border-t border-gray-700">#${booking.id}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${bookingDate}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${bookingTime}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${booking.duration} hours</td>
                                    <td class="px-4 py-2 border-t border-gray-700">${booking.Parking_Space_Name}</td>
                                    <td class="px-4 py-2 border-t border-gray-700">
                                        <span class="status-badge ${statusClass}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>
                                    </td>
                                    <td class="px-4 py-2 border-t border-gray-700">
                                        <span class="status-badge ${paymentClass}">${booking.payment_status.charAt(0).toUpperCase() + booking.payment_status.slice(1)}</span>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        bookingHistoryHTML += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        bookingHistoryHTML = `
                            <div class="detail-section mt-6">
                                <h4 class="text-lg font-semibold text-primary mb-3">Booking History</h4>
                                <p class="text-white/70">No booking history found for this vehicle.</p>
                            </div>
                        `;
                    }
                    
                    // Create HTML for vehicle details
                    const vehicleDetailsHTML = `
                        <div class="grid grid-cols-1 gap-6">
                            <div class="flex flex-wrap gap-4 justify-between items-center">
                                <div>
                                    <h3 class="text-xl font-bold">${vehicle.make} ${vehicle.model}</h3>
                                    <p class="text-white/70 text-sm">License Plate: ${vehicle.licensePlate}</p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Vehicle Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Type</p>
                                            <p class="detail-value capitalize">${vehicle.vehicleType}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Make</p>
                                            <p class="detail-value">${vehicle.make}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Model</p>
                                            <p class="detail-value">${vehicle.model}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Color</p>
                                            <p class="detail-value capitalize">${vehicle.color}</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="detail-item">
                                            <p class="detail-label">Username</p>
                                            <p class="detail-value">${vehicle.username}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Name</p>
                                            <p class="detail-value">${vehicle.firstName} ${vehicle.lastName}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Email</p>
                                            <p class="detail-value">${vehicle.email || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item">
                                            <p class="detail-label">Phone</p>
                                            <p class="detail-value">${vehicle.phone || 'Not provided'}</p>
                                        </div>
                                        <div class="detail-item md:col-span-2">
                                            <p class="detail-label">Address</p>
                                            <p class="detail-value">${vehicle.address || 'Not provided'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            ${bookingHistoryHTML}
                        </div>
                    `;
                    
                    document.getElementById('vehicleDetailsContent').innerHTML = vehicleDetailsHTML;
                } else {
                    document.getElementById('vehicleDetailsContent').innerHTML = `
                        <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                            <p>${data.message}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('vehicleDetailsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p>An error occurred while fetching vehicle details. Please try again.</p>
                    </div>
                `;
            });
        }
        
        function closeVehicleDetailsModal() {
            document.getElementById('vehicleDetailsModal').classList.add('hidden');
        }
    </script>








<!-- Message Owner Modal -->
<div id="messageOwnerModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Send Message to Owner</h3>
        <form id="messageOwnerForm">
            <input type="hidden" id="message_owner_id" name="owner_id">
            <input type="hidden" name="action" value="send_owner_message">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Subject</span>
                </label>
                <input type="text" id="message_subject" name="subject" class="input input-bordered bg-gray-700 text-white" required>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Message</span>
                </label>
                <textarea id="message_content" name="message" class="textarea textarea-bordered bg-gray-700 text-white h-32" required></textarea>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeMessageModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>

    // Message modal functions
function openMessageModal(ownerId) {
    document.getElementById('message_owner_id').value = ownerId;
    document.getElementById('message_subject').value = '';
    document.getElementById('message_content').value = '';
    document.getElementById('messageOwnerModal').classList.remove('hidden');
}

function closeMessageModal() {
    document.getElementById('messageOwnerModal').classList.add('hidden');
}

// Make sure this code is added to your document ready function or at the end of the script
document.addEventListener('DOMContentLoaded', function() {
    // Add message form submission
    if (document.getElementById('messageOwnerForm')) {
        document.getElementById('messageOwnerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeMessageModal();
                } else {
                    alert(data.message || 'An error occurred while sending the message.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }
});
</script>


<!-- Commission Modal -->
<div id="commissionModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Set Commission Rate</h3>
        <form id="commissionForm">
            <input type="hidden" id="commission_owner_id" name="owner_id">
            <input type="hidden" name="action" value="update_commission_rate">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Commission Rate (%)</span>
                </label>
                <input type="number" id="commission_rate" name="commission_rate" class="input input-bordered bg-gray-700 text-white" min="0" max="100" step="0.1" required>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closeCommissionModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Commission modal functions
function openCommissionModal(ownerId, currentRate) {
    document.getElementById('commission_owner_id').value = ownerId;
    document.getElementById('commission_rate').value = currentRate || 10;
    document.getElementById('commissionModal').classList.remove('hidden');
}

function closeCommissionModal() {
    document.getElementById('commissionModal').classList.add('hidden');
}

// Add commission form submission
document.getElementById('commissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeCommissionModal();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
});
</script>

<script>
    // Function to update garage owner status
function updateOwnerStatus(ownerId, status) {
    if (confirm(`Are you sure you want to change this owner's status to "${status}"?`)) {
        const formData = new FormData();
        formData.append('action', 'update_owner_status');
        formData.append('owner_id', ownerId);
        formData.append('status', status);
        
        fetch('admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload(); // Reload to see the updated status
            } else {
                alert(data.message || 'An error occurred while updating owner status.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}
</script>

<script>
    function closeOwnerDetailsModal() {
    document.getElementById('ownerDetailsModal').classList.add('hidden');
}
</script>

<script>
    function verifyUser(username) {
    if (confirm('Are you sure you want to verify this user?')) {
        // AJAX call to the server
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (this.status === 200) {
                try {
                    var response = JSON.parse(this.responseText);
                    if (response.success) {
                        // নতুন কোড - পৃষ্ঠা রিলোড করে
                        alert(response.message);
                        window.location.reload(); // পুরো পৃষ্ঠা রিলোড করে
                    } else {
                        alert('Error: ' + response.message);
                    }
                } catch (e) {
                    console.error('JSON parsing error:', e);
                    alert('Error processing the response');
                }
            } else {
                alert('Error: ' + this.status);
            }
        };
        xhr.onerror = function() {
            alert('Network error occurred');
        };
        xhr.send('action=verify_user&username=' + encodeURIComponent(username));
    }
}

// Function to set default 30% commission for all garage owners
function setDefaultCommissionForAll() {
    if (confirm('Are you sure you want to set 30% commission rate for all garage owners? This will update existing rates as well.')) {
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=set_default_commission_for_all'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload(); // Reload to see changes
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}

</script>



<script>
  // Test map functionality
  console.log("Map functionality test...");
  console.log("Leaflet loaded:", typeof L !== 'undefined' ? "Yes" : "No");
  
  // Override viewGarageLocation with a debug version
  window.originalViewGarageLocation = window.viewGarageLocation;
  window.viewGarageLocation = function(lat, lng, name) {
    console.log("viewGarageLocation called with:", lat, lng, name);
    
    // Log the map container element
    const mapContainer = document.getElementById('mapContainer');
    console.log("Map container found:", mapContainer ? "Yes" : "No");
    
    // Call the original function if it exists
    if (window.originalViewGarageLocation) {
      try {
        window.originalViewGarageLocation(lat, lng, name);
      } catch (error) {
        console.error("Error in original function:", error);
      }
    } else {
      console.error("Original viewGarageLocation function not found");
    }
  };
  
  // Test if modal can be shown
  const viewLocationModal = document.getElementById('viewLocationModal');
  console.log("Location modal found:", viewLocationModal ? "Yes" : "No");
</script>


<!-- Add this JavaScript code to the end of your admin.php file, just before the closing </body> tag -->
<script>
    // Notification system
    document.addEventListener('DOMContentLoaded', function() {
        const notificationButton = document.getElementById('notification-button');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationContent = document.getElementById('notification-content');
        const refreshButton = document.getElementById('refresh-notifications');
        
        // Toggle notification dropdown
        notificationButton.addEventListener('click', function() {
            notificationDropdown.classList.toggle('hidden');
            
            // If showing dropdown, fetch notification items
            if (!notificationDropdown.classList.contains('hidden')) {
                fetchNotificationItems();
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!notificationButton.contains(event.target) && !notificationDropdown.contains(event.target)) {
                notificationDropdown.classList.add('hidden');
            }
        });
        
        // Refresh notifications
        refreshButton.addEventListener('click', function() {
            fetchNotificationItems();
            fetchNotificationCounts();
        });
        
        // Fetch notification counts every 5 minutes
        setInterval(fetchNotificationCounts, 5 * 60 * 1000);
        
        // Function to fetch notification counts
        // Add these two functions to your JavaScript code
function fetchNotificationCounts() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_notification_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('notification-count').textContent = data.counts.total;
        }
    })
    .catch(error => console.error('Error fetching notification counts:', error));
}

function fetchNotificationItems() {
    // Show loading state
    document.getElementById('notification-content').innerHTML = `
        <div class="p-6 text-center text-white/70">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <p>Loading notifications...</p>
        </div>
    `;

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_verification_items'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderNotificationItems(data);
        } else {
            document.getElementById('notification-content').innerHTML = `
                <div class="p-6 text-center text-white/70">
                    <p>Error loading notifications.</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching notifications:', error);
        document.getElementById('notification-content').innerHTML = `
            <div class="p-6 text-center text-white/70">
                <p>Failed to load notifications. Please try again.</p>
            </div>
        `;
    });

    
}

function renderNotificationItems(data) {
    const { users, owners, unauthorized, garages } = data; // 'garages' যোগ করুন
    let content = '';
    
    // Add user notifications
    if (users.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Users (${users.length})</h4>
            </div>
        `;
        
        users.forEach(user => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${user.username}</p>
                            <p class="text-sm text-white/70">${user.firstName || ''} ${user.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyUser('${user.username}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add garage owner notifications
    if (owners.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Garage Owners (${owners.length})</h4>
            </div>
        `;
        
        owners.forEach(owner => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${owner.username}</p>
                            <p class="text-sm text-white/70">${owner.firstName || ''} ${owner.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyOwner('${owner.owner_id}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add unauthorized garage owners notifications
    if (unauthorized.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Users with Garages (${unauthorized.length})</h4>
            </div>
        `;
        
        unauthorized.forEach(user => {
            // Create a temporary owner ID for users with garages but not registered as owners
            const tempOwnerId = `U_owner_${user.username}`;
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${user.username}</p>
                            <p class="text-sm text-white/70">${user.firstName || ''} ${user.lastName || ''}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyOwner('${tempOwnerId}')">Register & Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Add unverified garages notifications - নতুন যোগ করা সেকশন
    if (garages && garages.length > 0) {
        content += `
            <div class="p-3 bg-gray-700">
                <h4 class="font-semibold text-white">Unverified Garages (${garages.length})</h4>
            </div>
        `;
        
        garages.forEach(garage => {
            content += `
                <div class="p-3 border-b border-gray-700 hover:bg-gray-700/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${garage.Parking_Space_Name}</p>
                            <p class="text-sm text-white/70">${garage.Parking_Lot_Address}</p>
                        </div>
                        <button class="btn btn-xs btn-primary" onclick="verifyGarage('${garage.garage_id}')">Verify</button>
                    </div>
                </div>
            `;
        });
    }
    
    // Show message if no notifications - গ্যারেজ যোগ করুন চেকে
    if (users.length === 0 && owners.length === 0 && unauthorized.length === 0 && (!garages || garages.length === 0)) {
        content = `
            <div class="p-6 text-center text-white/70">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/40 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                    <line x1="9" y1="9" x2="9.01" y2="9"></line>
                    <line x1="15" y1="9" x2="15.01" y2="9"></line>
                </svg>
                <p>No pending verifications!</p>
            </div>
        `;
    }
    
    // Update notification content
    notificationContent.innerHTML = content;
}
    });
</script>


<script>
function viewOwnerDetails(ownerId) {
    // Show loading state
    document.getElementById('ownerDetailsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
        </div>
    `;
    
    // Show the modal
    document.getElementById('ownerDetailsModal').classList.remove('hidden');
    
    // Fetch owner details
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_owner_details&owner_id=${ownerId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const owner = data.data;
            const ownerType = owner.is_official == 1 ? 'Professional Owner' : 'Dual User';
            const lastLogin = owner.last_login ? new Date(owner.last_login).toLocaleString() : 'Never';
            
            // Create garage list HTML
            let garagesList = '';
            if (owner.garages && owner.garages.length > 0) {
                garagesList = `
                    <div class="mt-6">
                        <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr>
                                        <th class="text-left px-4 py-2 bg-gray-700">Name</th>
                                        <th class="text-left px-4 py-2 bg-gray-700">Address</th>
                                        <th class="text-left px-4 py-2 bg-gray-700">Type</th>
                                        <th class="text-left px-4 py-2 bg-gray-700">Capacity</th>
                                        <th class="text-left px-4 py-2 bg-gray-700">Price/Hour</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                owner.garages.forEach(garage => {
                    garagesList += `
                        <tr>
                            <td class="px-4 py-2 border-t border-gray-700">${garage.name}</td>
                            <td class="px-4 py-2 border-t border-gray-700">${garage.address}</td>
                            <td class="px-4 py-2 border-t border-gray-700">${garage.type}</td>
                            <td class="px-4 py-2 border-t border-gray-700">${garage.capacity}</td>
                            <td class="px-4 py-2 border-t border-gray-700">৳${garage.price}</td>
                        </tr>
                    `;
                });
                
                garagesList += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                garagesList = `
                    <div class="mt-6">
                        <h4 class="text-lg font-semibold text-primary mb-3">Owned Garages</h4>
                        <p class="text-white/70">No garages registered yet.</p>
                    </div>
                `;
            }
            
            // Generate owner details HTML
            const ownerDetailsHTML = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="detail-section">
                        <h4 class="text-lg font-semibold text-primary mb-3">Owner Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="detail-item">
                                <p class="detail-label">Owner ID</p>
                                <p class="detail-value">${owner.owner_id}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Username</p>
                                <p class="detail-value">${owner.username}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Owner Type</p>
                                <p class="detail-value">${ownerType}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Account Status</p>
                                <p class="detail-value capitalize">${owner.account_status}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Registration Date</p>
                                <p class="detail-value">${new Date(owner.registration_date).toLocaleDateString()}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Last Login</p>
                                <p class="detail-value">${lastLogin}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h4 class="text-lg font-semibold text-primary mb-3">Personal Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="detail-item">
                                <p class="detail-label">Name</p>
                                <p class="detail-value">${owner.firstName} ${owner.lastName}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Email</p>
                                <p class="detail-value">${owner.email || 'Not provided'}</p>
                            </div>
                            <div class="detail-item">
                                <p class="detail-label">Phone</p>
                                <p class="detail-value">${owner.phone || 'Not provided'}</p>
                            </div>
                            <div class="detail-item md:col-span-2">
                                <p class="detail-label">Address</p>
                                <p class="detail-value">${owner.address || 'Not provided'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6">
                    <h4 class="text-lg font-semibold text-primary mb-3">Commission Settings</h4>
                    <div class="bg-gray-700/30 p-4 rounded-lg">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-white/60 text-sm">Current Commission Rate</p>
                                <p class="text-white text-2xl font-bold">${owner.commission_rate}%</p>
                                <p class="text-white/60 text-xs mt-2">Commission rate determines the percentage of booking revenue the owner receives. The remaining amount goes to the platform.</p>
                            </div>
                            <button class="btn btn-primary" onclick="updateCommissionRate('${owner.owner_id}')">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                Update Commission Rate
                            </button>
                        </div>
                    </div>
                </div>
                
                ${garagesList}
            `;
            
            document.getElementById('ownerDetailsContent').innerHTML = ownerDetailsHTML;
        } else {
            document.getElementById('ownerDetailsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p>${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('ownerDetailsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p>An error occurred while fetching owner details. Please try again.</p>
            </div>
        `;
    });
}

// Function to close the owner details modal
function closeOwnerDetailsModal() {
    document.getElementById('ownerDetailsModal').classList.add('hidden');
}

// Function to update commission rate

</script>

<script>
function updateCommissionRate(ownerId) {
    // Simple prompt to get new rate
    const newRate = prompt("Enter new commission rate (%):", "30");
    
    // Check if user cancelled
    if (newRate === null) return;
    
    // Validate input
    const rate = parseFloat(newRate);
    if (isNaN(rate) || rate < 0 || rate > 100) {
        alert("Please enter a valid number between 0 and 100");
        return;
    }
    
    // Show loading
    const button = document.querySelector('button[onclick*="updateCommissionRate"]');
    if (button) {
        button.disabled = true;
        button.innerHTML = '<svg class="animate-spin h-5 w-5 mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Updating...';
    }
    
    // Send AJAX request
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'admin.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            // Re-enable button
            if (button) {
                button.disabled = false;
                button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Update Commission Rate';
            }
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Update the displayed rate
                        const rateElement = document.querySelector('.detail-section .text-2xl.font-bold');
                        if (rateElement) {
                            rateElement.textContent = rate + '%';
                        }
                        alert('Commission rate updated successfully!');
                    } else {
                        alert('Error: ' + (response.message || 'Failed to update commission rate'));
                    }
                } catch (e) {
                    console.error(e);
                    alert('Error: Invalid response from server');
                }
            } else {
                alert('Error: Server returned status ' + xhr.status);
            }
        }
    };
    xhr.send('action=update_commission&owner_id=' + encodeURIComponent(ownerId) + '&rate=' + encodeURIComponent(rate));
}

function setDefaultCommissionForAll() {
    if (confirm('Are you sure you want to set 30% commission rate for all garage owners? This will update existing rates as well.')) {
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=set_default_commission_for_all'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload(); // Reload to see changes
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
}

// Add these functions to your existing JavaScript in admin.php

// Function to calculate missing profits
function calculateMissingProfits() {
    if (confirm('Calculate profit for all payments that are missing profit data?')) {
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Calculating...';
        
        fetch('admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=calculate_missing_profits'
        })
        .then(response => response.json())
        .then(data => {
            alert(data.message);
            if (data.calculated > 0) {
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while calculating profits.');
        })
        .finally(() => {
            // Restore button state
            button.disabled = false;
            button.innerHTML = originalText;
        });
    }
}

// Function to refresh dashboard
function refreshDashboard() {
    window.location.reload();
}

// Function to refresh profit chart
function refreshProfitChart() {
    if (window.revenueProfitChartInstance) {
        window.revenueProfitChartInstance.destroy();
    }
    initRevenueProfitChart();
}

// Updated Revenue vs Profit Chart function
// REPLACE your existing initRevenueProfitChart function with this fixed version

function initRevenueProfitChart() {
    const ctx = document.getElementById('revenueProfitChart');
    if (!ctx) {
        console.log('Revenue profit chart canvas not found');
        return;
    }
    
    console.log('Initializing revenue profit chart...');
    
    // Destroy existing chart if it exists
    if (window.revenueProfitChartInstance) {
        window.revenueProfitChartInstance.destroy();
    }
    
    // Fetch profit data
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_profit_by_period&period=last_7_days'
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Parsed data:', data);
            
            if (data.success && data.data && data.data.length > 0) {
                createRevenueProfitChart(ctx, data.data);
            } else {
                console.log('No data available, creating sample chart');
                createSampleProfitChart(ctx);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.log('Raw response that failed to parse:', text);
            createSampleProfitChart(ctx);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        createSampleProfitChart(ctx);
    });
}

function createRevenueProfitChart(ctx, profitData) {
    const dates = profitData.map(item => {
        const date = new Date(item.date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    
    const revenueData = profitData.map(item => parseFloat(item.total_revenue || 0));
    const profitData_values = profitData.map(item => parseFloat(item.platform_profit || 0));
    const ownerCommissionData = profitData.map(item => parseFloat(item.owner_commission || 0));
    
    console.log('Chart data:', { dates, revenueData, profitData_values, ownerCommissionData });
    
    window.revenueProfitChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: dates,
            datasets: [
                {
                    label: 'Total Revenue',
                    data: revenueData,
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Platform Profit',
                    data: profitData_values,
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Owner Commission',
                    data: ownerCommissionData,
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderColor: 'rgba(245, 158, 11, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '৳' + value.toFixed(0);
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: 'white'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white',
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ৳' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            }
        }
    });
}

function createSampleProfitChart(ctx) {
    window.revenueProfitChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['6 days ago', '5 days ago', '4 days ago', '3 days ago', '2 days ago', 'Yesterday', 'Today'],
            datasets: [
                {
                    label: 'Total Revenue',
                    data: [150, 200, 175, 300, 225, 250, 166],
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                },
                {
                    label: 'Platform Profit',
                    data: [45, 60, 52, 90, 67, 75, 50],
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '৳' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: 'white'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white'
                }
            }
        }
    });
}

// Test function to check database connection
function testProfitData() {
    console.log('Testing profit data connection...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=test_profit_data'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Database test results:', data);
        alert('Database Test Results:\n' + 
              'Connected: ' + data.database_connected + '\n' +
              'Profit Records: ' + (data.profit_tracking_total || 0) + '\n' +
              'Joined Records: ' + (data.joined_total || 0) + '\n' +
              'Sample Data: ' + (data.samples ? data.samples.length : 0) + ' records');
    })
    .catch(error => {
        console.error('Test error:', error);
        alert('Database test failed: ' + error.message);
    });
}
</script>

<script>
// Function to view garage reviews
function viewGarageReviews(garageId, garageName) {
    console.log('Viewing reviews for garage:', garageId, garageName); // Debug log
    
    // Set modal title
    document.getElementById('reviewsModalTitle').textContent = `Reviews for ${garageName}`;
    
    // Show loading state
    document.getElementById('garageReviewsContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            <p class="ml-4">Loading reviews...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('garageReviewsModal').classList.remove('hidden');
    
    // Create form data
    const formData = new FormData();
    formData.append('action', 'get_garage_reviews');
    formData.append('garage_id', garageId);
    
    // Debug: Log what we're sending
    console.log('Sending data:', {
        action: 'get_garage_reviews',
        garage_id: garageId
    });
    
    // Fetch reviews
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status); // Debug log
        return response.text(); // Get text first to debug
    })
    .then(text => {
        console.log('Raw response:', text); // Debug log
        try {
            const data = JSON.parse(text);
            console.log('Parsed data:', data); // Debug log
            
            if (data.success) {
                displayGarageReviews(data);
            } else {
                document.getElementById('garageReviewsContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p><strong>Error:</strong> ${data.message}</p>
                        <p class="mt-2 text-sm">Garage ID: ${garageId}</p>
                    </div>
                `;
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            document.getElementById('garageReviewsContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>JSON Parse Error:</strong> ${e.message}</p>
                    <p class="mt-2 text-sm">Raw response: ${text.substring(0, 200)}...</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('garageReviewsContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Network Error:</strong> ${error.message}</p>
                <p class="mt-2 text-sm">Please check console for details.</p>
            </div>
        `;
    });
}

// Test function to debug AJAX
function testAjax() {
    const formData = new FormData();
    formData.append('action', 'debug_action');
    formData.append('test', 'value');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Test response:', data);
        alert('AJAX test successful! Check console for details.');
    })
    .catch(error => {
        console.error('Test error:', error);
        alert('AJAX test failed! Check console for details.');
    });
}

// Function to display garage reviews
function displayGarageReviews(data) {
    const { reviews, summary } = data;
    let content = '';
    
    // Rating Summary Section
    if (summary) {
        const avgRating = parseFloat(summary.average_rating);
        const totalRatings = parseInt(summary.total_ratings);
        
        content += `
            <div class="bg-gray-700/50 rounded-lg p-6 mb-6">
                <h4 class="text-lg font-semibold text-primary mb-4">Rating Summary</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="text-center">
                        <div class="text-4xl font-bold text-yellow-400 mb-2">${avgRating.toFixed(1)}</div>
                        <div class="flex justify-center mb-2">
                            ${generateStarRating(avgRating)}
                        </div>
                        <p class="text-white/70">Based on ${totalRatings} review${totalRatings !== 1 ? 's' : ''}</p>
                    </div>
                    <div class="space-y-2">
                        ${generateRatingBreakdown(summary)}
                    </div>
                </div>
            </div>
        `;
    }
    
    // Individual Reviews Section
    if (reviews && reviews.length > 0) {
        content += `
            <div>
                <h4 class="text-lg font-semibold text-primary mb-4">Individual Reviews</h4>
                <div class="space-y-4 max-h-96 overflow-y-auto">
        `;
        
        reviews.forEach(review => {
            const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const bookingDate = review.booking_date ? new Date(review.booking_date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }) : '';
            
            content += `
                <div class="bg-gray-700/30 rounded-lg p-4 border border-gray-600">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h5 class="font-medium text-white">${review.firstName} ${review.lastName}</h5>
                            <p class="text-sm text-white/60">@${review.rater_username}</p>
                            ${bookingDate ? `<p class="text-xs text-white/50">Booking: ${bookingDate}</p>` : ''}
                        </div>
                        <div class="text-right">
                            <div class="flex items-center gap-1 mb-1">
                                ${generateStarRating(parseFloat(review.rating))}
                                <span class="text-sm text-white/70 ml-2">${review.rating}/5</span>
                            </div>
                            <p class="text-xs text-white/50">${reviewDate}</p>
                        </div>
                    </div>
                    ${review.review_text ? `
                        <div class="mt-3 p-3 bg-gray-600/30 rounded">
                            <p class="text-white/90">"${review.review_text}"</p>
                        </div>
                    ` : ''}
                </div>
            `;
        });
        
        content += `
                </div>
            </div>
        `;
    } else {
        content += `
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/20 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 9V5a3 3 0 0 0-6 0v4"></path>
                    <rect x="2" y="9" width="20" height="12" rx="2" ry="2"></rect>
                    <circle cx="12" cy="15" r="1"></circle>
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Reviews Yet</h4>
                <p class="text-white/50">This garage hasn't received any reviews from customers yet.</p>
            </div>
        `;
    }
    
    document.getElementById('garageReviewsContent').innerHTML = content;
}

// Helper function to generate star rating HTML
function generateStarRating(rating) {
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
    
    let starsHtml = '';
    
    // Full stars
    for (let i = 0; i < fullStars; i++) {
        starsHtml += `<svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>`;
    }
    
    // Half star
    if (hasHalfStar) {
        starsHtml += `<svg class="w-4 h-4 text-yellow-400" viewBox="0 0 24 24"><defs><linearGradient id="half"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><path fill="url(#half)" d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>`;
    }
    
    // Empty stars
    for (let i = 0; i < emptyStars; i++) {
        starsHtml += `<svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>`;
    }
    
    return starsHtml;
}

// Helper function to generate rating breakdown
function generateRatingBreakdown(summary) {
    const total = parseInt(summary.total_ratings);
    const ratings = [
        { star: 5, count: parseInt(summary.five_star) },
        { star: 4, count: parseInt(summary.four_star) },
        { star: 3, count: parseInt(summary.three_star) },
        { star: 2, count: parseInt(summary.two_star) },
        { star: 1, count: parseInt(summary.one_star) }
    ];
    
    return ratings.map(rating => {
        const percentage = total > 0 ? Math.round((rating.count / total) * 100) : 0;
        return `
            <div class="flex items-center gap-2">
                <span class="text-sm text-white/70 w-6">${rating.star}★</span>
                <div class="flex-1 bg-gray-600 rounded-full h-2">
                    <div class="bg-yellow-400 h-2 rounded-full" style="width: ${percentage}%"></div>
                </div>
                <span class="text-sm text-white/70 w-12">${rating.count} (${percentage}%)</span>
            </div>
        `;
    }).join('');
}

// Function to close garage reviews modal
function closeGarageReviewsModal() {
    document.getElementById('garageReviewsModal').classList.add('hidden');
}
</script>

<!-- Points Management Modal -->
<div id="pointsModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-xl font-bold text-white mb-4">Manage User Points</h3>
        <form id="pointsForm">
            <input type="hidden" id="points_username" name="username">
            <input type="hidden" name="action" value="adjust_user_points">
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">User</span>
                </label>
                <input type="text" id="points_user_display" class="input input-bordered bg-gray-700 text-white" readonly>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Current Points</span>
                </label>
                <input type="text" id="current_points_display" class="input input-bordered bg-gray-700 text-white" readonly>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Points Change</span>
                </label>
                <div class="flex gap-2">
                    
                    <input type="number" id="points_change" name="points_change" class="input input-bordered bg-gray-700 text-white flex-1" placeholder="Enter amount (+/-)" required>
                    
                </div>
                <label class="label">
                    <span class="label-text-alt text-white/60">Use positive numbers to add points, negative to subtract</span>
                </label>
            </div>
            
            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text text-white">Reason</span>
                </label>
                <select id="reason_select" class="select select-bordered bg-gray-700 text-white mb-2" onchange="updateReasonField()">
                    <option value="">Select a reason</option>
                    <option value="Admin bonus">Admin bonus</option>
                    <option value="Compensation">Compensation</option>
                    <option value="Promotion reward">Promotion reward</option>
                    <option value="System adjustment">System adjustment</option>
                    <option value="Custom">Custom reason</option>
                </select>
                <input type="text" id="reason" name="reason" class="input input-bordered bg-gray-700 text-white" placeholder="Enter reason for adjustment" required>
            </div>
            
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" class="btn btn-outline border-white/20 text-white" onclick="closePointsModal()">Cancel</button>
                <button type="submit" class="btn bg-primary hover:bg-primary-dark text-white border-none">Update Points</button>
            </div>
        </form>
    </div>
</div>

<!-- Points History Modal -->
<div id="pointsHistoryModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white">Points History</h3>
            <button onclick="closePointsHistoryModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="pointsHistoryContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            </div>
        </div>
        
        <div class="flex justify-end mt-6">
            <button class="btn btn-outline border-white/20 text-white" onclick="closePointsHistoryModal()">Close</button>
        </div>
    </div>
</div>
<script>
// Complete fix for admin dashboard - Replace your JavaScript section with this

// Global variables to track chart instances
let bookingStatusChartInstance = null;
let revenueChartInstance = null;
let revenueProfitChartInstance = null;

// Function to safely destroy and recreate charts
function destroyChart(chartInstance) {
    if (chartInstance) {
        try {
            chartInstance.destroy();
        } catch (e) {
            console.warn('Error destroying chart:', e);
        }
    }
    return null;
}

// Fixed chart initialization functions
function initBookingStatusChart() {
    const ctx = document.getElementById('bookingStatusChart');
    if (!ctx) return;
    
    // Destroy existing chart if it exists
    bookingStatusChartInstance = destroyChart(bookingStatusChartInstance);
    
    const data = {
        labels: ['Upcoming', 'Active', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?php 
                $upcomingCount = 0;
                $activeCount = 0;
                $completedCount = 0;
                $cancelledCount = 0;
                
                foreach ($bookings as $booking) {
                    switch ($booking['status']) {
                        case 'upcoming': $upcomingCount++; break;
                        case 'active': $activeCount++; break;
                        case 'completed': $completedCount++; break;
                        case 'cancelled': $cancelledCount++; break;
                    }
                }
                echo $upcomingCount . ', ' . $activeCount . ', ' . $completedCount . ', ' . $cancelledCount;
                ?>
            ],
            backgroundColor: [
                'rgba(59, 130, 246, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(243, 156, 18, 0.7)',
                'rgba(239, 68, 68, 0.7)'
            ],
            borderColor: [
                'rgba(59, 130, 246, 1)',
                'rgba(16, 185, 129, 1)',
                'rgba(243, 156, 18, 1)',
                'rgba(239, 68, 68, 1)'
            ],
            borderWidth: 1
        }]
    };
    
    bookingStatusChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: 'white'
                    }
                }
            }
        }
    });
}

// Fixed points modal functions
function openPointsModal(username, currentPoints) {
    console.log('Opening points modal for:', username, 'Current points:', currentPoints);
    
    document.getElementById('points_username').value = username;
    document.getElementById('points_user_display').value = username;
    document.getElementById('current_points_display').value = currentPoints + ' points';
    document.getElementById('points_change').value = '';
    document.getElementById('reason').value = '';
    document.getElementById('reason_select').selectedIndex = 0;
    document.getElementById('pointsModal').classList.remove('hidden');
}

function closePointsModal() {
    document.getElementById('pointsModal').classList.add('hidden');
}

function updateReasonField() {
    const select = document.getElementById('reason_select');
    const input = document.getElementById('reason');
    
    if (select.value && select.value !== 'Custom') {
        input.value = select.value;
        input.readOnly = true;
    } else {
        input.value = '';
        input.readOnly = false;
        input.focus();
    }
}

// Fixed points form submission
function submitPointsForm(e) {
    e.preventDefault();
    e.stopPropagation();
    
    console.log('Points form submitted');
    
    const form = e.target;
    const formData = new FormData(form);
    const pointsChange = parseInt(formData.get('points_change'));
    const username = formData.get('username');
    const reason = formData.get('reason');
    
    // Validate inputs
    if (!pointsChange || isNaN(pointsChange)) {
        alert('Please enter a valid points change amount');
        return false;
    }
    
    if (!reason || reason.trim() === '') {
        alert('Please provide a reason for the points adjustment');
        return false;
    }
    
    // Custom confirmation (avoid browser dialog conflict)
    const actionText = pointsChange > 0 ? `add ${pointsChange} points to` : `remove ${Math.abs(pointsChange)} points from`;
    const confirmMessage = `Are you sure you want to ${actionText} ${username}?\n\nReason: ${reason}`;
    
    if (!confirm(confirmMessage)) {
        return false;
    }
    
    // Disable submit button
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="loading loading-spinner loading-sm"></span> Updating...';
    
    // Send AJAX request with proper error handling
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'admin.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            // Re-enable button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            
            console.log('Response status:', xhr.status);
            console.log('Response text:', xhr.responseText);
            
            if (xhr.status === 200) {
                let response;
                try {
                    // Clean the response text to remove any extra characters
                    const cleanResponse = xhr.responseText.trim();
                    response = JSON.parse(cleanResponse);
                    
                    if (response.success) {
                        alert(response.message);
                        closePointsModal();
                        // Reload page to see updates
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.message || 'Unknown error occurred'));
                    }
                } catch (jsonError) {
                    console.error('JSON parsing error:', jsonError);
                    console.error('Raw response:', xhr.responseText);
                    alert('Error: Invalid response from server. Check console for details.');
                }
            } else {
                alert('HTTP Error: ' + xhr.status);
            }
        }
    };
    
    xhr.onerror = function() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        alert('Network error occurred. Please try again.');
    };
    
    // Prepare form data for sending
    const params = new URLSearchParams();
    for (let [key, value] of formData.entries()) {
        params.append(key, value);
    }
    
    console.log('Sending data:', params.toString());
    xhr.send(params.toString());
    
    return false;
}

// Enhanced DOMContentLoaded event listener
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing admin dashboard...');
    
    // Initialize charts with error handling
    try {
        if (document.getElementById('bookingStatusChart')) {
            initBookingStatusChart();
        }
    } catch (e) {
        console.error('Error initializing booking status chart:', e);
    }
    
    // Add points form event listener with better error handling
    const pointsForm = document.getElementById('pointsForm');
    if (pointsForm) {
        pointsForm.removeEventListener('submit', submitPointsForm); // Remove any existing listeners
        pointsForm.addEventListener('submit', submitPointsForm);
        console.log('Points form event listener added');
    } else {
        console.warn('Points form not found');
    }
    
    // Add other form listeners
    const editGarageForm = document.getElementById('editGarageForm');
    if (editGarageForm) {
        editGarageForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeEditGarageModal();
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }
    
    // Add user form listener
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeUserModal();
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }
    
    // Add search functionality
    document.querySelectorAll('input[id$="-search"]').forEach(input => {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const tableId = this.id.split('-')[0];
            
            document.querySelectorAll(`#${tableId}-tab table tbody tr`).forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
    
    console.log('Admin dashboard initialization complete');
});

// Quick test function
function testPointsUpdate() {
    console.log('Testing points update...');
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'admin.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            console.log('Test response status:', xhr.status);
            console.log('Test response text:', xhr.responseText);
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    console.log('Test response parsed:', response);
                    alert('Test successful: ' + response.message);
                } catch (e) {
                    console.error('Test JSON parse error:', e);
                    alert('Test failed: Invalid JSON response');
                }
            } else {
                alert('Test failed: HTTP ' + xhr.status);
            }
        }
    };
    
    const params = 'action=adjust_user_points&username=shakib&points_change=5&reason=Test update';
    xhr.send(params);
}

// Show tab content function
function showTabContent() {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab') || 'dashboard';
    
    // Hide all tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tab + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
}
</script>

<script>
    // Complete Points History JavaScript - Add this to your admin.php

// Points History Modal Function
function viewPointsHistory(username) {
    console.log('Opening points history for:', username);
    
    // Show loading state
    document.getElementById('pointsHistoryContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            <p class="ml-4 text-white">Loading points history...</p>
        </div>
    `;
    
    // Show the modal
    document.getElementById('pointsHistoryModal').classList.remove('hidden');
    
    // Fetch points history
    const formData = new FormData();
    formData.append('action', 'get_user_points_history');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Points history response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Points history raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Points history parsed data:', data);
            
            if (data.success) {
                displayPointsHistory(data, username);
            } else {
                document.getElementById('pointsHistoryContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p><strong>Error loading points history:</strong> ${data.message}</p>
                    </div>
                `;
            }
        } catch (jsonError) {
            console.error('JSON parsing error:', jsonError);
            document.getElementById('pointsHistoryContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>Error:</strong> Invalid response from server</p>
                    <p class="text-sm mt-2">Check browser console for details</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching points history:', error);
        document.getElementById('pointsHistoryContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Network Error:</strong> ${error.message}</p>
                <p class="text-sm mt-2">Please check your connection and try again</p>
            </div>
        `;
    });
}

// Display Points History Function
function displayPointsHistory(data, username) {
    const { current_points, history } = data;
    let content = `
        <div class="bg-gray-700/50 rounded-lg p-4 mb-6">
            <h4 class="text-lg font-semibold text-primary mb-2">Current Status</h4>
            <div class="flex items-center gap-6">
                <div>
                    <p class="text-white/70 text-sm">User</p>
                    <p class="text-white font-medium text-lg">${username}</p>
                </div>
                <div>
                    <p class="text-white/70 text-sm">Current Points</p>
                    <p class="text-primary text-3xl font-bold">${current_points.toLocaleString()}</p>
                </div>
            </div>
        </div>
    `;
    
    if (history && history.length > 0) {
        content += `
            <div>
                <h4 class="text-lg font-semibold text-primary mb-4">Transaction History (Last 20)</h4>
                <div class="overflow-x-auto max-h-96 overflow-y-auto">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-gray-700">
                            <tr>
                                <th class="text-left px-4 py-3 text-white font-semibold">Date & Time</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Type</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Points</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Description</th>
                                <th class="text-left px-4 py-3 text-white font-semibold">Booking</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        history.forEach((transaction, index) => {
            const date = new Date(transaction.created_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            
            let typeClass = '';
            let typeIcon = '';
            let typeBadge = '';
            
            switch (transaction.transaction_type) {
                case 'earned':
                    typeClass = 'text-green-400';
                    typeIcon = '+';
                    typeBadge = 'bg-green-500/20 text-green-400';
                    break;
                case 'spent':
                    typeClass = 'text-red-400';
                    typeIcon = '-';
                    typeBadge = 'bg-red-500/20 text-red-400';
                    break;
                case 'bonus':
                    typeClass = 'text-blue-400';
                    typeIcon = '+';
                    typeBadge = 'bg-blue-500/20 text-blue-400';
                    break;
            }
            
            const rowClass = index % 2 === 0 ? 'bg-gray-800/30' : 'bg-gray-800/10';
            
            content += `
                <tr class="${rowClass} hover:bg-gray-700/30 transition-colors">
                    <td class="px-4 py-3 text-white/80 text-sm">${date}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs font-medium ${typeBadge}">
                            ${transaction.transaction_type.charAt(0).toUpperCase() + transaction.transaction_type.slice(1)}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="${typeClass} font-bold text-lg">${typeIcon}${transaction.points_amount}</span>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">${transaction.description}</td>
                    <td class="px-4 py-3 text-center">
                        ${transaction.booking_id ? 
                            `<span class="bg-gray-600 text-white px-2 py-1 rounded text-xs">#${transaction.booking_id}</span>` : 
                            '<span class="text-white/40">-</span>'
                        }
                    </td>
                </tr>
            `;
        });
        
        content += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        
        // Add summary stats
        const totalEarned = history.filter(t => t.transaction_type === 'earned').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        const totalSpent = history.filter(t => t.transaction_type === 'spent').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        const totalBonus = history.filter(t => t.transaction_type === 'bonus').reduce((sum, t) => sum + parseInt(t.points_amount), 0);
        
        content += `
            <div class="mt-6 grid grid-cols-3 gap-4">
                <div class="bg-green-500/10 border border-green-500/20 rounded-lg p-3 text-center">
                    <p class="text-green-400 text-sm">Total Earned</p>
                    <p class="text-green-400 text-xl font-bold">+${totalEarned}</p>
                </div>
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3 text-center">
                    <p class="text-red-400 text-sm">Total Spent</p>
                    <p class="text-red-400 text-xl font-bold">-${totalSpent}</p>
                </div>
                <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-3 text-center">
                    <p class="text-blue-400 text-sm">Admin Bonus</p>
                    <p class="text-blue-400 text-xl font-bold">+${totalBonus}</p>
                </div>
            </div>
        `;
    } else {
        content += `
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/20 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Transaction History</h4>
                <p class="text-white/50">This user hasn't earned or spent any points yet.</p>
                <p class="text-white/40 text-sm mt-2">Points will appear here when they complete bookings or receive admin bonuses.</p>
            </div>
        `;
    }
    
    document.getElementById('pointsHistoryContent').innerHTML = content;
}

// Close Modal Function
function closePointsHistoryModal() {
    document.getElementById('pointsHistoryModal').classList.add('hidden');
}

// Test function to verify it's working
function testPointsHistory() {
    console.log('Testing points history...');
    viewPointsHistory('saba'); // Test with saba who has points
}

// Make sure the modal HTML exists - Add this function to check
function ensurePointsHistoryModal() {
    if (!document.getElementById('pointsHistoryModal')) {
        console.warn('Points History Modal not found! Adding it...');
        
        const modalHTML = `
            <div id="pointsHistoryModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
                <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-white">Points History</h3>
                        <button onclick="closePointsHistoryModal()" class="text-white/70 hover:text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div id="pointsHistoryContent" class="text-white">
                        <!-- Content will be loaded dynamically -->
                    </div>
                    
                    <div class="flex justify-end mt-6">
                        <button class="btn btn-outline border-white/20 text-white" onclick="closePointsHistoryModal()">Close</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        console.log('Points History Modal added successfully!');
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Ensure modal exists
    ensurePointsHistoryModal();
    
    // Add click event listeners to all blue buttons
    document.querySelectorAll('button[onclick*="viewPointsHistory"]').forEach(button => {
        console.log('Found points history button:', button);
    });
    
    console.log('Points history functionality initialized');
});
</script>

<script>
// Enhanced DOMContentLoaded with better tab detection
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing admin dashboard...');
    
    // Initialize charts with error handling
    try {
        if (document.getElementById('bookingStatusChart')) {
            initBookingStatusChart();
        }
    } catch (e) {
        console.error('Error initializing booking status chart:', e);
    }
    
    // CHECK IF REVENUE TAB IS ACTIVE AND LOAD DATA
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'dashboard';
    
    console.log('Active tab:', activeTab);
    
    if (activeTab === 'revenue') {
        console.log('Revenue tab is active, loading analytics...');
        setTimeout(() => {
            loadRevenueAnalytics();
        }, 500); // Small delay to ensure DOM is ready
    }
    
    // Add tab click listeners to load revenue data when tab is clicked
    document.querySelectorAll('a[href*="tab=revenue"]').forEach(link => {
        link.addEventListener('click', function() {
            console.log('Revenue tab clicked, loading analytics...');
            setTimeout(() => {
                loadRevenueAnalytics();
            }, 100);
        });
    });
    
    // Add period filter event listener
    const periodFilter = document.getElementById('revenue-period-filter');
    if (periodFilter) {
        periodFilter.addEventListener('change', function() {
            console.log('Period changed:', this.value);
            loadRevenueTrends();
        });
    }
    
    // Rest of your existing code...
});

// ISSUE 3: Missing/Incomplete Revenue Analytics Functions
// Add these complete functions to your JavaScript section:

function loadRevenueAnalytics() {
    console.log('Loading revenue analytics...');
    
    // Load revenue statistics
    loadRevenueStats();
    
    // Load revenue trends  
    loadRevenueTrends();
    
    // Load payment methods data
    loadPaymentMethodsData();
    
    // Load top garages
    loadTopGarages();
}

function loadRevenueStats() {
    console.log('Fetching revenue stats...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_revenue_stats'
    })
    .then(response => {
        console.log('Revenue stats response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Revenue stats raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Revenue stats parsed data:', data);
            
            if (data.success) {
                updateRevenueCards(data.data);
            } else {
                console.error('Revenue stats error:', data.message);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Raw response:', text);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
    });
}

function updateRevenueCards(stats) {
    console.log('Updating revenue cards with:', stats);
    
    // Update revenue cards with null checks
    const totalRevenue = document.getElementById('total-revenue');
    const platformProfit = document.getElementById('platform-profit');
    const ownerEarnings = document.getElementById('owner-earnings');
    const pendingRevenue = document.getElementById('pending-revenue');
    
    if (totalRevenue) {
        totalRevenue.textContent = '৳' + Number(stats.total_revenue || 0).toLocaleString();
    }
    
    if (platformProfit) {
        platformProfit.textContent = '৳' + Number(stats.total_profit || 0).toLocaleString();
    }
    
    if (ownerEarnings) {
        ownerEarnings.textContent = '৳' + Number(stats.total_owner_earnings || 0).toLocaleString();
    }
    
    if (pendingRevenue) {
        pendingRevenue.textContent = '৳' + Number(stats.pending_revenue || 0).toLocaleString();
    }
    
    // Update percentages
    const profitMargin = stats.total_revenue > 0 ? ((stats.total_profit / stats.total_revenue) * 100).toFixed(1) : 0;
    const profitMarginEl = document.getElementById('profit-margin');
    if (profitMarginEl) {
        profitMarginEl.textContent = profitMargin + '% margin';
    }
    
    const ownerPercentage = stats.total_revenue > 0 ? ((stats.total_owner_earnings / stats.total_revenue) * 100).toFixed(1) : 0;
    const ownerPercentageEl = document.getElementById('owner-percentage');
    if (ownerPercentageEl) {
        ownerPercentageEl.textContent = ownerPercentage + '% to owners';
    }
}

function loadRevenueTrends() {
    console.log('Loading revenue trends...');
    
    const periodFilter = document.getElementById('revenue-period-filter');
    const period = periodFilter ? periodFilter.value : 'last_7_days';
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `action=get_revenue_trends&period=${period}`
    })
    .then(response => response.text())
    .then(text => {
        console.log('Revenue trends raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Revenue trends parsed data:', data);
            
            if (data.success) {
                updateRevenueTrendsChart(data.data);
            } else {
                console.error('Revenue trends error:', data.message);
                // Show sample data if no real data
                updateRevenueTrendsChart([]);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            // Show sample data on error
            updateRevenueTrendsChart([]);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        // Show sample data on error
        updateRevenueTrendsChart([]);
    });
}

function updateRevenueTrendsChart(data) {
    console.log('Updating revenue trends chart with:', data);
    
    const ctx = document.getElementById('revenueTrendsChart');
    if (!ctx) {
        console.error('Revenue trends chart canvas not found');
        return;
    }
    
    // Destroy existing chart if it exists
    if (window.revenueTrendsChartInstance) {
        window.revenueTrendsChartInstance.destroy();
    }
    
    // Prepare chart data
    let labels, revenueData, profitData;
    
    if (data && data.length > 0) {
        labels = data.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        revenueData = data.map(item => parseFloat(item.revenue || 0));
        profitData = data.map(item => parseFloat(item.profit || 0));
    } else {
        // Sample data when no real data available
        labels = ['6 days ago', '5 days ago', '4 days ago', '3 days ago', '2 days ago', 'Yesterday', 'Today'];
        revenueData = [150, 200, 175, 300, 225, 250, 180];
        profitData = [45, 60, 52, 90, 67, 75, 54];
    }
    
    window.revenueTrendsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 2,
                tension: 0.4
            }, {
                label: 'Profit',
                data: profitData,
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 2,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: 'white'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)',
                        callback: function(value) {
                            return '৳' + value;
                        }
                    }
                },
                x: {
                    grid: {
                        color: 'rgba(255, 255, 255, 0.1)'
                    },
                    ticks: {
                        color: 'rgba(255, 255, 255, 0.7)'
                    }
                }
            }
        }
    });
}

function loadPaymentMethodsData() {
    console.log('Loading payment methods data...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_payment_method_revenue'
    })
    .then(response => response.text())
    .then(text => {
        console.log('Payment methods raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Payment methods parsed data:', data);
            
            if (data.success) {
                updatePaymentMethodsChart(data.data);
            } else {
                console.error('Payment methods error:', data.message);
                updatePaymentMethodsChart([]);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            updatePaymentMethodsChart([]);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        updatePaymentMethodsChart([]);
    });
}

function updatePaymentMethodsChart(data) {
    console.log('Updating payment methods chart with:', data);
    
    const ctx = document.getElementById('paymentMethodsChart');
    if (!ctx) {
        console.error('Payment methods chart canvas not found');
        return;
    }
    
    // Destroy existing chart if it exists
    if (window.paymentMethodsChartInstance) {
        window.paymentMethodsChartInstance.destroy();
    }
    
    // Prepare chart data
    let labels, amounts, colors;
    
    if (data && data.length > 0) {
        labels = data.map(item => item.payment_method.charAt(0).toUpperCase() + item.payment_method.slice(1));
        amounts = data.map(item => parseFloat(item.total_amount));
        colors = [
            'rgba(236, 72, 153, 0.8)',
            'rgba(59, 130, 246, 0.8)',
            'rgba(245, 158, 11, 0.8)',
            'rgba(16, 185, 129, 0.8)',
            'rgba(139, 92, 246, 0.8)'
        ];
    } else {
        // Sample data when no real data available
        labels = ['bKash', 'Nagad', 'Points', 'Cash'];
        amounts = [45, 30, 15, 10];
        colors = [
            'rgba(236, 72, 153, 0.8)',
            'rgba(59, 130, 246, 0.8)',
            'rgba(245, 158, 11, 0.8)',
            'rgba(16, 185, 129, 0.8)'
        ];
    }
    
    window.paymentMethodsChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: amounts,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#1f2937'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: 'white',
                        padding: 20
                    }
                }
            }
        }
    });
}

function loadTopGarages() {
    console.log('Loading top garages...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_top_revenue_garages&limit=10'
    })
    .then(response => response.text())
    .then(text => {
        console.log('Top garages raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Top garages parsed data:', data);
            
            if (data.success) {
                updateTopGaragesTable(data.data);
            } else {
                console.error('Top garages error:', data.message);
                updateTopGaragesTable([]);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            updateTopGaragesTable([]);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        updateTopGaragesTable([]);
    });
}

function updateTopGaragesTable(data) {
    console.log('Updating top garages table with:', data);
    
    const tableBody = document.getElementById('top-garages-table');
    if (!tableBody) {
        console.error('Top garages table not found');
        return;
    }
    
    if (data && data.length > 0) {
        let html = '';
        data.forEach(garage => {
            html += `
                <tr class="border-b border-gray-700/50">
                    <td class="py-3">
                        <div>
                            <p class="font-medium text-white">${garage.garage_name || 'Unknown'}</p>
                            <p class="text-sm text-gray-400">${garage.garage_id || ''}</p>
                        </div>
                    </td>
                    <td class="py-3 text-white">${garage.owner_username || 'Unknown'}</td>
                    <td class="text-right py-3 text-blue-400 font-medium">৳${Number(garage.total_revenue || 0).toLocaleString()}</td>
                    <td class="text-right py-3 text-emerald-400 font-medium">৳${Number(garage.total_profit || 0).toLocaleString()}</td>
                    <td class="text-right py-3 text-gray-300">${garage.total_bookings || 0}</td>
                    <td class="text-right py-3 text-purple-400 font-medium">৳${Number(garage.avg_booking_value || 0).toFixed(0)}</td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;
    } else {
        tableBody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-8 text-white/60">
                    <div class="text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-white/20 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <p>No revenue data available yet</p>
                        <p class="text-sm mt-1">Complete some bookings to see analytics</p>
                    </div>
                </td>
            </tr>
        `;
    }
}

function exportRevenueReport() {
    console.log('Exporting revenue report...');
    // You can implement this later
    alert('Export feature coming soon!');
}

// Test function to debug AJAX
function testRevenueAjax() {
    console.log('Testing revenue AJAX...');
    
    fetch('admin.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=get_revenue_stats'
    })
    .then(response => {
        console.log('Test response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Test raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Test parsed data:', data);
        } catch (e) {
            console.error('Test JSON parse error:', e);
        }
    })
    .catch(error => {
        console.error('Test fetch error:', error);
    });
}

// Add this to your browser console to test: testRevenueAjax()
</script>

<!-- Add this modal HTML to your admin.php file, before the closing </body> tag -->

<!-- Document Verification Modal -->
<div id="verificationModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Document Verification Review</h3>
            <button onclick="closeVerificationModal()" class="text-white/70 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div id="verificationContent" class="text-white">
            <!-- Content will be loaded dynamically -->
            <div class="flex justify-center items-center h-40">
                <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                <p class="ml-4 text-white">Loading verification documents...</p>
            </div>
        </div>
        
        <!-- Action buttons -->
        <div id="verificationActions" class="flex justify-between items-center mt-6 pt-6 border-t border-gray-700 hidden">
            <div class="flex-1">
                <label class="block text-white text-sm font-medium mb-2">Admin Notes:</label>
                <textarea id="adminNotes" class="w-full bg-gray-700 text-white border border-gray-600 rounded px-3 py-2 h-20 resize-none" placeholder="Add notes about your decision..."></textarea>
            </div>
            <div class="flex gap-3 ml-6">
                <button id="rejectBtn" class="btn btn-error" onclick="reviewVerification('reject')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    Reject
                </button>
                <button id="approveBtn" class="btn btn-success" onclick="reviewVerification('approve')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    Approve
                </button>
            </div>
        </div>
        
        <div class="flex justify-end mt-4">
            <button class="btn btn-outline border-white/20 text-white" onclick="closeVerificationModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Global variable to store current verification data
let currentVerificationData = null;

// Function to open verification modal (call this when clicking unverified status)
function openVerificationModal(username) {
    console.log('Opening verification modal for:', username);
    
    // Show modal
    document.getElementById('verificationModal').classList.remove('hidden');
    
    // Reset content
    document.getElementById('verificationContent').innerHTML = `
        <div class="flex justify-center items-center h-40">
            <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
            <p class="ml-4 text-white">Loading verification documents...</p>
        </div>
    `;
    
    // Hide action buttons initially
    document.getElementById('verificationActions').classList.add('hidden');
    
    // Fetch verification documents
    fetchVerificationDocuments(username);
}

// Function to fetch verification documents
function fetchVerificationDocuments(username) {
    const formData = new FormData();
    formData.append('action', 'get_user_verification_docs');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        try {
            const data = JSON.parse(text);
            console.log('Parsed verification data:', data);
            
            if (data.success) {
                currentVerificationData = data;
                displayVerificationDocuments(data);
            } else {
                document.getElementById('verificationContent').innerHTML = `
                    <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                        <p><strong>Error:</strong> ${data.message}</p>
                    </div>
                `;
            }
        } catch (jsonError) {
            console.error('JSON parsing error:', jsonError);
            document.getElementById('verificationContent').innerHTML = `
                <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                    <p><strong>Error:</strong> Invalid response from server</p>
                    <p class="text-sm mt-2">Check browser console for details</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching verification documents:', error);
        document.getElementById('verificationContent').innerHTML = `
            <div class="p-4 bg-red-900/20 text-red-500 rounded-lg">
                <p><strong>Network Error:</strong> ${error.message}</p>
                <p class="text-sm mt-2">Please check your connection and try again</p>
            </div>
        `;
    });
}

// Function to display verification documents
function displayVerificationDocuments(data) {
    const { username, verification_request, documents } = data;
    
    let content = `
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- User Information -->
            <div class="bg-gray-700/30 rounded-lg p-4">
                <h4 class="text-lg font-semibold text-primary mb-3">User Information</h4>
                <div class="space-y-2">
                    <div>
                        <span class="text-white/60 text-sm">Username:</span>
                        <p class="text-white font-medium">${username}</p>
                    </div>`;
    
    if (verification_request) {
        content += `
                    <div>
                        <span class="text-white/60 text-sm">Full Name:</span>
                        <p class="text-white">${verification_request.full_name || 'Not provided'}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Email:</span>
                        <p class="text-white">${verification_request.email || 'Not provided'}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Phone:</span>
                        <p class="text-white">${verification_request.phone || 'Not provided'}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Current Status:</span>
                        <span class="px-2 py-1 rounded text-xs ${verification_request.account_status === 'verified' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'}">${verification_request.account_status}</span>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Request Type:</span>
                        <p class="text-white capitalize">${verification_request.request_type}</p>
                    </div>
                    <div>
                        <span class="text-white/60 text-sm">Requested At:</span>
                        <p class="text-white text-sm">${new Date(verification_request.requested_at).toLocaleString()}</p>
                    </div>`;
    }
    
    content += `
                </div>
            </div>
            
            <!-- Documents Section -->
            <div class="lg:col-span-2">
                <h4 class="text-lg font-semibold text-primary mb-4">Submitted Documents (${documents.length})</h4>`;
    
    if (documents.length > 0) {
        content += `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">`;
        
        documents.forEach(doc => {
            const statusClass = doc.status === 'approved' ? 'bg-green-500/20 text-green-400' : 
                               doc.status === 'rejected' ? 'bg-red-500/20 text-red-400' : 
                               'bg-yellow-500/20 text-yellow-400';
            
            const submittedDate = new Date(doc.submitted_at).toLocaleDateString();
            const fileSize = (doc.file_size / 1024 / 1024).toFixed(2); // Convert to MB
            
            content += `
                <div class="bg-gray-700/50 border border-gray-600 rounded-lg p-4 hover:bg-gray-700/70 transition-colors">
                    <div class="flex justify-between items-start mb-3">
                        <h5 class="font-medium text-white">${doc.document_type_display}</h5>
                        <span class="px-2 py-1 rounded text-xs ${statusClass}">${doc.status}</span>
                    </div>
                    
                    <div class="space-y-2 text-sm mb-4">
                        <div>
                            <span class="text-white/60">Document Number:</span>
                            <span class="text-white ml-2">${doc.document_number || 'Not provided'}</span>
                        </div>
                        <div>
                            <span class="text-white/60">File Name:</span>
                            <span class="text-white ml-2">${doc.original_filename}</span>
                        </div>
                        <div>
                            <span class="text-white/60">File Size:</span>
                            <span class="text-white ml-2">${fileSize} MB</span>
                        </div>
                        <div>
                            <span class="text-white/60">Submitted:</span>
                            <span class="text-white ml-2">${submittedDate}</span>
                        </div>
                    </div>
                    
                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-primary flex-1" onclick="viewDocument('${doc.file_path}', '${doc.original_filename}')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            View Document
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="downloadDocument('${doc.file_path}', '${doc.original_filename}')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                        </button>
                    </div>
                </div>`;
        });
        
        content += `</div>`;
    } else {
        content += `
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-white/20 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14,2 14,8 20,8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10,9 9,9 8,9"></polyline>
                </svg>
                <h4 class="text-lg font-semibold text-white/70 mb-2">No Documents Submitted</h4>
                <p class="text-white/50">This user hasn't submitted any verification documents yet.</p>
            </div>`;
    }
    
    content += `
            </div>
        </div>`;
    
    document.getElementById('verificationContent').innerHTML = content;
    
    // Show action buttons only if there are pending documents
    const hasPendingDocs = documents.some(doc => doc.status === 'pending');
    if (hasPendingDocs) {
        document.getElementById('verificationActions').classList.remove('hidden');
    }
}

// Function to view document
function viewDocument(filePath, fileName) {
    // Create a new window to display the document
    const documentUrl = filePath; // You might need to adjust this path
    window.open(documentUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
}

// Function to download document
function downloadDocument(filePath, fileName) {
    // Create a temporary link to download the file
    const link = document.createElement('a');
    link.href = filePath;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Function to review verification (approve/reject)
function reviewVerification(decision) {
    if (!currentVerificationData) {
        alert('No verification data available');
        return;
    }
    
    const adminNotes = document.getElementById('adminNotes').value.trim();
    if (!adminNotes) {
        alert('Please add admin notes before making a decision');
        return;
    }
    
    const actionText = decision === 'approve' ? 'approve' : 'reject';
    if (!confirm(`Are you sure you want to ${actionText} this verification request?`)) {
        return;
    }
    
    // Disable buttons
    document.getElementById('approveBtn').disabled = true;
    document.getElementById('rejectBtn').disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'review_user_verification');
    formData.append('username', currentVerificationData.username);
    formData.append('decision', decision);
    formData.append('admin_notes', adminNotes);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            
            if (data.success) {
                alert(data.message);
                closeVerificationModal();
                // Refresh the page to update the user status
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            alert('Error processing response');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error occurred');
    })
    .finally(() => {
        // Re-enable buttons
        document.getElementById('approveBtn').disabled = false;
        document.getElementById('rejectBtn').disabled = false;
    });
}

// Function to close verification modal
function closeVerificationModal() {
    document.getElementById('verificationModal').classList.add('hidden');
    currentVerificationData = null;
    document.getElementById('adminNotes').value = '';
}

// Update the existing verifyUser function to use the new modal
function verifyUser(username) {
    // Check if user has submitted verification documents
    const formData = new FormData();
    formData.append('action', 'get_user_verification_docs');
    formData.append('username', username);
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.documents && data.documents.length > 0) {
            // User has submitted documents, open verification modal
            openVerificationModal(username);
        } else {
            // No documents submitted, use the old verification method
            if (confirm('This user has not submitted any verification documents. Do you want to verify them anyway?')) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (this.status === 200) {
                        try {
                            const response = JSON.parse(this.responseText);
                            if (response.success) {
                                alert(response.message);
                                window.location.reload();
                            } else {
                                alert('Error: ' + response.message);
                            }
                        } catch (e) {
                            console.error('JSON parsing error:', e);
                            alert('Error processing the response');
                        }
                    }
                };
                xhr.send('action=verify_user&username=' + encodeURIComponent(username));
            }
        }
    })
    .catch(error => {
        console.error('Error checking verification documents:', error);
        // Fallback to old verification method
        if (confirm('Are you sure you want to verify this user?')) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'admin.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        if (response.success) {
                            alert(response.message);
                            window.location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    } catch (e) {
                        console.error('JSON parsing error:', e);
                        alert('Error processing the response');
                    }
                }
            };
            xhr.send('action=verify_user&username=' + encodeURIComponent(username));
        }
    });
}
</script>
</body>
</html>
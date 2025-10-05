<?php
require 'dbconn.php'; // Ensure this file contains your database connection logic

// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login if user is not logged in
    header("Location: signin.php");
    exit;
}

// Prepare and execute the query to fetch the user profile
$stmt = $conn->prepare("SELECT role, profile_image, first_name, last_name FROM cjusers WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

// Fetch the result
if ($user = $result->fetch_assoc()) {
    // Set profile image and role
    $role = $user['role'];
    $profile_image = $user['profile_image'] ? (strpos($user['profile_image'], 'uploads/') === 0 ? $user['profile_image'] : 'uploads/' . $user['profile_image']) : 'img/default.jpg';
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
} else {
    // Default fallback
    $role = 'Guest';
    $profile_image = 'uploads/carlo.jpg';
    $user_name = 'Cashier';
}

$stmt->close();

// Function to count orders for a given status and payment method
function getOrderCount($conn, $status, $paymentMethod, $date = null) {
    $sql = "SELECT COUNT(*) FROM orders WHERE order_status = ? AND payment_method = ?";
    $params = [$status, $paymentMethod];
    $types = "ss";

    if ($date !== null) {
        $sql .= " AND DATE(order_date) = ?";
        $params[] = $date;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to count orders from a specific barangay
function getBarangayOrderCount($conn, $barangayId) {
    $sql = "SELECT COUNT(o.id)
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE u.barangay_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $barangayId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Get counts for COD orders (general - all time)
$pendingCODCount = getOrderCount($conn, 'Pending', 'COD');
$readyToShipCODCount = getOrderCount($conn, 'Ready to Ship', 'COD');
$onShipCODCount = getOrderCount($conn, 'On-Ship', 'COD');

// Get today's counts
$today = date("Y-m-d"); // Get current date in YYYY-MM-DD format
$todayPendingCODCount = getOrderCount($conn, 'Pending', 'COD', $today);
$todayReadyToShipCount = getOrderCount($conn, 'Ready to Ship', 'COD', $today);
$todayOnDeliveryCount = getOrderCount($conn, 'On-Ship', 'COD', $today); // Assuming "On-Ship" is your "On-Delivery" status
$todaySuccessfulCount = getOrderCount($conn, 'Completed', 'COD', $today); // Assuming "Completed" is your "Successful" status

// Fetch all barangays from the barangays table
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);

$barangays = [];
if ($result_barangays->num_rows > 0) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

// Fetch completed transactions with necessary details including delivery fees
$sql_transactions = "SELECT o.id, t.transaction_number, o.order_date,
                               u.first_name, u.last_name, o.total_price, o.payment_method,
                               u.email, u.phone_number, b.barangay_name, u.purok,  -- User info
                               o.rider_name, o.rider_contact, o.rider_motor_type, o.rider_plate_number, -- Rider info
                               o.order_status, o.delivery_method, o.home_description,
                               u.ImagePath,
                               -- Include delivery fee calculation
                               o.delivery_fee, o.total_amount_with_delivery,
                               bf.fare_amount, bf.staff_fare_amount,
                               CASE 
                                   WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                                   ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
                               END AS delivery_fee_effective,
                               CASE 
                                   WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
                                   THEN (o.total_price + 
                                       CASE 
                                           WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                                           ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
                                       END)
                                   ELSE o.total_amount_with_delivery 
                               END AS total_with_delivery_effective
                        FROM orders o
                        JOIN transactions t ON o.id = t.order_id
                        JOIN users u ON o.user_id = u.id
                        JOIN barangays b ON u.barangay_id = b.id
                        LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                        WHERE o.order_status = 'Completed'
                        ORDER BY o.order_date DESC";

$result_transactions = $conn->query($sql_transactions);

$transactions = [];
if ($result_transactions->num_rows > 0) {
    while ($row = $result_transactions->fetch_assoc()) {
        // Format the order_date to Philippine time (AM/PM)
        date_default_timezone_set('Asia/Manila');
        $row['order_date_formatted'] = date("Y-m-d h:i A", strtotime($row['order_date'])); // Format date
        $transactions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Cashier Dashboard - Transactions</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link rel="icon" type="image/png" href="Image/logo.png">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- icon font Stylesheet -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

     <!--libraries stylesheet-->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!--customized Bootstrap Stylesheet-->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!--Template Stylesheet-->
    <link href="css/style.css" rel="stylesheet">
       <style>
      .modal-content {
            background-color: #fff; /* White Background */
            color: #000; /* Black Text */
        }
          .soft-edge-square {
                border-radius: 10px; /* Adjust the radius to control the roundness */
                overflow: hidden; /* Ensures the rounded corners are visible */
            }
            /* Make modal body scrollable so long content fits within viewport */
            #transactionDetailsModal .modal-body { max-height: 70vh; overflow-y: auto; }
   </style>
   <style>
      /* Make transactions table area scrollable */
      .transactions-scroll { max-height: 65vh; overflow-y: auto; }
      
      /* Pagination styling - show only 10 rows with scroll */
      .table-pagination-container {
          max-height: 600px !important; /* Approximately 10 rows height */
          overflow-y: auto !important;
          border: 1px solid #dee2e6 !important;
          border-radius: 8px !important;
      }
      
      .table-pagination-container::-webkit-scrollbar {
          width: 8px !important;
      }
      
      .table-pagination-container::-webkit-scrollbar-track {
          background: #f1f1f1 !important;
          border-radius: 4px !important;
      }
      
      .table-pagination-container::-webkit-scrollbar-thumb {
          background: #888 !important;
          border-radius: 4px !important;
      }
      
      .table-pagination-container::-webkit-scrollbar-thumb:hover {
          background: #555 !important;
      }
      
      /* Ensure table header stays fixed */
      .table-pagination-container .table thead th {
          position: sticky !important;
          top: 0 !important;
          z-index: 10 !important;
          background-color: #212529 !important;
      }
      
      /* Filter button styling */
      .filter-btn {
          transition: all 0.3s ease;
      }
      
      .filter-btn.active {
          background-color: #0d6efd !important;
          border-color: #0d6efd !important;
          color: white !important;
      }
      
      .filter-btn:hover {
          background-color: #0d6efd !important;
          border-color: #0d6efd !important;
          color: white !important;
      }
      
      /* Pagination button styling */
      .btn-outline-light {
          color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          background-color: transparent !important;
      }
      
      .btn-outline-light:hover:not(:disabled) {
          background-color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          color: #212529 !important;
      }
      
      .btn-outline-light:disabled {
          color: #6c757d !important;
          border-color: #6c757d !important;
          background-color: transparent !important;
          opacity: 0.5 !important;
      }
      
      .btn-light {
          background-color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          color: #212529 !important;
      }
      
      /* Date Range Calendar Icon Styling */
      .calendar-icon {
          background-color: #007bff !important;
          border-color: #007bff !important;
          color: white !important;
          cursor: pointer !important;
          transition: all 0.3s ease !important;
      }
      
      .calendar-icon:hover {
          background-color: #0056b3 !important;
          border-color: #0056b3 !important;
          transform: scale(1.05) !important;
      }
      
      .calendar-icon i {
          font-size: 14px !important;
      }
      
      /* Input group styling for dark theme */
      .input-group .form-control {
          background-color: #495057 !important;
          border-color: #6c757d !important;
          color: white !important;
      }
      
      .input-group .form-control:focus {
          background-color: #495057 !important;
          border-color: #007bff !important;
          color: white !important;
          box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
      }
      
      .input-group .form-control::placeholder {
          color: #adb5bd !important;
      }
      
      /* Mobile responsiveness for date inputs */
      @media (max-width: 768px) {
          .input-group {
              margin-bottom: 10px;
          }
          
          .calendar-icon {
              padding: 8px 12px !important;
          }
          
          .calendar-icon i {
              font-size: 12px !important;
          }
      }
   </style>
</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
         <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->
        <!-- Sidebar Start -->
     <!-- Sidebar Start -->
<div class="sidebar pe-4 pb-3">
    <nav class="navbar bg-secondary navbar-dark">
        <div class="navbar-brand mx-4 mb-3">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </div>
        <div class="d-flex align-items-center ms-4 mb-4">
            <div class="position-relative">
                <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
            </div>
            <div class="ms-3">
                <h6 class="mb-0"><?php echo htmlspecialchars($user_name); ?></h6>
                <span id="role">Cashier</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Cashier-Dashboard.php" class="nav-item nav-link"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-shopping-cart me-2"></i>Pending Orders
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Cashier-COD-Delivery.php" class="dropdown-item">Pending COD orders</a>
                    <a href="Cashier-GCASH-Delivery.php" class="dropdown-item">Pending GCASH orders</a>
                </div>
            </div>
            <a href="Cashier-Pickup-Orders.php" class="nav-item nav-link"><i class="fa fa-store me-2"></i>Pickup Orders</a>
            <a href="Cashier-Transactions.php" class="nav-item nav-link active"><i class="fa fa-list-alt me-2"></i>Transactions</a>
            <a href="Cashier-Returns.php" class="nav-item nav-link"><i class="fa fa-undo me-2"></i>Return Product</a>
        </div>
    </nav>
</div>

            
<!-- Sidebar End -->
  <!--Content Start-->
  <div class="content">
    <!--Navbar Start-->
       <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top 
       px-4 py-0">
            <a href="index.php" class="navbar-brand d-flex d-lg-none me-4">
                <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
            </a>
            <a href="#" class="sidebar-toggler flex-shrink-0">
                <i class="fa fa-bars"></i>
            </a>

            <div class="navbar-nav align-items-center ms-auto">
                <div class="nav-item dropdown">
                   
            <div class="dropdown-menu dropdown-menu-end bg-secondary 
            border-0 rounded-0 rounded-bottom m-0">
            <a href="#" class="dropdown-item">
                <div class="d-flex aligns-items-center">
                    <img src="img/johanns.jpg" alt="User Profile"
                    class="rounded-circle" style="width: 40px; height: 
                    40px;">
                    <div class="ms-2">
                        <h6 class="fw-normal mb-0">Johanns send you a 
                        message</h6>
                        <small>5 minutes ago</small>
                </div>
            </div>
            </a>
             <hr class="dropdown-divider">
             <a href="#" class="dropdown-item">
                <div class="d-flex aligns-items-center">
                    <img src="img/carlo.jpg" alt=""
                    class="rounded-circle" style="width: 40px; height: 
                    40px;">
                    <div class="ms-2">
                        <h6 class="fw-normal mb-0">Carlo send you a 
                        message</h6>
                        <small>10 minutes ago</small>
                </div>
            </div>
            </a>
            <hr class="dropdown-divider">
            <a href="#" class="dropdown-item">
                <div class="d-flex aligns-items-center">
                    <img src="img/alquin.jpg" alt=""
                    class="rounded-circle" style="width: 40px; height: 
                    40px;">
                    <div class="ms-2">
                        <h6 class="fw-normal mb-0">Alquin send you a 
                        message</h6>
                        <small>15 minutes ago</small>
                </div>
            </div>
            </a>
            <hr class="dropdown-divider">
            <a href="#" class="dropdown-item text-center">See all 
            Messages</a>
            </div>
    </div>
    <?php include 'cashier_notifications.php'; ?>
    <div class="nav-item dropdown">
        <a href="" class="nav-link dropdown-toggle" 
    data-bs-toggle="dropdown">
        <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle me-lg-2" 
        alt="" style="width: 40px; height: 40px;">
        <span class="d-none d-lg-inline"><?php echo htmlspecialchars($user_name); ?></span>
    </a>
                    <div class="dropdown-menu dropdown-menu-end bg-dark border-0 rounded-3 shadow-lg m-0" style="min-width: 200px;">
                        <div class="dropdown-header text-light border-bottom border-secondary">
                            <small class="text-muted">Account</small>
                        </div>
                        <a href="logout.php" class="dropdown-item text-light d-flex align-items-center py-2">
                            <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                            <span>Log out</span>
                        </a>
                    </div>
    </div>
</div>
</nav>
<!--Navbar End-->

<!-- Transactions History Start -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Completed Transactions History</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary filter-btn active" data-filter="all">All Transactions</button>
                <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="today">Today</button>
                <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="daterange">Date Range</button>
            </div>
        </div>
        
        <!-- Date Range Filter -->
        <div class="row mb-3" id="dateRangeFilter" style="display: none;">
            <div class="col-md-4">
                <label for="fromDate" class="form-label text-white">From Date:</label>
                <div class="input-group">
                    <input type="date" class="form-control" id="fromDate" name="fromDate">
                    <span class="input-group-text calendar-icon" onclick="document.getElementById('fromDate').showPicker()">
                        <i class="fa fa-calendar-alt"></i>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <label for="toDate" class="form-label text-white">To Date:</label>
                <div class="input-group">
                    <input type="date" class="form-control" id="toDate" name="toDate">
                    <span class="input-group-text calendar-icon" onclick="document.getElementById('toDate').showPicker()">
                        <i class="fa fa-calendar-alt"></i>
                    </span>
                </div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-sm btn-primary me-2" id="applyDateFilter">
                    <i class="fa fa-filter"></i> Apply Filter
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="clearDateFilter">
                    <i class="fa fa-times"></i> Clear
                </button>
            </div>
        </div>
        
        <!-- Pagination Info -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="text-muted">
                Showing <span id="showing-start">1</span> to <span id="showing-end">10</span> of <span id="total-transactions"><?php echo count($transactions); ?></span> transactions
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-light" id="prev-page" disabled>
                    <i class="fa fa-chevron-left"></i> Previous
                </button>
                <span class="btn btn-sm btn-light text-dark" id="page-info">Page 1</span>
                <button class="btn btn-sm btn-outline-light" id="next-page">
                    Next <i class="fa fa-chevron-right"></i>
                </button>
            </div>
        </div>
        
        <div class="table-responsive table-pagination-container">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th scope="col">Order ID</th>
                        <th scope="col">Transaction #</th>
                        <th scope="col">Order Date</th>
                        <th scope="col">Customer Name</th>
                        <th scope="col">Total Price</th>
                        <th scope="col">Payment Method</th>
                        <th scope="col">Action</th> <!-- Action Column -->
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaction['id']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['transaction_number']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['order_date_formatted']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                <td>₱<?php echo number_format($transaction['total_with_delivery_effective'] ?? ($transaction['total_price'] + ($transaction['delivery_fee_effective'] ?? 0)), 2); ?></td>
                                <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary view-details-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#transactionDetailsModal"
                                        data-order-id="<?php echo htmlspecialchars($transaction['id']); ?>"
                                        data-transaction-number="<?php echo htmlspecialchars($transaction['transaction_number']); ?>"
                                        data-order-date="<?php echo htmlspecialchars($transaction['order_date_formatted']); ?>"
                                        data-customer-name="<?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?>"
                                        data-customer-email="<?php echo htmlspecialchars($transaction['email']); ?>"
                                        data-customer-phone="<?php echo htmlspecialchars($transaction['phone_number']); ?>"
                                        data-barangay="<?php echo htmlspecialchars($transaction['barangay_name']); ?>"
                                        data-purok="<?php echo htmlspecialchars($transaction['purok']); ?>"
                                        data-rider-name="<?php echo htmlspecialchars($transaction['rider_name']); ?>"
                                        data-rider-contact="<?php echo htmlspecialchars($transaction['rider_contact']); ?>"
                                        data-rider-motor-type="<?php echo htmlspecialchars($transaction['rider_motor_type']); ?>"
                                        data-rider-plate-number="<?php echo htmlspecialchars($transaction['rider_plate_number']); ?>"
                                        data-payment-method="<?php echo htmlspecialchars($transaction['payment_method']); ?>"
                                        data-subtotal-price="<?php echo htmlspecialchars($transaction['total_price']); ?>"
                                        data-delivery-fee="<?php echo htmlspecialchars($transaction['delivery_fee_effective'] ?? 0); ?>"
                                        data-total-price="<?php echo htmlspecialchars($transaction['total_with_delivery_effective'] ?? ($transaction['total_price'] + ($transaction['delivery_fee_effective'] ?? 0))); ?>"
                                        data-delivery-method="<?php echo htmlspecialchars($transaction['delivery_method']); ?>"
                                        data-home-description="<?php echo htmlspecialchars($transaction['home_description']); ?>"
                                        data-image-path="<?php echo htmlspecialchars($transaction['ImagePath']); ?>"
                                    >
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">No completed transactions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Transactions History End -->

<!-- Modal for Transaction Details -->
<div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-labelledby="transactionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title w-100 text-center" id="transactionDetailsModalLabel">Transaction Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Customer Image on the Left -->
                      <div class="text-center mb-3">
                                <img id="modalCustomerImage" src="" alt="Customer Image" class="soft-edge-square" width="250" height="250">
                      </div>
                <!-- Customer Details -->
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Name:</strong></div>
                            <div class="col-sm-7" id="customerName"></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Transaction Number:</strong></div>
                            <div class="col-sm-7" id="transactionNumber"></div>
                        </div>
                         <div class="row mb-2">
                            <div class="col-sm-5"><strong>Order Date:</strong></div>
                            <div class="col-sm-7" id="orderDate"></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Email:</strong></div>
                            <div class="col-sm-7" id="email"></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Phone Number:</strong></div>
                            <div class="col-sm-7" id="phoneNumber"></div>
                        </div>
                   <div class="row mb-2">
                                    <div class="col-5"><strong>Shipping Address:</strong></div>
                                      <div class="col-7" id="shippingAddress"></div>
                                </div>
                <!-- Rider Details -->
                <hr>
                 <div class="mb-4">
                    <h6>Rider Details</h6>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Rider Name:</strong></div>
                            <div class="col-sm-7" id="riderName"></div>
                        </div>
                       <div class="row mb-2">
                            <div class="col-sm-5"><strong>Rider Contact:</strong></div>
                            <div class="col-sm-7" id="riderContact"></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Motor Type:</strong></div>
                            <div class="col-sm-7" id="motorType"></div>
                        </div>
                      <div class="row mb-2">
                            <div class="col-sm-5"><strong>Plate Number:</strong></div>
                            <div class="col-sm-7" id="plateNumber"></div>
                        </div>
              <hr>
                <!-- Order Details -->
                 <div class="mb-4">
                    <h6>Order Details</h6>
                        </div>
                         <div class="row mb-2">
                            <div class="col-sm-5"><strong>Subtotal:</strong></div>
                            <div class="col-sm-7" id="subtotalPrice"></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-5"><strong>Delivery Fee:</strong></div>
                            <div class="col-sm-7" id="deliveryFee"></div>
                        </div>
                         <div class="row mb-2">
                            <div class="col-sm-5"><strong>Total Amount:</strong></div>
                            <div class="col-sm-7" id="totalPrice"></div>
                        </div>
                         <div class="row mb-2">
                            <div class="col-sm-5"><strong>Payment Method:</strong></div>
                            <div class="col-sm-7" id="paymentMethod"></div>
                        </div>
                          <div class="row mb-2">
                            <div class="col-sm-5"><strong>Delivery Method:</strong></div>
                            <div class="col-sm-7" id="deliveryMethod"></div>
                        </div>
                            <div class="row mb-2">
                                    <div class="col-sm-5"><strong>Home Description:</strong></div>
                                    <div class="col-sm-7" id="homeDescription"></div>
                            </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript to handle modal population -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const detailButtons = document.querySelectorAll('.view-details-btn');

        detailButtons.forEach(button => {
            button.addEventListener('click', function() {
                const transaction = this.dataset; // Get all data attributes in one object
                const modal = document.getElementById('transactionDetailsModal'); // Get the modal

                //Modal Customer Image
                  modal.querySelector("#modalCustomerImage").src = transaction.imagePath || ''; // Set profile picture (with fallback)
                modal.querySelector("#customerName").innerText = transaction.customerName || '';
                modal.querySelector("#transactionNumber").innerText = transaction.transactionNumber || '';
                modal.querySelector("#orderDate").innerText = transaction.orderDate || '';
                modal.querySelector("#email").innerText = transaction.customerEmail || '';
                modal.querySelector("#phoneNumber").innerText = transaction.customerPhone || '';
               modal.querySelector("#shippingAddress").innerText = 'Purok: ' + transaction.purok + ', Barangay: ' + transaction.barangay + ', Valencia City';

                // Set other modal content
                modal.querySelector("#riderName").innerText = transaction.riderName || '';
                 modal.querySelector("#riderContact").innerText = transaction.riderContact || '';
                modal.querySelector("#motorType").innerText = transaction.riderMotorType || '';
                modal.querySelector("#plateNumber").innerText = transaction.riderPlateNumber || '';
              modal.querySelector("#paymentMethod").innerText = transaction.paymentMethod || '';
                modal.querySelector("#subtotalPrice").innerText = '₱' + parseFloat(transaction.subtotalPrice || 0).toFixed(2);
                modal.querySelector("#deliveryFee").innerText = '₱' + parseFloat(transaction.deliveryFee || 0).toFixed(2);
                 modal.querySelector("#totalPrice").innerText = '₱' + parseFloat(transaction.totalPrice || 0).toFixed(2);
              modal.querySelector("#deliveryMethod").innerText = transaction.deliveryMethod || '';
              modal.querySelector("#homeDescription").innerText = transaction.homeDescription || '';

            });
        });
    });
</script>

<!-- JavaScript for Filtering and Pagination -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get all transaction rows
    const allRows = Array.from(document.querySelectorAll('tbody tr'));
    const rowsPerPage = 10;
    let currentPage = 1;
    let currentFilter = 'all';
    let filteredRows = allRows;

    // Filter buttons
    const filterButtons = document.querySelectorAll('.filter-btn');
    const prevButton = document.getElementById('prev-page');
    const nextButton = document.getElementById('next-page');
    const pageInfo = document.getElementById('page-info');
    const showingStart = document.getElementById('showing-start');
    const showingEnd = document.getElementById('showing-end');
    const totalTransactions = document.getElementById('total-transactions');

    // Filter functionality
    function filterTransactions(filter) {
        currentFilter = filter;
        currentPage = 1; // Reset to first page when filtering

        if (filter === 'today') {
            const today = new Date().toISOString().split('T')[0]; // Get today's date in YYYY-MM-DD format
            filteredRows = allRows.filter(row => {
                const dateCell = row.cells[2]; // Order Date column
                const dateText = dateCell.textContent.trim();
                const rowDate = dateText.split(' ')[0]; // Extract date part (YYYY-MM-DD)
                return rowDate === today;
            });
        } else if (filter === 'daterange') {
            // Show date range inputs
            document.getElementById('dateRangeFilter').style.display = 'block';
            filteredRows = allRows; // Don't filter yet, wait for apply button
        } else {
            // Hide date range inputs for other filters
            document.getElementById('dateRangeFilter').style.display = 'none';
            filteredRows = allRows;
        }

        updatePagination();
        updateDisplay();
    }
    
    // Date range filtering function
    function filterByDateRange() {
        const fromDate = document.getElementById('fromDate').value;
        const toDate = document.getElementById('toDate').value;
        
        if (!fromDate || !toDate) {
            alert('Please select both From Date and To Date');
            return;
        }
        
        if (fromDate > toDate) {
            alert('From Date cannot be after To Date');
            return;
        }
        
        currentPage = 1;
        filteredRows = allRows.filter(row => {
            const dateCell = row.cells[2]; // Order Date column
            const dateText = dateCell.textContent.trim();
            const rowDate = dateText.split(' ')[0]; // Extract date part (YYYY-MM-DD)
            return rowDate >= fromDate && rowDate <= toDate;
        });
        
        updatePagination();
        updateDisplay();
    }
    
    // Clear date range filter
    function clearDateRange() {
        const fromDateInput = document.getElementById('fromDate');
        const toDateInput = document.getElementById('toDate');
        
        // Clear values and reset restrictions
        fromDateInput.value = '';
        toDateInput.value = '';
        fromDateInput.min = '';
        fromDateInput.max = '';
        toDateInput.min = '';
        toDateInput.max = '';
        
        document.getElementById('dateRangeFilter').style.display = 'none';
        
        // Reset to all transactions
        currentFilter = 'all';
        filteredRows = allRows;
        currentPage = 1;
        
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('[data-filter="all"]').classList.add('active');
        
        updatePagination();
        updateDisplay();
    }

    // Pagination functionality
    function updatePagination() {
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        
        // Update pagination buttons
        prevButton.disabled = currentPage === 1;
        nextButton.disabled = currentPage === totalPages || totalPages === 0;
        
        // Update page info
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        // Update showing info
        const start = filteredRows.length === 0 ? 0 : (currentPage - 1) * rowsPerPage + 1;
        const end = Math.min(currentPage * rowsPerPage, filteredRows.length);
        
        showingStart.textContent = start;
        showingEnd.textContent = end;
        totalTransactions.textContent = filteredRows.length;
    }

    function updateDisplay() {
        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');
        
        // Show rows for current page
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = startIndex + rowsPerPage;
        
        for (let i = startIndex; i < endIndex && i < filteredRows.length; i++) {
            filteredRows[i].style.display = '';
        }
    }

    // Event listeners
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Update active button
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Apply filter
            filterTransactions(this.dataset.filter);
        });
    });
    
    // Date range filter event listeners
    document.getElementById('applyDateFilter').addEventListener('click', filterByDateRange);
    document.getElementById('clearDateFilter').addEventListener('click', clearDateRange);
    
    // Date input event listeners for dynamic validation
    document.getElementById('fromDate').addEventListener('change', function() {
        const fromDate = this.value;
        const toDateInput = document.getElementById('toDate');
        
        if (fromDate) {
            // Set minimum date for "To Date" to be the same as "From Date"
            toDateInput.min = fromDate;
            
            // If "To Date" is already selected and is before "From Date", clear it
            if (toDateInput.value && toDateInput.value < fromDate) {
                toDateInput.value = '';
            }
        } else {
            // If "From Date" is cleared, remove the min restriction
            toDateInput.min = '';
        }
    });
    
    document.getElementById('toDate').addEventListener('change', function() {
        const toDate = this.value;
        const fromDateInput = document.getElementById('fromDate');
        
        if (toDate) {
            // Set maximum date for "From Date" to be the same as "To Date"
            fromDateInput.max = toDate;
            
            // If "From Date" is already selected and is after "To Date", clear it
            if (fromDateInput.value && fromDateInput.value > toDate) {
                fromDateInput.value = '';
            }
        } else {
            // If "To Date" is cleared, remove the max restriction
            fromDateInput.max = '';
        }
    });

    prevButton.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updatePagination();
            updateDisplay();
        }
    });

    nextButton.addEventListener('click', function() {
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updatePagination();
            updateDisplay();
        }
    });

    // Initialize display
    updatePagination();
    updateDisplay();
});
</script>
   <!--javascript Libraries-->
   <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
   <script src="lib/chart/Chart.min.js"></script>
   <script src="js/notification-sound.js"></script>
   <script src="lib/easing/easing.min.js"></script>
   <script src="lib/waypoints/waypoints.min.js"></script>
   <script src="lib/owlcarousel/owl.carousel.min.js"></script>
   <script src="lib/tempusdominus/js/moment.min.js"></script>
   <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
   <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
   
   

    <!-- Template Javascript -->
    <script src="js/main.js">
    </script>
        <!--Footer Start-->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded-top p-4">
        <div class="row">
            <div class="col-12 col-sm-6 text-center text-sm-start">
                © <a href="#">Cj PowerHouse</a>, All Right Reserved.
            </div> 
            <div class="col-12 col-sm-6 text-center text-sm-end">
                Design By: <a href="">Team Jandi</a>
            </div>
        </div>
    </div>
</div>
<!--Footer End-->
<script>
     window.addEventListener('DOMContentLoaded', function() {
         setTimeout(function() {
             var spinner = document.getElementById('spinner');
             if (spinner) spinner.style.display = 'none';
         }, 500); // 0.5 second delay
     });
     </script>
</body>
</html>
<?php $conn->close(); ?>
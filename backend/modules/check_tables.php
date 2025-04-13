<?php
    include '../config/connection.php';
    
    // Get all table numbers from que_orders
    $query = "SELECT table_number, receipt_number FROM que_orders";
    $result = $conn->query($query);

    $tableStatus = [];

    // Initialize all possible tables
    // Indoor tables (1-20)
    for ($i = 1; $i <= 20; $i++) {
        $tableStatus[$i] = false;
    }
    // Outdoor tables (O1-O15)
    for ($i = 1; $i <= 15; $i++) {
        $tableStatus["O$i"] = false;
    }
    // Garden tables (G1-G6)
    for ($i = 1; $i <= 6; $i++) {
        $tableStatus["G$i"] = false;
    }

    // Update status for occupied tables
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tableStatus[$row['table_number']] = !empty($row['receipt_number']);
        }
    }

    header('Content-Type: application/json');
    echo json_encode($tableStatus);
?>
    
    
    
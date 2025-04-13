<?php
require_once '../config/connection.php';

// Get the JSON data from the request
$data = json_decode(file_get_contents("php://input"), true);

// Start a transaction
$conn->begin_transaction();

try {
    // Insert into remit_sales table
    $stmt = $conn->prepare("INSERT INTO remit_sales (cashier_name, total_sales, remit_date, remit_time, remit_shortage, remit_validation, shift) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", 
        $data['cashier_name'], 
        $data['total_sales'], 
        $data['remit_date'], 
        $data['remit_time'], 
        $data['remit_shortage'], 
        $data['remit_validation'],
        $data['shift']
    );
    $stmt->execute();
    $remit_id = $conn->insert_id;

    // Insert sales data into remit_sales_data table
    if (isset($data['sales_data']) && is_array($data['sales_data'])) {
        $stmt = $conn->prepare("INSERT INTO remit_sales_data (remit_id, receipt_number, items_ordered, total_amount, amount_paid, amount_change, order_date, order_time, order_take, cashier_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($data['sales_data'] as $sale) {
            $stmt->bind_param("isssssssss", 
                $remit_id,
                $sale['receipt'],
                $sale['items_ordered'],
                $sale['totalCost'],
                $sale['payAmount'],
                $sale['changeDue'],
                $sale['orderDate'],
                $sale['orderTime'],
                $sale['orderIn'],
                $sale['name']
            );
            $stmt->execute();
        }
    }

    // Commit the transaction
    $conn->commit();
    echo json_encode(["success" => true, "message" => "Remit and sales data saved successfully."]);

} catch (Exception $e) {
    // Rollback the transaction on error
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}

// Close connections
$stmt->close();
$conn->close();
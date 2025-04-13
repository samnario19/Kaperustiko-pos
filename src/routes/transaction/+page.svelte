<script lang="ts">
    import { onMount } from "svelte";
    import Sidebar from "../sidebar/+page.svelte";
  
    // Define the type for a sale
    type Sale = {
        receipt: string;
        items: string;
        order_name2: string;
        order_quantity: string;
        order_size: string;
        price: string;
        totalCost: string;
        payAmount: string;
        changeDue: string;
        orderDate: string;
        orderTime: string;
        orderIn: string;
        name: string;
        totalDiscount: string;
        items_ordered: string;
    };
  
    // Add this type definition near your Sale type
    type OrderItem = {
        order_name: string;
        order_name2: string;
        order_size: string;
        order_quantity: string;
    };
  
    let recentSales: Sale[] = [];
  
    let selectedDate: Date = new Date(); // Change to Date object
  
    let noSalesData: boolean = false; // New variable to track no sales data
  
    let showPopup: boolean = false; // New variable to control popup visibility
    let totalSales: number = 0; // Variable to store total sales
    let shortage: number = 0.00; // New variable to store shortage input
  
    let showSecondPopup: boolean = false; // New variable to control second popup visibility
  
    let inputCode: string = ""; // Declare inputCode variable
  
    let isRemitDisabled: boolean = false; // New variable to track if remit is disabled
  
    // Add a new variable to control the return confirmation popup
    let showReturnConfirmation: boolean = false; // New variable for return confirmation
    let selectedReceipt: string | null = null; // Variable to store the selected receipt for return
  
    let selectedShift: string = 'morning'; // Add shift selection
    let showEndReport: boolean = false; // Add end report visibility
    let endReportData: any = {}; // Add end report data
  
    // Add new type for end report with detailed categories
    type FoodCategory = {
        appetizer: number;
        salad: number;
        riceMeal: number;
        steakAndSalmon: number;
        pasta: number;
        sandwich: number;
        pizza: number;
        soup: number;
        breakfast: number;
        sideDish: number;
        chicken: number;
        pork: number;
        beef: number;
        specialty: number;
        vegetables: number;
        fish: number;
    };

    type DrinksCategory = {
        frappe: number;
        icedCoffee: number;
        hotCoffee: number;
        soda: number;
        fruitShake: number;
        beverage: number;
        juice: number;
    };

    type EndReport = {
        morningShift: {
            startTime: string;
            endTime: string;
            totalSales: number;
            foodCount: number;
            coffeeCount: number;
            remitTime: string;
            cashierName: string;
            categories: {
                food: FoodCategory;
                drinks: DrinksCategory;
            };
        };
        nightShift: {
            startTime: string;
            endTime: string;
            totalSales: number;
            foodCount: number;
            coffeeCount: number;
            remitTime: string;
            cashierName: string;
            categories: {
                food: FoodCategory;
                drinks: DrinksCategory;
            };
        };
        totalFood: number;
        totalCoffee: number;
        grandTotal: number;
    };
  
    // Function to get all date options for sorting
    function getAllDateOptions() {
        const options: string[] = [];
        const startYear = 2024; // Set minimum year to 2024
        const startMonth = 10; // November (0-indexed)
        const startDay = 1; // Start from the 1st
        const today = new Date();
        const endYear = today.getFullYear(); // Current year

        for (let year = startYear; year <= endYear; year++) {
            for (let month = (year === startYear ? startMonth : 0); month < 12; month++) {
                const daysInMonth = new Date(year, month + 1, 0).getDate(); // Get the number of days in the month
                for (let day = (year === startYear && month === startMonth ? startDay : 1); day <= daysInMonth; day++) {
                    const date = new Date(year, month, day);
                    options.push(date.toISOString().split('T')[0]); // Format as YYYY-MM-DD
                }
            }
        }
        return options;
    }
  
    // Initialize dateOptions with all possible dates
    const dateOptions: string[] = getAllDateOptions();
  
    // Function to handle date change
    function handleDateChange(event: Event) {
        const target = event.target as HTMLSelectElement;
        selectedDate = new Date(target.value); // Update to create a Date object

        // Fetch sales data for the selected date
        fetchSalesData();
    }
  
    // New function to fetch sales data
    async function fetchSalesData() {
        const formattedDate = selectedDate.toLocaleDateString('en-US');
        
        const apiUrl = `http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getSalesInformation&date=${formattedDate}`;
        console.log("API URL:", apiUrl);

        const response = await fetch(apiUrl);
        const data = await response.json();

        if (Array.isArray(data) && data.length > 0) {
            noSalesData = false;
            
            // Process all sales data
            recentSales = data.map((sale) => {
                if (new Date(sale.date).toLocaleDateString('en-US') === formattedDate) {
                    try {
                        const itemsOrdered = JSON.parse(sale.items_ordered.replace(/\\/g, ''));
                        
                        if (!Array.isArray(itemsOrdered)) {
                            throw new Error("Parsed itemsOrdered is not an array");
                        }

                        return {
                            receipt: sale.receipt_number,
                            items: itemsOrdered.map((item: { order_name: string }) => item.order_name).join(", "),
                            order_name2: itemsOrdered.map((item: { order_name2: string }) => item.order_name2).join(", "),
                            order_quantity: itemsOrdered.map((item: { order_quantity: string }) => item.order_quantity).join(", "),
                            order_size: itemsOrdered.map((item: { order_size: string }) => item.order_size).join(", "),
                            price: itemsOrdered.map((item: { price: string }) => item.price).join(", "),
                            totalCost: `₱${sale.total_amount}`,
                            payAmount: `₱${sale.amount_paid}`,
                            changeDue: `₱${sale.amount_change}`,
                            orderDate: sale.date,
                            orderTime: sale.time,
                            orderIn: sale.order_take,
                            name: sale.cashier_name,
                            totalDiscount: "₱0.00",
                            items_ordered: sale.items_ordered,
                        };
                    } catch (error) {
                        console.error("Error parsing items_ordered:", error);
                        console.error("Invalid items_ordered data:", sale.items_ordered);
                        return null;
                    }
                }
                return null;
            }).filter(sale => sale !== null);
        } else if (data.message) {
            console.error("API Response Message:", data.message);
            noSalesData = true;
        } else {
            console.error("Unexpected API response:", data);
            noSalesData = true;
        }

        await checkRemitExists();
    }
  
    onMount(() => {
        fetchSalesData(); // Fetch initial sales data on mount
        const intervalId = setInterval(fetchSalesData, 1000); // Update sales data every second

        return () => clearInterval(intervalId); // Clear interval on component unmount
    });
  
    // Function to calculate total sales
    function calculateTotalSales() {
        totalSales = recentSales.reduce((sum, sale) => {
            const amount = parseFloat(sale.totalCost.replace(/₱/, '')); // Remove currency symbol
            return sum + (isNaN(amount) ? 0 : amount);
        }, 0);
    }
  
    // Function to handle Remit button click
    function handleRemitClick() {
        calculateTotalSales();
        showPopup = true;
    }
  
    // Function to close the popup
    function closePopup() {
        showPopup = false;
        shortage = 0; // Reset shortage when closing the popup
    }
  
    // Function to handle Submit button click
    function handleSubmitClick() {
        // Save the shortage value in local storage when submitting
        localStorage.setItem('shortage', shortage.toString()); // Save as string

        // Log the shortage value to the console
        console.log("Shortage value saved to local storage:", shortage);

        closePopup(); // Close the first popup
        showSecondPopup = true; // Open the second popup
    }
  
    // Function to close the second popup
    function closeSecondPopup() {
        showSecondPopup = false; // Close the second popup
    }
  
    // Update the confirmRemit function
    function confirmRemit() {
        if (inputCode !== "123456") {
            showAlert("Wrong code. Please try again.", "error");
            return;
        }
        
        // Get current time to determine shift
        const currentHour = new Date().getHours();
        const isMorningShift = currentHour >= 6 && currentHour < 14;
        
        // Prepare data to send
        const remitData = {
            cashier_name: recentSales[0]?.name,
            total_sales: totalSales.toFixed(2),
            remit_date: selectedDate.toISOString().split('T')[0],
            remit_time: new Date().toLocaleTimeString(),
            remit_shortage: (localStorage.getItem('shortage') || "0").toString(),
            remit_validation: "Validated",
            shift: isMorningShift ? "morning" : "night",
            sales_data: recentSales.map(sale => ({
                receipt_number: sale.receipt,
                items_ordered: sale.items_ordered,
                total_amount: sale.totalCost.replace(/₱/, ''),
                amount_paid: sale.payAmount.replace(/₱/, ''),
                amount_change: sale.changeDue.replace(/₱/, ''),
                order_date: sale.orderDate,
                order_time: sale.orderTime,
                order_take: sale.orderIn,
                cashier_name: sale.name
            }))
        };

        console.log("Remit Data:", remitData);

        // Send data to the backend
        fetch('http://localhost/kaperustiko-possystem/backend/modules/remit_sales.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(remitData),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showAlert("Sales remit successfully.", "success");
                console.log("Remit confirmed with code:", inputCode);
                isRemitDisabled = true;
                
                // Clear the recent sales display but keep data for end-of-day report
                recentSales = [];
                noSalesData = true;
            } else {
                showAlert("Failed to remit sales. Please try again.", "error");
                console.error("Error response:", data.message);
            }
        })
        .catch(error => {
            console.error("Error:", error);
            showAlert("An error occurred. Please try again.", "error");
        });

        localStorage.removeItem('shortage');
        closeSecondPopup();
    }
  
    // Updated showAlert function to include a success message
    function showAlert(message: string, type: string) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `fixed top-0 left-1/2 transform -translate-x-1/2 mt-4 p-4 ${type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white rounded shadow-lg z-50`;
        alertDiv.innerText = message;
        document.body.appendChild(alertDiv);
        setTimeout(() => {
            alertDiv.remove();
        }, 3000); // Remove alert after 3 seconds
    }
  
    // New function to handle pending action
    function handlePendingClick() {
        showAlert("Sales has been moved to the pending queue.", "success"); // Show alert for pending action
        
        // Prepare data to send for pending action
        const pendingData = {
            cashier_name: recentSales[0]?.name, // Assuming the cashier name is from the first sale
            total_sales: totalSales.toFixed(2), // Format total sales
            remit_date: selectedDate.toISOString().split('T')[0], // Format date as YYYY-MM-DD
            remit_time: new Date().toLocaleTimeString(), // Get current time
            remit_shortage: (localStorage.getItem('shortage') || "0").toString(), // Get shortage from local storage
            remit_validation: "Pending" // Set validation status to Pending
        };

        console.log("Pending Data:", pendingData); // Log pendingData to check values

        // Send data to the backend for pending action
        fetch('http://localhost/kaperustiko-possystem/backend/modules/remit_sales.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(pendingData),
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert("Sales moved to pending successfully.", "success"); // Show alert for successful pending
            } else {
                showAlert("Failed to move sales to pending. Please try again.", "error");
            }
        })
        .catch(error => {
            console.error("Error:", error);
            showAlert("An error occurred. Please try again.", "error");
        });

        // Clear shortage from local storage
        localStorage.removeItem('shortage'); // Clear shortage from local storage

        closeSecondPopup(); // Close the second popup after confirmation
    }
  
    function formatNumber(value: number): string {
        return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
  
    // Function to handle shortage input
    function handleShortageInput(event: Event) {
        const target = event.target as HTMLInputElement;
        const rawValue = target.value.replace(/,/g, ''); // Remove commas for parsing
        shortage = parseFloat(rawValue) || 0; // Update shortage

        // Format the input value with commas
        target.value = formatNumber(shortage);
    }
  
    // Function to load shortage from local storage on mount
    onMount(() => {
        const storedShortage = localStorage.getItem('shortage');
        if (storedShortage) {
            shortage = parseFloat(storedShortage); // Load shortage from local storage
        }
    });
  
    // Function to get the current shortage value
    function getShortageValue() {
        console.log("Current shortage value:", shortage); // Log the current shortage value
    }
  
    // New function to check if both shifts have remitted for the selected date
    async function checkRemitExists() {
        const formattedDate = selectedDate.toISOString().split('T')[0]; // Format date as YYYY-MM-DD
        const apiUrl = `http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getRemitSales&date=${formattedDate}`;
        
        const response = await fetch(apiUrl);
        const data = await response.json();

        // Check if both shifts have remitted
        if (data.length >= 2) {
            // Count remits for each shift
            let morningRemits = 0;
            let nightRemits = 0;

            data.forEach((remit: any) => {
                const remitHour = new Date(`${remit.remit_date} ${remit.remit_time}`).getHours();
                if (remitHour >= 6 && remitHour < 14) {
                    morningRemits++;
                } else {
                    nightRemits++;
                }
            });

            // Disable remit only if both shifts have remitted
            isRemitDisabled = morningRemits > 0 && nightRemits > 0;
        } else {
            isRemitDisabled = false;
        }
    }
  
    // Function to handle Return button click
    function handleReturn(receipt: string) {
        const saleToReturn = recentSales.find(sale => sale.receipt === receipt); // Find the selected sale
        if (saleToReturn) {
            selectedReceipt = saleToReturn.receipt; // Store the selected receipt for confirmation
            showReturnConfirmation = true; // Show return confirmation popup
        }
    }

    // New function to delete the sale from total_sales
    function deleteSale(receipt: string) {
        fetch('http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=deleteSalesInformation', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ receipt_number: receipt }),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`); // Log HTTP errors
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Refresh the sales data after deletion
                fetchSalesData();
            } else {
                console.error("Failed to delete sale:", data.message);
            }
        })
        .catch(error => {
            console.error("Error deleting sale:", error);
        });
    }
  
    // New function to confirm the return action
    async function confirmReturn() {
        const saleToReturn = recentSales.find(sale => sale.receipt === selectedReceipt); // Find the selected sale
        if (saleToReturn) {
            const returnData = {
                action: 'return_order',
                receipt_number: saleToReturn.receipt,
                return_date: saleToReturn.orderDate,
                return_time: saleToReturn.orderTime,
                cashier_name: saleToReturn.name,
                items_ordered: saleToReturn.items_ordered,
                total_amount: saleToReturn.totalCost.replace(/₱/, ''), // Remove currency symbol
                amount_paid: saleToReturn.payAmount.replace(/₱/, ''), // Remove currency symbol
                amount_change: saleToReturn.changeDue.replace(/₱/, ''), // Remove currency symbol
                order_take: saleToReturn.orderIn,
            };

            try {
                const response = await fetch('http://localhost/kaperustiko-possystem/backend/modules/insert.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(returnData),
                });

                const data = await response.json();
                if (data.success) {
                    // Check if selectedReceipt is not null before calling deleteSale
                    if (selectedReceipt) { 
                        deleteSale(selectedReceipt); // Call deleteSale to remove the sale from the database
                    }
                    showAlert("Return processed successfully.", "success"); // Show success alert
                    location.reload(); // Reload the page after successful return
                } else {
                    console.error("Failed to process return:", data.message);
                }
            } catch (error) {
                console.error("Error:", error);
                showAlert("An error occurred. Please try again.", "error");
            }
        }

        showReturnConfirmation = false; // Close the confirmation popup
    }
  
    // New function to close the return confirmation popup
    function closeReturnConfirmation() {
        showReturnConfirmation = false; // Close the confirmation popup
    }
  
    // Update the printTable function
    function printTable() {
        // Calculate total sales first
        calculateTotalSales();
        
        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        if (!printWindow) return;

        // Create the print content with styles
        const printContent = `
            <html>
            <head>
                <title>Sales Report</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .header img { max-width: 100px; margin-bottom: 10px; }
                    .business-info { text-align: center; margin-bottom: 10px; font-size: 14px; }
                    .date { text-align: center; margin-bottom: 10px; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <img src="http://localhost/kaperustiko-possystem/src/lib/images/logo.png" alt="Kape Rustiko Logo">
                    <h1>KAPE RUSTIKO</h1>
                    <div class="business-info">
                        <p>Brgy. Singcang Airport, Bacolod City</p>
                        <p>Contact: 0912-345-6789</p>
                        <p>Email: kaperustiko@gmail.com</p>
                    </div>
                    <h2>Sales Report</h2>
                </div>
                <div class="date">
                    Date: ${selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Items</th>
                            <th>Total Cost</th>
                            <th>Pay Amount</th>
                            <th>Change Due</th>
                            <th>Order Date</th>
                            <th>Order Time</th>
                            <th>Order Type</th>
                            <th>Cashier</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each recentSales as sale, i}
                            <tr class="border-t border-gray-300 {i % 2 === 0 ? 'bg-blue-50' : ''}">
                                <td class="p-2 text-center border-b">{sale.receipt}</td>
                                <td class="p-2 text-left border-b">
                                    <ul class="list-none pl-5">
                                        {#each JSON.parse(sale.items_ordered) as item}
                                            <li class="flex flex-col mb-1">
                                                <span class="font-semibold">{item.order_name} {item.order_name2}</span>
                                                <span class="text-gray-600 text-sm">{item.order_size} {item.order_quantity}</span>
                                                <ul class="list-disc pl-5 mt-1">
                                                    {#if item.order_addons && item.order_addons !== 'None'}
                                                        <li class="text-sm text-gray-600">{item.order_addons} ₱{item.order_addons_price}.00</li>
                                                    {/if}
                                                    {#if item.order_addons2 && item.order_addons2 !== 'None'}
                                                        <li class="text-sm text-gray-600">{item.order_addons2} ₱{item.order_addons_price2}.00</li>
                                                    {/if}
                                                    {#if item.order_addons3 && item.order_addons3 !== 'None'}
                                                        <li class="text-sm text-gray-600">{item.order_addons3} ₱{item.order_addons_price3}.00</li>
                                                    {/if}
                                                </ul>
                                            </li>
                                        {/each}
                                    </ul>
                                </td>
                                <td class="p-2 text-center border-b">{sale.totalCost}</td>
                                <td class="p-2 text-center border-b">{sale.payAmount}</td>
                                <td class="p-2 text-center border-b">{sale.changeDue}</td>
                                <td class="p-2 text-center border-b">{new Date(sale.orderDate).toLocaleDateString()}</td>
                                <td class="p-2 text-center border-b">{sale.orderTime}</td>
                                <td class="p-2 text-center border-b">{sale.orderIn}</td>
                                <td class="p-2 text-center border-b">{sale.name}</td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
                <div style="margin-top: 20px; text-align: right;">
                    <p><strong>Total Sales:</strong> ₱${totalSales.toFixed(2)}</p>
                </div>
            </body>
            </html>
        `;

        // Write content to the new window and print
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.focus();
        
        // Print after images and resources are loaded
        printWindow.onload = function() {
            printWindow.print();
            printWindow.close();
        };
    }
  
    // Add function to categorize items
    function categorizeItem(label: string, label2: string): { type: 'food' | 'drinks'; category: keyof FoodCategory | keyof DrinksCategory } {
        // Food categories
        if (label === 'Appetizer') return { type: 'food', category: 'appetizer' };
        if (label === 'Salad') return { type: 'food', category: 'salad' };
        if (label === 'Rice Meal') return { type: 'food', category: 'riceMeal' };
        if (label === 'Steak And Salmon') return { type: 'food', category: 'steakAndSalmon' };
        if (label === 'Pasta') return { type: 'food', category: 'pasta' };
        if (label === 'Sandwich') return { type: 'food', category: 'sandwich' };
        if (label === 'Pizza') return { type: 'food', category: 'pizza' };
        if (label === 'Soup') return { type: 'food', category: 'soup' };
        if (label === 'Breakfast Menu') return { type: 'food', category: 'breakfast' };
        if (label === 'Side Dish Menu') return { type: 'food', category: 'sideDish' };
        if (label === 'Chicken') return { type: 'food', category: 'chicken' };
        if (label === 'Pork') return { type: 'food', category: 'pork' };
        if (label === 'Beef') return { type: 'food', category: 'beef' };
        if (label === 'Specialty') return { type: 'food', category: 'specialty' };
        if (label === 'Vegetables') return { type: 'food', category: 'vegetables' };
        if (label === 'Fish') return { type: 'food', category: 'fish' };

        // Drink categories
        if (label === 'Frappe') return { type: 'drinks', category: 'frappe' };
        if (label === 'Iced Coffee') return { type: 'drinks', category: 'icedCoffee' };
        if (label === 'Hot Coffee') return { type: 'drinks', category: 'hotCoffee' };
        if (label === 'Soda') return { type: 'drinks', category: 'soda' };
        if (label === 'Fruit Shake') return { type: 'drinks', category: 'fruitShake' };
        if (label === 'Beverage') return { type: 'drinks', category: 'beverage' };
        if (label === 'Juice') return { type: 'drinks', category: 'juice' };

        return { type: 'food', category: 'specialty' }; // Default category
    }

    // Add this function after the other functions but before the script end
    function parseFoodItems(items: string): { name: string; quantity: number }[] {
        try {
            const parsedItems = JSON.parse(items);
            return parsedItems.map((item: any) => ({
                name: item.order_name,
                quantity: item.quantity
            }));
        } catch (error) {
            console.error('Error parsing food items:', error);
            return [];
        }
    }

    async function generateEndReport(): Promise<void> {
        try {
            const morningShift = {
                start: '06:00:00',
                end: '14:00:00'
            };

            const nightShift = {
                start: '14:00:00',
                end: '22:00:00'
            };

            // Get all sales for the selected date
            const formattedDate = selectedDate.toLocaleDateString('en-US');
            const response = await fetch(`http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getSalesInformation&date=${formattedDate}`);
            const salesData = await response.json();

            // Get remit data
            const remitResponse = await fetch(`http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getRemitSales&date=${formattedDate}`);
            const remitData = await remitResponse.json();

            // Initialize counters
            let totalSales = 0;
            let totalShortage = 0;
            let totalFood = 0;
            let totalCoffee = 0;
            let foodItems: { [key: string]: number } = {};

            // Process morning shift
            const morningSales = salesData.filter((sale: any) => {
                const saleTime = new Date(`1970-01-01T${sale.time}`);
                const startTime = new Date(`1970-01-01T${morningShift.start}`);
                const endTime = new Date(`1970-01-01T${morningShift.end}`);
                return saleTime >= startTime && saleTime < endTime;
            });

            // Process night shift
            const nightSales = salesData.filter((sale: any) => {
                const saleTime = new Date(`1970-01-01T${sale.time}`);
                const startTime = new Date(`1970-01-01T${nightShift.start}`);
                const endTime = new Date(`1970-01-01T${nightShift.end}`);
                return saleTime >= startTime && saleTime < endTime;
            });

            // Process all sales to count food items
            salesData.forEach((sale: any) => {
                try {
                    // Clean and parse the items_ordered string
                    let itemsString = sale.items_ordered;
                    // Remove any backslashes
                    itemsString = itemsString.replace(/\\/g, '');
                    // Remove any extra quotes
                    itemsString = itemsString.replace(/^"|"$/g, '');
                    
                    const items = JSON.parse(itemsString);
                    
                    if (Array.isArray(items)) {
                        items.forEach((item: any) => {
                            // Parse quantity from "x2" format
                            const quantity = parseInt(item.order_quantity.replace('x', '')) || 1;
                            const itemName = `${item.order_name} ${item.order_name2}`.trim();
                            
                            if (!foodItems[itemName]) {
                                foodItems[itemName] = 0;
                            }
                            foodItems[itemName] += quantity;

                            if (itemName.toLowerCase().includes('coffee')) {
                                totalCoffee += quantity;
                            } else {
                                totalFood += quantity;
                            }
                        });

                        // Add to total sales
                        totalSales += parseFloat(sale.total_amount);
                    }
                } catch (error) {
                    console.error('Error processing sale:', error);
                    console.error('Problematic items_ordered:', sale.items_ordered);
                }
            });

            // Calculate total shortage from remit data
            totalShortage = remitData.reduce((sum: number, remit: any) => sum + (parseFloat(remit.remit_shortage) || 0), 0);

            // Create the report
            const report = {
                date: selectedDate,
                morningShift: {
                    sales: morningSales.reduce((sum: number, sale: any) => sum + parseFloat(sale.total_amount), 0),
                    transactions: morningSales.length
                },
                nightShift: {
                    sales: nightSales.reduce((sum: number, sale: any) => sum + parseFloat(sale.total_amount), 0),
                    transactions: nightSales.length
                },
                totalSales,
                totalShortage,
                totalFood,
                totalCoffee,
                foodItems,
                grandTotal: totalSales - totalShortage
            };

            // Show the report in a popup
            const reportHtml = `
                <div class="p-4">
                    <h2 class="text-xl font-bold mb-4">End of Day Report - ${selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</h2>
                    
                    <div class="mb-4">
                        <h3 class="font-semibold">Shift Summary</h3>
                        <p>Morning Shift (6:00 AM - 2:00 PM):</p>
                        <p class="ml-4">Sales: ₱${report.morningShift.sales.toFixed(2)}</p>
                        <p class="ml-4">Transactions: ${report.morningShift.transactions}</p>
                        
                        <p>Night Shift (2:00 PM - 10:00 PM):</p>
                        <p class="ml-4">Sales: ₱${report.nightShift.sales.toFixed(2)}</p>
                        <p class="ml-4">Transactions: ${report.nightShift.transactions}</p>
                    </div>

                    <div class="mb-4">
                        <h3 class="font-semibold">Food Items Summary</h3>
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="p-2 text-left border">Item Name</th>
                                    <th class="p-2 text-center border">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${Object.entries(report.foodItems)
                                    .map(([name, quantity]) => `
                                        <tr>
                                            <td class="p-2 border">${name}</td>
                                            <td class="p-2 text-center border">${quantity}</td>
                                        </tr>
                                    `).join('')}
                            </tbody>
                        </table>
                    </div>

                    <div class="mb-4">
                        <h3 class="font-semibold">Totals</h3>
                        <p>Total Food Items: ${report.totalFood}</p>
                        <p>Total Coffee Items: ${report.totalCoffee}</p>
                        <p>Total Sales: ₱${report.totalSales.toFixed(2)}</p>
                        <p>Total Shortage: ₱${report.totalShortage.toFixed(2)}</p>
                        <p class="font-bold">Grand Total: ₱${report.grandTotal.toFixed(2)}</p>
                    </div>
                </div>
            `;

            // Create and show the popup
            const popup = document.createElement('div');
            popup.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            popup.innerHTML = `
                <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                    ${reportHtml}
                    <div class="p-4 border-t">
                        <button onclick="this.closest('.fixed').remove()" class="bg-blue-500 text-white px-4 py-2 rounded">
                            Close
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(popup);
        } catch (error) {
            console.error('Error generating end report:', error);
            alert('Error generating end report. Please try again.');
        }
    }

    // Add function to close end report
    function closeEndReport() {
        showEndReport = false;
    }
  </script>
  
  <div class="flex h-screen bg-gradient-to-b from-green-500 to-green-700">
    <!-- Sidebar Component -->
    <Sidebar />
  
    <!-- Main Content -->
    <div class="flex-grow p-4 bg-gray-100">
   
      <!-- Recent Sales Header -->
      <div class="flex justify-between items-center mb-2">
        <h2 class="text-2xl font-bold">Recent Sales</h2>
        <div>
          <select on:change={handleDateChange} class="bg-gray-800 text-white px-3 py-1 rounded shadow-md">
            {#each dateOptions as date}
                <option value={date} selected={date === selectedDate.toISOString().split('T')[0]}>{new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</option>
            {/each}
          </select>
          <button class="bg-gray-800 text-white px-3 py-1 rounded shadow-md ml-1" on:click={() => { selectedDate = new Date(); fetchSalesData(); }}>
            Recent Returns
          </button>
          <select bind:value={selectedShift} class="bg-gray-800 text-white px-3 py-1 rounded shadow-md ml-1">
            <option value="morning">Morning Shift</option>
            <option value="night">Night Shift</option>
          </select>
          <button class="bg-blue-600 text-white px-3 py-1 rounded shadow-md ml-1" on:click={handleRemitClick} disabled={isRemitDisabled}>
            Remit
          </button>
          <button class="bg-green-600 text-white px-3 py-1 rounded shadow-md ml-1" on:click={generateEndReport}>
            End Report
          </button>
          <button class="bg-purple-600 text-white px-3 py-1 rounded shadow-md ml-1" on:click={printTable}>
            <i class="fas fa-print mr-1"></i> Print
          </button>
        </div>
      </div>
  
      <!-- Adjusted Sales Table -->
      <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <table class="w-full text-left table-fixed border-collapse">
          <thead class="bg-gray-800 text-white">
            <tr>
              <th class="p-2 text-center border-b">Receipt #</th>
              <th class="p-2 text-center border-b">Items</th>
              <th class="p-2 text-center border-b">Total Cost</th>
              <th class="p-2 text-center border-b">Pay Amount</th>
              <th class="p-2 text-center border-b">Change Due</th>
              <th class="p-2 text-center border-b">Order Date</th>
              <th class="p-2 text-center border-b">Order Time</th>
              <th class="p-2 text-center border-b">Order Type</th>
              <th class="p-2 text-center border-b">Cashier</th>
              <th class="p-2 text-center border-b">Return</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            {#if noSalesData}
                <tr>
                    <td colspan="9" class="p-3 text-center">No sales data found.</td>
                </tr>
            {:else if recentSales.length > 0}
                {#each recentSales as sale, i}
                    <tr class="border-t border-gray-300 {i % 2 === 0 ? 'bg-blue-50' : ''}">
                        <td class="p-2 text-center border-b">{sale.receipt}</td>
                        <td class="p-2 text-left border-b">
                            <ul class="list-none pl-5">
                                {#each JSON.parse(sale.items_ordered) as item}
                                    <li class="flex flex-col mb-1">
                                        <span class="font-semibold">{item.order_name} {item.order_name2}</span>
                                        <span class="text-gray-600 text-sm">{item.order_size} {item.order_quantity}</span>
                                        <ul class="list-disc pl-5 mt-1">
                                            {#if item.order_addons && item.order_addons !== 'None'}
                                                <li class="text-sm text-gray-600">{item.order_addons} ₱{item.order_addons_price}.00</li>
                                            {/if}
                                            {#if item.order_addons2 && item.order_addons2 !== 'None'}
                                                <li class="text-sm text-gray-600">{item.order_addons2} ₱{item.order_addons_price2}.00</li>
                                            {/if}
                                            {#if item.order_addons3 && item.order_addons3 !== 'None'}
                                                <li class="text-sm text-gray-600">{item.order_addons3} ₱{item.order_addons_price3}.00</li>
                                            {/if}
                                        </ul>
                                    </li>
                                {/each}
                            </ul>
                        </td>
                        <td class="p-2 text-center border-b">{sale.totalCost}</td>
                        <td class="p-2 text-center border-b">{sale.payAmount}</td>
                        <td class="p-2 text-center border-b">{sale.changeDue}</td>
                        <td class="p-2 text-center border-b">{new Date(sale.orderDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</td>
                        <td class="p-2 text-center border-b">{sale.orderTime}</td>
                        <td class="p-2 text-center border-b">{sale.orderIn}</td>
                        <td class="p-2 text-center border-b">{sale.name}</td>
                        <td class="p-2 text-center border-b">
                            <button class="bg-red-500 text-white px-2 py-1 rounded" on:click={() => handleReturn(sale.receipt)}>Return</button>
                        </td>
                    </tr>
                {/each}
            {:else}
                <tr>
                    <td colspan="9" class="p-3 text-center">No sales data available.</td>
                </tr>
            {/if}
          </tbody>
        </table>
      </div>
  
      <!-- Popup for Total Sales -->
      {#if showPopup}
          <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
              <div class="bg-white p-6 rounded-lg shadow-lg max-w-md w-full">
                  <h3 class="text-xl font-bold text-gray-800">Remit Sales</h3>
                  <p class="mt-2 text-gray-600">Total Sales for ${selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}: <span class="font-semibold">₱{totalSales.toFixed(2)}</span></p>
                  
                  <!-- Input for Shortage with Peso Sign -->
                  <div class="mt-4">
                      <label for="shortage" class="block text-sm font-medium text-gray-700">Shortage:</label>
                      <div class="flex items-center border border-gray-300 rounded-md">
                          <span class="text-gray-700 px-2 mt-1">₱</span>
                          <input type="text" id="shortage" bind:value={shortage} on:input={handleShortageInput} class="mt-1 block w-full border-0 focus:ring-0 focus:outline-none p-2" placeholder="Enter shortage amount" />
                      </div>
                  </div>
                  
                  <button class="mt-4 w-full bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 transition duration-200" on:click={closePopup}>Close</button>
                  <button class="mt-4 w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-200" on:click={handleSubmitClick}>Submit</button>
              </div>
          </div>
      {/if}
  
      <!-- Popup for Second Sales -->
      {#if showSecondPopup}
          <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
              <div class="bg-white p-6 rounded-lg shadow-lg max-w-md w-full">
                  <h3 class="text-xl font-bold text-gray-800">Input 6-Digit Code</h3>
                  <input
                      type="password"
                      bind:value={inputCode}
                      maxlength="6"
                      class="w-full rounded border border-gray-300 p-2 text-center"
                      placeholder="Enter 6-digit code"
                  />
                  <div class="flex justify-between mt-4 space-x-2">
                      <button class="w-full bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 transition duration-200" on:click={closeSecondPopup}>Cancel</button>
                      <button class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-200" on:click={confirmRemit}>Confirm</button>
                      <button class="w-full bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-600 transition duration-200" on:click={handlePendingClick}>Pending</button>
                  </div>
              </div>
          </div>
      {/if}
  
      <!-- Popup for Return Confirmation -->
      {#if showReturnConfirmation}
          <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
              <div class="bg-white p-6 rounded-lg shadow-lg max-w-md w-full">
                  <h3 class="text-xl font-bold text-gray-800">Confirm Return</h3>
                  <p class="mt-2 text-gray-600">Are you sure you want to return this item?</p>
                  <div class="flex justify-between mt-4 space-x-2">
                      <button class="w-full bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 transition duration-200" on:click={closeReturnConfirmation}>Cancel</button>
                      <button class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-200" on:click={confirmReturn}>Confirm</button>
                  </div>
              </div>
          </div>
      {/if}

      <!-- Popup for End of Day Report -->
      {#if showEndReport}
          <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
              <div class="bg-white p-6 rounded-lg shadow-lg max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                  <h3 class="text-xl font-bold text-gray-800">End of Day Report</h3>
                  <p class="text-gray-600">Date: {selectedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                  
                  <div class="mt-4">
                      <h4 class="font-bold">Morning Shift (6:00 AM - 2:00 PM)</h4>
                      <div class="grid grid-cols-2 gap-4 mt-2">
                          <div>
                              <p><strong>Cashier:</strong> {endReportData.morningShift.cashierName}</p>
                              <p><strong>Remit Time:</strong> {endReportData.morningShift.remitTime}</p>
                              <p><strong>Total Sales:</strong> ₱{endReportData.morningShift.totalSales.toFixed(2)}</p>
                          </div>
                          <div>
                              <p><strong>Food Items Sold:</strong> {endReportData.morningShift.foodCount}</p>
                              <p><strong>Coffee Items Sold:</strong> {endReportData.morningShift.coffeeCount}</p>
                          </div>
                      </div>
                  </div>

                  <div class="mt-4">
                      <h4 class="font-bold">Night Shift (2:00 PM - 10:00 PM)</h4>
                      <div class="grid grid-cols-2 gap-4 mt-2">
                          <div>
                              <p><strong>Cashier:</strong> {endReportData.nightShift.cashierName}</p>
                              <p><strong>Remit Time:</strong> {endReportData.nightShift.remitTime}</p>
                              <p><strong>Total Sales:</strong> ₱{endReportData.nightShift.totalSales.toFixed(2)}</p>
                          </div>
                          <div>
                              <p><strong>Food Items Sold:</strong> {endReportData.nightShift.foodCount}</p>
                              <p><strong>Coffee Items Sold:</strong> {endReportData.nightShift.coffeeCount}</p>
                          </div>
                      </div>
                  </div>

                  <div class="mt-4">
                      <h4 class="font-bold">Daily Summary</h4>
                      <div class="grid grid-cols-2 gap-4 mt-2">
                          <div>
                              <p><strong>Total Food Items Sold:</strong> {endReportData.totalFood}</p>
                              <p><strong>Total Coffee Items Sold:</strong> {endReportData.totalCoffee}</p>
                          </div>
                          <div>
                              <p><strong>Grand Total Sales:</strong> ₱{endReportData.grandTotal.toFixed(2)}</p>
                          </div>
                      </div>
                  </div>

                  <div class="mt-4 flex justify-end space-x-2">
                      <button class="bg-red-500 text-white px-4 py-2 rounded" on:click={closeEndReport}>Close</button>
                      <button class="bg-blue-500 text-white px-4 py-2 rounded" on:click={printTable}>Print Report</button>
                  </div>
              </div>
          </div>
      {/if}
    </div>
  </div>
  
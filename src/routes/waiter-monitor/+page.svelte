<script lang="ts">
	import Card from '../components/card.svelte';
	import Sidebar from '../sidebar/+page.svelte';
	import { orderedItemsStore } from '../../stores/orderedItemsStore';
	import { onMount, onDestroy } from 'svelte';
	import { handleButtonClick } from '../../utils/buttonHandler'; // Import the reusable function
	import { currentInputStore } from '../../stores/currentInputStore'; // Import the store

	const API_URL = 'http://localhost/kaperustiko-possystem/backend/modules';

	let cardData: MenuItem[] = [];
	let amountPaid = '₱0.00'; // Default amount paid
	let isDineIn = false;
	let isTakeOut = false;
	let cashierName = '';
	let staffToken: string | null = null; // Declare a variable to hold the staff_token
	let currentTime: string;
	let currentDay: string;
	let isSleepActive = false; // Ensure this line exists
	let receiptNumber = ''; // Add receipt number variable

	let debounceTimeout: ReturnType<typeof setTimeout>;
	let refreshInterval: ReturnType<typeof setInterval>;

	let isFetching = false; // Flag to prevent multiple fetch calls

	let tableStatus: { [key: string]: boolean } = {};

	let reservedTables: string[] = [];

	let selectedTableNumber: string = ''; // Declare the variable

	let isWaiterCodePopupVisible = false; // Add this for waiter code popup
	let waiterCode = ''; // Variable to store waiter code
	let waiterName = ''; // Variable to store waiter name
	let change = 0; // Set default change to 0

	let isConfirmVoidVisible = false; // State for confirmation modal

	// Add this at the top with other let declarations
	let deliveredItems = new Map();

	// Function to load delivery status from localStorage
	function loadDeliveryStatus() {
		try {
			const storedStatus = localStorage.getItem('deliveryStatus');
			if (storedStatus) {
				// Parse the stored data and convert it back to a Map
				const parsedData = JSON.parse(storedStatus);
				deliveredItems = new Map(parsedData);
				console.log('Loaded delivery status:', deliveredItems);
			}
		} catch (error) {
			console.error('Error loading delivery status:', error);
		}
	}

	// Function to save delivery status to localStorage
	function saveDeliveryStatus() {
		try {
			// Convert Map to array of entries and store
			const mapEntries = Array.from(deliveredItems.entries());
			localStorage.setItem('deliveryStatus', JSON.stringify(mapEntries));
			console.log('Saved delivery status:', mapEntries);
		} catch (error) {
			console.error('Error saving delivery status:', error);
		}
	}

	// Function to toggle delivery status
	function toggleDeliveryStatus(item: TableOrderItem) {
		try {
			// Create a unique key for this item
			const itemKey = `${item.receipt_number}-${item.order_name}-${item.order_size}`;
			
			// Toggle the delivery status
			const newStatus = !item.delivered;
			
			// Update the item's delivered status
			item.delivered = newStatus;
			
			// Store in our Map
			deliveredItems.set(itemKey, newStatus);
			
			// Save to localStorage immediately
			saveDeliveryStatus();
			
			// Update the selectedTableItems array to trigger reactivity
			selectedTableItems = [...selectedTableItems];
			
			console.log('Toggled delivery status:', { itemKey, newStatus });
		} catch (error) {
			console.error('Error toggling delivery status:', error);
		}
	}

	async function fetchCashierName() {
		// Retrieve staff_token from local storage only if not already fetched
		if (!staffToken) {
			staffToken = localStorage.getItem('staff_token'); // Get the staff_token
		}
		console.log('Fetched staff_token:', staffToken); // Log the fetched staff_token
		console.log(
			`Fetching user data from: http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getUser&staff_token=${staffToken}`
		); // Log the URL with the staff_token
		const response = await fetch(
			`http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getUser&staff_token=${staffToken}`
		);
		if (response.ok) {
			const userData = await response.json();
			console.log('Fetched user data:', userData); // Log the fetched user data
			cashierName = userData || 'Unknown'; // Default to 'Unknown' if firstName is not available
		}
	}

	async function fetchMenu() {
		const response = await fetch(
			'http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getMenu'
		);
		if (response.ok) {
			cardData = await response.json();
		} else {
			console.error('Failed to fetch menu items');
		}
	}

	let orderedItems: OrderedItem[] = [];

	// Function to fetch orders - modify to merge duplicates
	async function fetchOrders() {
		if (isFetching) return; // Prevent multiple fetch calls
		isFetching = true; // Set fetching flag

		const response = await fetch(
			'http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getOrders'
		);
		
		if (response.ok) {
			const textResponse = await response.text(); // Get response as text
			try {
				const fetchedOrders = JSON.parse(textResponse); // Attempt to parse JSON
				
				// Create a map to merge duplicate items
				const mergedItems = new Map();
				
				// First, process all fetched orders
				fetchedOrders.forEach((item: OrderedItem) => {
					// Create a unique key based on item properties
					const itemKey = `${item.code}-${item.order_size}-${item.order_addons}-${item.order_addons2}-${item.order_addons3}-${item.special_instructions || ''}`;
					
					if (mergedItems.has(itemKey)) {
						// If we already have this item, increment quantity and update price
						const existingItem = mergedItems.get(itemKey);
						existingItem.order_quantity += item.order_quantity;
						existingItem.order_price += item.order_price;
					} else {
						// Otherwise, add the item to our map
						mergedItems.set(itemKey, {...item});
					}
				});
				
				// Convert the map values to an array
				const mergedOrdersArray = Array.from(mergedItems.values());
				
				// Now assign the merged array to our state
				orderedItemsStore.set(mergedOrdersArray);
				orderedItems = mergedOrdersArray;
				
				// Ensure local storage is updated
				localStorage.setItem('orderedItems', JSON.stringify(mergedOrdersArray));
			} catch (error) {
				console.error('Failed to parse JSON:', error, 'Response:', textResponse);
			}
		} else {
			console.error('Failed to fetch orders:', response.statusText);
		}

		isFetching = false; // Reset fetching flag
	}

	function updateTime() {
		const now = new Date();
		currentTime = now.toLocaleString('en-US', {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit'
		});
		currentDay = now.toLocaleString('en-US', { weekday: 'long' });
	}

	onMount(() => {
		// Load delivery status first
		loadDeliveryStatus();
		
		fetchMenu();
		fetchOrders();
		fetchQueuedOrders();
		fetchCashierName(); // Automatically fetch cashier name on mount
		updateTime(); // Initialize time immediately
		// Set up timer to update time every minute
		const timeInterval = setInterval(updateTime, 60000);
		// Retrieve ordered items from localStorage
		const storedItems = localStorage.getItem('orderedItems');
		if (storedItems) {
			orderedItems = JSON.parse(storedItems); // Parse and set orderedItems
		}
		// Start refreshing orders every 500 ms
		refreshInterval = setInterval(fetchOrders, 500); 
		refreshInterval = setInterval(fetchQueuedOrders, 500); // Set interval for refreshing orders
		fetchTableStatus();
		fetchReserveTables();
		
		return () => {
			clearInterval(timeInterval); // Clean up time interval on unmount
		};
	});

	onDestroy(() => {
		clearInterval(refreshInterval); // Clear the interval on component destroy
	});

	let orderNumber = '';
	let totalCost = '₱00.00';
	let selectedCategory = 'All';
	let payment = '';
	let quantity = 1; // Change this line to ensure quantity is a number
	let isPopupVisible = false;
	let isVariationVisible = false;
	let selectedItem: MenuItem | null = null;
	let selectedItemDetails: {
		title: string;
		price: string;
		size: string;
		quantity: number;
		addons: string[];
	} | null = null;

	type OrderedItem = {
		order_name: string;
		order_name2: string;
		order_price: number;
		order_size: string;
		order_quantity: number;
		order_addons: string;
		order_addons_price: number;
		order_addons2: string;
		order_addons_price2: number;
		order_addons3: string;
		order_addons_price3: number;
		basePrice: number;
		code: string;
		special_instructions?: string; // Add special instructions field
		que_order_no?: string; // Add que_order_no field
	};

	let selectedAddons: string[] = [];
	let displayedPrice = selectedItem
		? formatPrice(
				quantity *
					(parseFloat((selectedItem as MenuItem).price1.replace('₱', '').replace(',', '')) +
						parseFloat(calculateAddonsPrice(selectedAddons).replace('₱', '').replace(',', '')))
			)
		: '';
	let selectedSize = 'Regular';

	// Sort cardData by label1 and label2
	cardData.sort((a, b) => {
		if (a.label < b.label) return -1;
		if (a.label > b.label) return 1;
		if (a.label2 < b.label2) return -1;
		if (a.label2 > b.label2) return 1;
		return 0;
	});


	function increaseQuantity() {
		quantity += 1;
	}

	function decreaseQuantity() {
		if (quantity > 0) {
			quantity -= 1;
		}
	}

	fetch('http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getTotalOrders')
		.then((response) => response.json())
		.then((data) => {
			orderNumber = `#${data.total_order.toString().padStart(2, '0')}`; // Set order number based on fetched data
			console.log('Order Number:', orderNumber); // Log the order number
		})
		.catch((error) => {
			console.error('Failed to fetch total orders:', error);
		});

    function closePopup() {
		isPopupVisible = false;
		isVariationVisible = false;
	}


	function handlePlaceOrder() {
		// Check if total cost is null or zero
		if (orderedItems.length === 0 || 
			orderedItems.reduce((total, item) => total + (item.order_price || 0), 0) === 0) {
			showAlert('Cannot place order. Total cost is null or zero.', 'error');
			return;
		}
		
		// Check if no table is selected
		if (!selectedTableNumber) {
			showAlert('Please select a table number before placing an order.', 'error');
			return;
		}

		// Validate table number format
		const isValidTable = (
			// Indoor tables (1-20)
			(/^[1-9]|1\d|20$/.test(selectedTableNumber)) ||
			// Outdoor tables (O1-O15)
			(/^O(?:[1-9]|1[0-5])$/.test(selectedTableNumber)) ||
			// Garden tables (G1-G6)
			(/^G[1-6]$/.test(selectedTableNumber))
		);

		if (!isValidTable) {
			showAlert('Invalid table number format', 'error');
			return;
		}
		
		// Show waiter code popup instead of directly saving order
		isWaiterCodePopupVisible = true;
	}

	async function verifyWaiterCode() {
		console.log("Verify Waiter Code function triggered");
		if (waiterCode.length < 6) {
			console.error('Invalid waiter code: less than 6 digits');
			showAlert('Please enter a valid waiter code (min 6 digits)', 'error');
			return;
		}
		
		// New validation for alphanumeric characters
		const isValidCode = /^[a-zA-Z0-9]+$/.test(waiterCode);
		if (!isValidCode) {
			console.error('Invalid waiter code: must be alphanumeric');
			showAlert('Please enter a valid waiter code (alphanumeric only)', 'error');
			return;
		}

		try {
			// Generate receipt number
			const timestamp = new Date().getTime();
			const randomNum = Math.floor(Math.random() * 1000);
			const receiptNumber = `${timestamp}-${randomNum}`;

			// Calculate total amount properly
			const totalAmount = orderedItems.reduce((total, item) => {
				const itemPrice = typeof item.order_price === 'string' ? 
					parseFloat(item.order_price) : item.order_price;
				return total + (itemPrice || 0);
			}, 0);

			// Keep original table number for database
			const tableNumber = selectedTableNumber; // This will be O1, O2, etc.

			// Prepare receipt data
			const receiptData = {
				saveQueOrder: true,
				receiptNumber,
				date: new Date().toLocaleDateString(),
				time: new Date().toLocaleTimeString(),
				cashierName,
				waiterName: waiterCode.substring(0, 2),
				waiterCode,
				itemsOrdered: orderedItems.map(item => ({
					...item,
					order_quantity: String(item.order_quantity),
					basePrice: String(item.basePrice),
					order_price: String(item.order_price),
					order_addons_price: String(item.order_addons_price || 0),
					order_addons_price2: String(item.order_addons_price2 || 0),
					order_addons_price3: String(item.order_addons_price3 || 0),
					table_number: tableNumber // Use original table number
				})),
				totalAmount: String(totalAmount),
				amountPaid: "0",
				change: "0",
				order_take: isTakeOut ? 'Take Out' : 'Dine In',
				table_number: tableNumber // Use original table number
			};

			// Log the receipt data before sending
			console.log('Sending order data with waiter:', waiterName, waiterCode);
			console.log('Receipt Data:', JSON.stringify(receiptData, null, 2));

			// Update table status before sending order
			try {
				const updateTableResponse = await fetch('http://localhost/kaperustiko-possystem/backend/modules/update.php?action=updateTableStatus', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json'
					},
					body: JSON.stringify({
						table_number: tableNumber, // Use original table number
						is_occupied: true
					})
				});

				if (!updateTableResponse.ok) {
					console.error('Failed to update table status');
				}
			} catch (error) {
				console.error('Error updating table status:', error);
			}

			// Send data to the server
			const response = await fetch('http://localhost/kaperustiko-possystem/backend/modules/save_que_order.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Accept': 'application/json'
				},
				body: JSON.stringify(receiptData)
			});

			if (!response.ok) {
				const errorText = await response.text();
				throw new Error('Server response not OK: ' + errorText);
			}

			const data = await response.json();
			if (data.error) {
				console.error('Error saving order:', data.error);
				showAlert(data.error, 'error');
				return;
			}
			
			console.log('Order saved:', data);
			showAlert(`Order queued successfully by Waiter ${waiterCode.substring(0, 2)}`, 'success');
			
			// Update the order count for the waiter using no-cors mode
			try {
				const updateResponse = await fetch(`http://localhost/kaperustiko-possystem/backend/modules/update_waiter_orders.php`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Accept': 'application/json'
					},
					body: JSON.stringify({
						waiter_code: waiterCode
					})
				});

				if (!updateResponse.ok) {
					console.error('Failed to update waiter order count');
				}
			} catch (error) {
				console.error('Error updating waiter order count:', error);
			}
			
			// After successful order, update table status
			await fetchTableStatus();
			
			// Call updateQuantity for each ordered item
			for (const item of orderedItems) {
				try {
					const code = item.code;
					console.log('Updating inventory for:', code);
					const qtyResponse = await fetch(
						`http://localhost/kaperustiko-possystem/backend/modules/qty_data.php?code=${code}&order_quantity=${String(item.order_quantity)}`
					);
					const qtyData = await qtyResponse.json();
					if (qtyData.status === 'success') {
						console.log('Inventory updated:', qtyData.message);
					} else {
						console.error('Inventory update error:', qtyData.message);
					}
				} catch (error) {
					console.error('Error updating quantity:', error);
				}
			}
			
			// Now delete all orders from the orders table
			try {
				const deleteResponse = await fetch(
					'http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=deleteAllOrders',
					{ method: 'DELETE' }
				);
				const deleteData = await deleteResponse.json();
				console.log('Orders cleared:', deleteData);
			} catch (error) {
				console.error('Error clearing orders:', error);
			}
			
			// Clear ordered items
			orderedItems = [];
			// Reset waiter code and close popup
			waiterCode = '';
			waiterName = '';
			isWaiterCodePopupVisible = false; // Close the waiter code popup
			// Reset table number selection
			selectedTableNumber = '';
			// Reset payment
			payment = '';
		} catch (error) {
			console.error('Error:', error);
			showAlert('Failed to queue order. Please try again.', 'error');
		}
	}

	function closeWaiterCodePopup() {
		isWaiterCodePopupVisible = false;
		waiterCode = '';
	}

	function handleWaiterCodeInput(num: string) {
		if (waiterCode.length < 6) {
			waiterCode += num;
		}
	}
	
	function handleWaiterCodeBackspace() {
		waiterCode = waiterCode.slice(0, -1);
	}
	
	function handleWaiterCodeClear() {
		waiterCode = '';
	}

	async function saveQueOrder() {
		// Fetch the unique que order number first
		fetch('http://localhost/kaperustiko-possystem/backend/modules/getTotalQueOrders')
			.then((response) => {
				if (!response.ok) {
					console.error('Failed to fetch queue order number:', response.statusText);
					return response.text().then(text => {
						throw new Error('Failed to fetch queue order number: ' + text);
					});
				}
				return response.json();
			})
			.then((queueData) => {
				// Create a unique receipt number for que orders
				console.log('Received queue order data:', queueData);
				
				// Check if total_order exists in the response
				if (!queueData || typeof queueData.total_order === 'undefined') {
					const timestamp = new Date().getTime().toString().slice(-6);
					return processOrder(parseInt(timestamp));
				}
				
				const queueOrderNumber = queueData.total_order.toString().padStart(2, '0');
				return processOrder(parseInt(queueOrderNumber));
			})
			.catch((error) => {
				console.error('Error fetching total que orders:', error);
				const timestamp = new Date().getTime().toString().slice(-6);
				processOrder(parseInt(timestamp));
			});
		
		// Function to process the order with the given receipt number
		function processOrder(receiptNum: number) {
			// Prepare the receipt data
			const receiptData = {
				receiptNumber: receiptNum,
				date: new Date().toLocaleDateString(),
				time: new Date().toLocaleTimeString(),
				cashierName: cashierName,
				waiterName: waiterName, // Add waiter name
				waiterCode: waiterCode, // Add waiter code
				table_number: selectedTableNumber, // Use the selected table number
				itemsOrdered: orderedItems.map((item) => ({
					order_name: item.order_name,
					order_name2: item.order_name2,
					order_quantity: 'x' + item.order_quantity,
					order_size: item.order_size,
					order_addons: item.order_addons !== 'None' ? item.order_addons : undefined,
					order_addons_price: item.order_addons_price || 0,
					order_addons2: item.order_addons2 !== 'None' ? item.order_addons2 : undefined,
					order_addons_price2: item.order_addons_price2 || 0,
					order_addons3: item.order_addons3 !== 'None' ? item.order_addons3 : undefined,
					order_addons_price3: item.order_addons_price3 || 0,
					basePrice: item.basePrice, // Include the base price of the item
					delivered: "0" // Add delivered property initialized to "0" (not delivered)
				})),
				totalAmount: Math.round(
					orderedItems.reduce(
						(total, item) =>
							total + parseFloat(item.order_price.toString().replace('₱', '').replace(',', '')),
						0
					)
				),
				amountPaid: 0, // Set to 0 if payment is not provided
				change: 0,
				order_take: isDineIn ? 'Dine In' : 'Take Out', // Ensure this key matches
				saveQueOrder: true // Add this line to indicate saving to que_orders
			};

			// Log the receipt data before sending
			console.log('Sending order data with waiter:', waiterName, waiterCode);
			console.log('Receipt Data:', JSON.stringify(receiptData, null, 2)); // Log the data being sent

			// Send data to the server
			fetch('http://localhost/kaperustiko-possystem/backend/modules/save_que_order.php', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify(receiptData)
			})
			.then((response) => {
				if (!response.ok) {
					return response.text().then(text => {
						throw new Error('Server response not OK: ' + text);
					});
				}
				return response.json();
			})
			.then((data) => {
				if (data.error) {
					console.error('Error saving order:', data.error);
					showAlert(data.error, 'error');
					return;
				}
				
				console.log('Order saved:', data);
				showAlert(`Order queued successfully by Waiter ${waiterName}`, 'success');
				
				// Update the order count for the waiter
				updateWaiterOrderCount(waiterCode);
				
				// After successful order, update table status
				fetchTableStatus();
				
				// Call updateQuantity for each ordered item
				orderedItems.forEach((item) => {
					const code = item.code;
					console.log('Updating inventory for:', code);
					fetch(`http://localhost/kaperustiko-possystem/backend/modules/qty_data.php?code=${code}&order_quantity=${item.order_quantity}`, {
						method: 'GET'
					})
						.then((response) => response.json())
						.then((data) => {
							if (data.status === 'success') {
								console.log('Inventory updated:', data.message);
							} else {
								console.error('Inventory update error:', data.message);
							}
						})
						.catch((error) => {
							console.error('Error updating quantity:', error);
						});
				});
				
				// Now delete all orders from the orders table
				fetch('http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=deleteAllOrders', {
					method: 'DELETE'
				})
					.then(response => response.json())
					.then(data => {
						console.log('Orders cleared:', data);
					})
					.catch(error => {
						console.error('Error clearing orders:', error);
					});
				
				// Clear ordered items
				orderedItems = [];
				// Reset waiter code
				waiterCode = '';
				waiterName = '';
				// Reset table number selection
				selectedTableNumber = '';
				// Reset payment
				payment = '';
			})
			.catch((error) => {
				console.error('Error:', error);
				showAlert('Failed to queue order. Please try again.', 'error');
			});
		}

		// Function to update the waiter order count
		async function updateWaiterOrderCount(waiterCode: string) {
			try {
				const response = await fetch(`${API_URL}/update_waiter_orders.php?waiter_code=${waiterCode}`);
				const data = await response.json();
				if (data.error) {
					console.error('Error updating waiter order count:', data.error);
					return false;
				}
				return true;
			} catch (error) {
				console.error('Error updating waiter order count:', error);
				return false;
			}
		}
	}

	function showAlert(message: string, type: string) {
		const alertDiv = document.createElement('div');
		alertDiv.className = `fixed top-0 left-1/2 transform -translate-x-1/2 mt-4 p-4 rounded shadow-lg transition-all duration-300 ease-in-out z-50 
			${type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white font-semibold text-lg`;
		alertDiv.innerText = message;
		document.body.appendChild(alertDiv);
		
		// Fade out effect
		setTimeout(() => {
			alertDiv.classList.add('opacity-0');
			setTimeout(() => {
				alertDiv.remove();
			}, 300); // Wait for fade out to complete before removing
		}, 3000); // Display for 3 seconds
	}

	type MenuItem = {
		menu_no: string;
		code: string;
		title1: string;
		title2: string;
		price1: string;
		price2: string;
		price3: string;
		image: string;
		label: string;
		label2: string;
		qty: string;
	};

	function formatPrice(price: number): string {
		return price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function handleAdd(item: MenuItem) {
		selectedItem = item;
		console.log('Selected Item Details:', selectedItem);
		isVariationVisible = true;
	}

	function handleOrder(item: MenuItem) {
		selectedItem = item;
		const addonsPrice = calculateAddonsPrice(selectedAddons); // Calculate addons price
		const basePrice =
			selectedSize === 'Regular'
				? parseFloat(item.price1.replace('₱', '').replace(',', ''))
				: selectedSize === 'Large'
					? parseFloat(item.price2.replace('₱', '').replace(',', ''))
					: parseFloat(item.price3.replace('₱', '').replace(',', '')); // Adjust for Family size

		const totalAddonsPrice = selectedAddons.reduce((total, addon) => {
			const addonPrice =
				parseFloat(calculateAddonsPrice([addon]).replace('₱', '').replace(',', '')) || 0;
			return total + addonPrice;
		}, 0);

		// Check if this item already exists in the cart with the same specifications
		const existingItemIndex = orderedItems.findIndex(existingItem => 
			existingItem.code === item.code && 
			existingItem.order_size === selectedSize && 
			existingItem.order_addons === (selectedAddons.length > 0 ? selectedAddons[0] : 'None') &&
			existingItem.order_addons2 === (selectedAddons.length > 1 ? selectedAddons[1] : 'None') &&
			existingItem.order_addons3 === (selectedAddons.length > 2 ? selectedAddons[2] : 'None') &&
			existingItem.special_instructions === specialInstructions
		);

		// Create the basic order data
		const orderData = {
			order_name: item.title1 || '',
			order_name2: item.title2 || '', // Ensure order_name2 is always set
			order_quantity: quantity,
			order_size: selectedSize,
			order_price: (basePrice + totalAddonsPrice) * quantity,
			order_image: item.image,
			basePrice: basePrice, // Ensure basePrice is set
			order_addons: selectedAddons.length > 0 ? selectedAddons[0] : 'None',
			order_addons_price: selectedAddons.length > 0
				? parseFloat(calculateAddonsPrice([selectedAddons[0]]).replace('₱', '').replace(',', ''))
				: 0,
			order_addons2: selectedAddons.length > 1 ? selectedAddons[1] : 'None',
			order_addons_price2: selectedAddons.length > 1
				? parseFloat(calculateAddonsPrice([selectedAddons[1]]).replace('₱', '').replace(',', ''))
				: 0,
			order_addons3: selectedAddons.length > 2 ? selectedAddons[2] : 'None',
			order_addons_price3: selectedAddons.length > 2
				? parseFloat(calculateAddonsPrice([selectedAddons[2]]).replace('₱', '').replace(',', ''))
				: 0,
			code: item.code,
			table_number: selectedTableNumber,
			special_instructions: specialInstructions || ''
		};

		if (existingItemIndex !== -1) {
			// Item exists, update quantity
			const existingItem = orderedItems[existingItemIndex];
			const newQuantity = existingItem.order_quantity + quantity; // Update quantity
			const unitPrice = basePrice + totalAddonsPrice;
			
			// Create updated order data
			const updatedOrderData = {
				...existingItem,
				order_quantity: newQuantity, // Update quantity
				order_price: unitPrice * newQuantity
			};
			
			// Update state with the new item quantity
			const updatedItems = [...orderedItems];
			
			updatedItems[existingItemIndex] = updatedOrderData; // Update the existing item

			// Update locally first
			orderedItems = updatedItems;
			localStorage.setItem('orderedItems', JSON.stringify(updatedItems));
			
			// Then delete the old item and add updated one
			fetch(
				`http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=deleteAllOrders`, 
				{ method: 'DELETE' }
			)
			.then(() => {
				// Add all items back to the database
				Promise.all(
					updatedItems.map(item => {
						return fetch('http://localhost/kaperustiko-possystem/backend/modules/insert.php?action=save_order', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify(item)
						});
					})
				)
				.then(() => {
					console.log('Order updated with new quantity');
					closePopup();
				})
				.catch(error => {
					console.error('Error updating orders:', error);
				});
			})
			.catch(error => {
				console.error('Error clearing orders:', error);
				// Fallback to just adding the updated item
				saveOrderToDatabase(updatedOrderData, updatedItems);
			});
		} else {
			// New item - just add it
			const updatedItems = [...orderedItems, orderData];
			saveOrderToDatabase(orderData, updatedItems);
		}

		// Reset quantity after adding the item
		quantity = 1; // Reset to default value
	}
	
	// Helper function to save order to database
	function saveOrderToDatabase(orderData: any, updatedItems: any[]) {
		fetch('http://localhost/kaperustiko-possystem/backend/modules/insert.php?action=save_order', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(orderData)
		})
		.then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(text);
				});
			}
			return response.json();
		})
		.then((data) => {
			console.log(data.message);
			
			// Update local state with the updated items
			orderedItems = updatedItems;
			
			// Update in the store too
			orderedItemsStore.set(updatedItems);
			
			// Save the order to localStorage
			localStorage.setItem('orderedItems', JSON.stringify(updatedItems));
		})
		.catch((error) => {
			console.error('Error saving order:', error);
		})
		.finally(() => {
			// Reset UI state
			selectedItem = null;
			selectedSize = 'Regular';
			selectedAddons = [];
			specialInstructions = ''; // Reset special instructions
			quantity = 1; // Reset quantity to 1
			closePopup();
		});
	}

	// Function to update quantity of an existing item
	function updateItemQuantity(index: number, newQuantity: number) {
		// Ensure newQuantity is a proper number
		newQuantity = parseInt(String(newQuantity), 10);
		
		if (newQuantity < 1) {
			// If quantity is less than 1, void the item
			voidOrder(index);
			return;
		}
		
		const item = orderedItems[index];
		
		// Create a unique identifier with all relevant details
		const orderIdentifier = {
			order_name: item.order_name,
			order_size: item.order_size,
			order_addons: item.order_addons,
			order_addons2: item.order_addons2,
			order_addons3: item.order_addons3,
			code: item.code,
			special_instructions: item.special_instructions,
			index: index
		};
		
		// First, remove the existing item from the database
		fetch(
			`http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=voidOrder&order_name=${encodeURIComponent(item.order_name)}&order_size=${encodeURIComponent(item.order_size)}&index=${index}`,
			{
				method: 'DELETE',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify(orderIdentifier)
			}
		).then((response) => response.json())
		.then(() => {
			// Calculate the unit price (price per item without quantity)
			const unitPrice = Number(item.basePrice);
			const addonsPrice = Number(item.order_addons_price || 0) + Number(item.order_addons_price2 || 0) + Number(item.order_addons_price3 || 0);
			
			// Create updated order data with preserved special instructions
			const updatedOrderData = {
				...item,
				order_quantity: newQuantity,
				order_price: (unitPrice * newQuantity) + (addonsPrice * newQuantity),
				// Preserve special instructions if they exist
				special_instructions: item.special_instructions || ''
			};
			
			// Update local state first to provide immediate feedback
			const updatedItems = [...orderedItems];
			updatedItems[index] = updatedOrderData;
			orderedItems = updatedItems;
			
			// Save the updated order to the database
			fetch('http://localhost/kaperustiko-possystem/backend/modules/insert.php?action=save_order', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify(updatedOrderData)
			})
			.then((response) => {
				if (!response.ok) {
					return response.text().then((text) => {
						throw new Error(text);
					});
				}
				return response.json();
			})
			.then((data) => {
				console.log('Order updated:', data.message);
				localStorage.setItem('orderedItems', JSON.stringify(orderedItems));
				fetchOrders();
			})
			.catch((error) => {
				console.error('Error updating order:', error);
				fetchOrders(); // Refresh in case of error
			});
		})
		.catch((error) => {
			console.error('Error updating order quantity:', error);
			fetchOrders(); // Refresh in case of error
		});
	}

	function calculateAddonsPrice(addons: string[]): string {
		// Define prices for each addon
		const addonPrices: { [key: string]: number } = {
			Sugar: 5,
			Bobba: 10,
			Milk: 7,
			'Extra Cheese': 15,
			Bacon: 20,
			Olives: 10,
			Rice: 10
		};

		// Return a string of addon prices
		return addons.map((addon) => `${addonPrices[addon] || 0}`).join(', ');
	}

	function selectSize(size: string) {
		selectedSize = size;
		if (selectedItem) {
			if (size === 'Regular' || size === '0.25L') {
				displayedPrice = formatPrice(
					quantity * parseFloat(selectedItem.price1.replace('₱', '').replace(',', ''))
				);
			} else if (size === 'Large' || size === '0.33L') {
				displayedPrice = formatPrice(
					quantity * parseFloat(selectedItem.price2.replace('₱', '').replace(',', ''))
				);
			} else if (size === 'Family' || size === '1.5L') {
				displayedPrice = formatPrice(
					quantity * parseFloat(selectedItem.price3.replace('₱', '').replace(',', ''))
				);
			} else if (size === '16oz') {
				displayedPrice = formatPrice(
					quantity * parseFloat(selectedItem.price1.replace('₱', '').replace(',', ''))
				);
			} else if (size === '22oz') {
				displayedPrice = formatPrice(
					quantity * parseFloat(selectedItem.price2.replace('₱', '').replace(',', ''))
				);
			}
		}
	}

	$: {
		if (selectedItem) {
			let price = 0;
			if (selectedSize === 'Regular' || selectedSize === '0.25L') {
				price = parseFloat(selectedItem.price1.replace('₱', '').replace(',', ''));
			} else if (selectedSize === 'Large' || selectedSize === '0.33L') {
				price = parseFloat(selectedItem.price2.replace('₱', '').replace(',', ''));
			} else if (selectedSize === 'Family' || selectedSize === '1.5L') {
				price = parseFloat(selectedItem.price3.replace('₱', '').replace(',', ''));
			} else if (selectedSize === '16oz') {
				price = parseFloat(selectedItem.price1.replace('₱', '').replace(',', ''));
			} else if (selectedSize === '22oz') {
				price = parseFloat(selectedItem.price2.replace('₱', '').replace(',', ''));
			}
			displayedPrice = formatPrice(quantity * price);
		}
	}

	function voidOrder(index: number) {
		const orderToVoid = orderedItems[index]; // Get the order to void
		orderedItems.splice(index, 1); // Remove from local array
		localStorage.setItem('orderedItems', JSON.stringify(orderedItems)); // Update localStorage after voiding

		// Send request to backend to delete the order - include both code and size to identify the specific item
		fetch(
			`http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=voidOrder&order_name=${encodeURIComponent(orderToVoid.order_name)}&order_size=${encodeURIComponent(orderToVoid.order_size)}&index=${index}`,
			{
				method: 'DELETE'
			}
		)
			.then((response) => response.json())
			.then((data) => {
				console.log('Order voided:', data);
			})
			.catch((error) => {
				console.error('Error voiding order:', error);
			});
	}

	function handleBackspace() {
		payment = payment.slice(0, -1);
	}

	function handleClear() {
		payment = '';
	}

	function handleNumberInput(num: string) {
		payment += num;
	}

	// Define a type for the queued orders
	type QueuedOrder = {
		table_number: string;
		receipt_number: string;
		total_amount: number;
		amount_paid: number;
		order_status: string;
		waiter_name?: string; // Add waiter name field
		waiter_code?: string; // Add waiter code field
	};

	// Define a type for table order items
	type TableOrderItem = {
		receipt_number: string;
		order_name: string;
		order_size: string;
		order_quantity: number;
		order_status: string;
		waiter_name?: string;
		order_addons: string;
		order_addons_price: number;
		order_addons2: string;
		order_addons_price2: number;
		order_addons3: string;
		order_addons_price3: number;
		item_total_price: number;
		delivered: boolean;
	};

	// Use the defined type for queuedOrders
	let queuedOrders: QueuedOrder[] = [];
	
	// Add state for table details modal
	let isTableDetailsModalVisible = false;
	let selectedTableDetails: { tableNumber: string; orders: QueuedOrder[] } = { tableNumber: '', orders: [] };
	let selectedTableItems: TableOrderItem[] = [];

	async function fetchQueuedOrders() {
		const response = await fetch('http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getQueOrders');
		if (response.ok) {
			queuedOrders = await response.json();
		} else {
			console.error('Failed to fetch queued orders', response.statusText); // Improved error logging
		}
	}

	// Function to open table details modal
	function openTableDetails(tableNumber: string) {
		selectedTableDetails.tableNumber = tableNumber;
		selectedTableDetails.orders = queuedOrders.filter(order => order.table_number === tableNumber);
		
		// Fetch detailed items for this table
		fetchTableOrderDetails(tableNumber);
	}
	
	// Function to fetch detailed order items for a table
	async function fetchTableOrderDetails(tableNumber: string) {
		try {
			// Show loading indicator
			showAlert('Loading order details...', 'info');
			
			console.log(`Fetching order details for table ${tableNumber}`);
			
			// Get queued orders for the table to check expectations
			const expectedOrders = queuedOrders.filter(order => order.table_number === tableNumber);
			console.log(`Table ${tableNumber} should have ${expectedOrders.length} orders:`, 
				expectedOrders.map(o => o.receipt_number));
			
			// Fetch order details
			const response = await fetch(`http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getTableOrderDetails&table_number=${tableNumber}`);
			if (!response.ok) {
				console.error(`HTTP error ${response.status}: ${response.statusText}`);
				showAlert(`Failed to fetch order details (${response.status})`, 'error');
				return;
			}
			
			let data;
			try {
				const text = await response.text();
				console.log("Raw response:", text);
				data = JSON.parse(text);
			} catch (error) {
				console.error("Error parsing response:", error);
				showAlert("Error parsing server response", 'error');
				return;
			}
			
			console.log('Table order details:', data);
			
			if (data && data.error) {
				console.error('Error in response:', data.error);
				showAlert('Error: ' + data.error, 'error');
				return;
			}
			
			// Process the fetched data
			if (Array.isArray(data) && data.length > 0) {
				// Process each item to ensure the delivered property is correctly formatted
				selectedTableItems = data.map((item: any) => {
					// Create a unique key for this item
					const itemKey = `${item.receipt_number}-${item.order_name}-${item.order_size}`;
					
					// Check if we have a stored delivery status
					const storedStatus = deliveredItems.get(itemKey);
					
					// Convert delivered property to a consistent format
					let isDelivered = false;
					if (storedStatus !== undefined) {
						isDelivered = storedStatus;
					} else if (typeof item.delivered === 'string') {
						isDelivered = item.delivered === "1";
					} else if (typeof item.delivered === 'boolean') {
						isDelivered = item.delivered;
					} else if (item.delivered === 1 || item.delivered === 0) {
						isDelivered = item.delivered === 1;
					}
					
					return {
						...item,
						delivered: isDelivered
					};
				});
				
				// Check if we have all expected orders
				const fetchedReceipts = [...new Set(selectedTableItems.map(item => item.receipt_number))];
				console.log(`Found ${fetchedReceipts.length} orders with ${selectedTableItems.length} items`);
				
				// Check if we're missing any orders
				const missingReceipts = expectedOrders.filter(order => 
					!fetchedReceipts.includes(order.receipt_number)).map(order => order.receipt_number);
				
				if (missingReceipts.length > 0) {
					console.log(`Missing ${missingReceipts.length} receipts: ${missingReceipts.join(', ')}`);
					
					// Try to fetch each missing receipt individually
					for (const receiptNumber of missingReceipts) {
						try {
							const receiptResponse = await fetch(
								`http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getOrderItemsByReceipt&receipt_number=${receiptNumber}`
							);
							
							if (receiptResponse.ok) {
								const receiptData = await receiptResponse.json();
								console.log(`Fetched additional data for receipt ${receiptNumber}:`, receiptData);
								
								if (Array.isArray(receiptData) && receiptData.length > 0) {
									// Process these items and add them to selectedTableItems
									const formattedItems = receiptData.map((item: any) => {
										let isDelivered = false;
										if (typeof item.delivered === 'string') {
											isDelivered = item.delivered === "1";
										} else if (typeof item.delivered === 'boolean') {
											isDelivered = item.delivered;
										} else if (item.delivered === 1 || item.delivered === 0) {
											isDelivered = item.delivered === 1;
										}
										
										return {
											...item,
											delivered: isDelivered,
											table_number: tableNumber // Ensure table number is set
										};
									});
									
									// Add the newly fetched items
									selectedTableItems = [...selectedTableItems, ...formattedItems];
								}
							} else {
								console.error(`Failed to fetch receipt ${receiptNumber}`);
							}
						} catch (error) {
							console.error(`Error fetching receipt ${receiptNumber}:`, error);
						}
					}
				}
				
				// Final count of orders after all fetches
				const finalReceipts = [...new Set(selectedTableItems.map(item => item.receipt_number))];
				console.log(`Final count: ${finalReceipts.length} orders with ${selectedTableItems.length} items`);
				
				if (selectedTableItems.length > 0) {
					showAlert(`Loaded ${selectedTableItems.length} items from ${finalReceipts.length} orders`, 'success');
				} else {
					showAlert('No items found for this table', 'warning');
				}
			} else {
				console.log('No items returned from API for table', tableNumber);
				showAlert('No order details found for this table', 'info');
			}
			
			// Show the modal regardless, even if empty (will show "No items" message)
			isTableDetailsModalVisible = true;
			
		} catch (error) {
			console.error('Error fetching table order details:', error);
			showAlert('Error loading order details', 'error');
		}
	}
	
	// Function to close table details modal
	function closeTableDetailsModal() {
		isTableDetailsModalVisible = false;
		selectedTableItems = [];
	}

	async function fetchTableStatus() {
		const response = await fetch('http://localhost/kaperustiko-possystem/backend/modules/check_tables.php');
		if (response.ok) {
			tableStatus = await response.json();
		} else {
			console.error('Failed to fetch table status');
		}
	}

	// Function to fetch reserve tables
	async function fetchReserveTables() {
		const response = await fetch('http://localhost/kaperustiko-possystem/backend/modules/get.php?action=getReserveTables');
		if (response.ok) {
			const data = await response.json();
			reservedTables = data.map((table: { table_number: string }) => table.table_number); // Assuming the response contains an array of reserved table objects
		} else {
			console.error('Failed to fetch reserved tables', response.statusText);
		}
	}
	

	// First, add a new variable to store special instructions
	let specialInstructions = '';

	async function voidQueuedOrders() {
		if (selectedTableDetails.orders.length > 0) {
			for (const order of selectedTableDetails.orders) {
				try {
					const response = await fetch(`http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=voidQueuedOrder&receipt_number=${order.receipt_number}`, {
						method: 'DELETE',
					});

					const data = await response.json();
					if (data.success) {
						console.log(`Successfully voided order: ${order.receipt_number}`);
						window.location.reload(); // Reload the window after successful void
					} else {
						console.error(`Failed to void order: ${data.message}`);
					}
				} catch (error) {
					console.error('Error voiding order:', error);
				}
			}
			// Optionally, refresh the queued orders after voiding
			await fetchQueuedOrders();
		} else {
			console.log('No orders to void.');
		}
	}

	function confirmVoidOrders() {
		isConfirmVoidVisible = true; // Show confirmation modal
	}

	function voidOrdersConfirmed() {
		voidQueuedOrders(); // Call the original void function
		isConfirmVoidVisible = false; // Hide confirmation modal
	}

	function closeConfirmVoid() {
		isConfirmVoidVisible = false; // Hide confirmation modal
	}

	// Add this function to handle marking all orders in the modal as done
	async function markAllOrdersAsDone() {
		try {
			// Get all unique receipt numbers for the current table
			const receiptNumbers = [...new Set(selectedTableItems.map(item => item.receipt_number))];
			
			// Update each receipt's status to "delivered"
			const updatePromises = receiptNumbers.map(receiptNumber => 
				fetch(`http://localhost/kaperustiko-possystem/backend/modules/update.php?action=updateTableStatus`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						receipt_number: receiptNumber,
						order_status: 'delivered'
					})
				})
			);
			
			// Wait for all updates to complete
			const results = await Promise.all(updatePromises);
			
			// Check if all updates were successful
			const allSuccessful = results.every(response => response.ok);
			
			if (allSuccessful) {
				showAlert(`All orders for Table ${selectedTableDetails.tableNumber} marked as delivered successfully`, 'success');
				
				// Update the local state to reflect the changes
				selectedTableItems = selectedTableItems.map(item => ({
					...item,
					delivered: true
				}));
				
				// Save the updated delivery status to localStorage
				saveDeliveryStatus();
				
				// Refresh the queued orders
				await fetchQueuedOrders();
			} else {
				showAlert('Failed to mark some orders as delivered', 'error');
			}
		} catch (error) {
			console.error('Error marking orders as delivered:', error);
			showAlert('Error marking orders as delivered', 'error');
		}
	}

	// Add this function near other async functions
	async function voidIndividualItem(receiptNumber: string, itemIndex: number) {
		try {
			const response = await fetch(
				`http://localhost/kaperustiko-possystem/backend/modules/delete.php?action=voidIndividualItem&receipt_number=${receiptNumber}&item_index=${itemIndex}`,
				{
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json'
					}
				}
			);

			if (!response.ok) {
				const errorText = await response.text();
				throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
			}

			const data = await response.json();
			if (data.success) {
				showAlert(`Item voided successfully`, 'success');
				
				// Remove the voided item from selectedTableItems
				selectedTableItems = selectedTableItems.filter((item, index) => 
					!(item.receipt_number === receiptNumber && index === itemIndex)
				);
				
				// If no more items for this receipt, remove it from the display
				if (!selectedTableItems.some(item => item.receipt_number === receiptNumber)) {
					// If no items remain at all, close the modal
					if (selectedTableItems.length === 0) {
						closeTableDetailsModal();
					}
				}
				
				// Refresh the queued orders to update the display
				await fetchQueuedOrders();
			} else {
				throw new Error(data.message || 'Failed to void item');
			}
		} catch (error: any) {
			console.error('Error voiding item:', error);
			showAlert(`Error voiding item: ${error.message}`, 'error');
		}
	}

	// ... existing code ...
	let totalOrderedItemsPrice: number = 0;

	// Update total price whenever orderedItems changes
	$: totalOrderedItemsPrice = orderedItems.reduce((total, item) => total + (item.order_price || 0), 0);

	// Function to update waiter order count
	async function updateWaiterOrderCount(waiterCode: string) {
		try {
			const response = await fetch(`${API_URL}/update_waiter_orders.php?waiter_code=${waiterCode}`);
			const data = await response.json();
			if (data.error) {
				console.error('Error updating waiter order count:', data.error);
				return false;
			}
			return true;
		} catch (error) {
			console.error('Error updating waiter order count:', error);
			return false;
		}
	}
	// ... existing code ...

	// In the receiptData object:
	const receiptData = {
		saveQueOrder: true,
		receiptNumber,
		date: new Date().toLocaleDateString(),
		time: new Date().toLocaleTimeString(),
		cashierName,
		itemsOrdered: orderedItems,
		totalAmount: totalOrderedItemsPrice,
		amountPaid: payment ? parseFloat(payment) : 0,
		change: payment ? Math.max(0, parseFloat(payment) - totalOrderedItemsPrice) : 0,
		order_take: isTakeOut ? 'Take Out' : 'Dine In',
		table_number: selectedTableNumber
	};
	// ... existing code ...

	// ... existing code ...
	async function fetchSalesData() {
		try {
			const currentDate = new Date().toLocaleDateString();
			console.log('API URL:', `${API_URL}?action=getSalesInformation&date=${currentDate}`);
			
			const response = await fetch(`${API_URL}?action=getSalesInformation&date=${currentDate}`);
			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`);
			}
			
			const data = await response.json();
			
			if (Array.isArray(data)) {
				salesData = data.map(item => {
					// Ensure items_ordered is an array
					if (!Array.isArray(item.items_ordered)) {
						console.error('Invalid items_ordered format:', item.items_ordered);
						item.items_ordered = [];
					}
					return item;
				});

				// Calculate total sales
				totalSales = salesData.reduce((acc, curr) => {
					const orderTotal = Array.isArray(curr.items_ordered) ? 
						curr.items_ordered.reduce((sum, item) => sum + (Number(item.order_price) || 0), 0) : 0;
					return acc + orderTotal;
				}, 0);
			} else {
				console.error('Invalid response format:', data);
				salesData = [];
				totalSales = 0;
			}
		} catch (error) {
			console.error('Error fetching sales data:', error);
			salesData = [];
			totalSales = 0;
		}
	}

	onMount(() => {
		fetchSalesData(); // Initial fetch
		salesDataInterval = setInterval(fetchSalesData, 5000); // Refresh every 5 seconds

		return () => {
			if (salesDataInterval) {
				clearInterval(salesDataInterval);
				salesDataInterval = null;
			}
		};
	});
	// ... existing code ...
</script>

<div class="flex h-screen">
	<div class="flex flex-grow overflow-hidden bg-gray-100">
		<div class="flex flex-col w-full overflow-auto p-4">
			<!-- Category buttons navigation -->
			<div class="sticky top-0 z-10 bg-white shadow-md py-3 px-2 mb-4 flex flex-wrap space-x-4 border-b border-gray-200">
				{#each ['All', 'Appetizer', 'Salad', 'Rice Meal', 'Steak And Salmon', 'Pasta', 'Sandwich', 'Pizza', 'Soup', 'Breakfast Menu', 'Side Dish Menu', 'Chicken', 'Pork', 'Beef', 'Specialty', 'Vegetables', 'Fish', 'Frappe', 'Soda', 'Fruit Shake', 'Beverage', 'Juice', 'Iced Coffee', 'Hot Coffee'] as category}
					<button
						class="rounded-md px-4 py-2 font-bold text-black transition duration-200"
						class:bg-cyan-950={selectedCategory === category}
						class:text-white={selectedCategory === category}
						class:bg-white={selectedCategory !== category}
						class:shadow-md={selectedCategory !== category}
						on:click={() => (selectedCategory = category)}
					>
						{category}
					</button>
				{/each}
			</div>

<!-- Category display header -->
<div class="mb-4 flex items-center justify-between font-bold text-black">
    {#if selectedCategory === 'All'}
        <p>Display All Menu</p>
    {:else if selectedCategory === 'Appetizer'}
        <p>Display Appetizer Menu</p>
    {:else if selectedCategory === 'Salad'}
        <p>Display Salad Menu</p>
    {:else if selectedCategory === 'Rice Meal'}
        <p>Display Rice Meal Menu</p>
    {:else if selectedCategory === 'Steak And Salmon'}
        <p>Display Steak And Salmon Menu</p>
    {:else if selectedCategory === 'Pasta'}
        <p>Display Pasta Menu</p>
    {:else if selectedCategory === 'Sandwich'}
        <p>Display Sandwich Menu</p>
    {:else if selectedCategory === 'Pizza'}
        <p>Display Pizza Menu</p>
    {:else if selectedCategory === 'Soup'}
        <p>Display Soup Menu</p>
    {:else if selectedCategory === 'Breakfast Menu'}
        <p>Display Breakfast Menu</p>
    {:else if selectedCategory === 'Side Dish Menu'}
        <p>Display Side Dish Menu</p>
    {:else if selectedCategory === 'Chicken'}
        <p>Display Chicken Menu</p>
    {:else if selectedCategory === 'Pork'}
        <p>Display Pork Menu</p>
    {:else if selectedCategory === 'Beef'}
        <p>Display Beef Menu</p>
    {:else if selectedCategory === 'Specialty'}
        <p>Display Specialty Menu</p>
    {:else if selectedCategory === 'Vegetables'}
        <p>Display Vegetables Menu</p>
    {:else if selectedCategory === 'Fish'}
        <p>Display Fish Menu</p>
    {:else if selectedCategory === 'Frappe'}
        <p>Display Frappe Menu</p>
    {:else if selectedCategory === 'Soda'}
        <p>Display Soda Menu</p>
    {:else if selectedCategory === 'Fruit Shake'}
        <p>Display Fruit Shake Menu</p>
    {:else if selectedCategory === 'Beverage'}
        <p>Display Beverage Menu</p>
    {:else if selectedCategory === 'Juice'}
        <p>Display Juice Menu</p>
    {:else if selectedCategory === 'Iced Coffee'}
        <p>Display Iced Coffee Menu</p>
    {:else if selectedCategory === 'Hot Coffee'}
        <p>Display Hot Coffee Menu</p>
    {/if}
    <p class="mr-4">{currentDay || ''} {currentTime ? '- ' + currentTime : ''}</p>
</div>
			<!-- Grid of menu items -->
			<div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 auto-rows-auto">
				{#each cardData
					.filter((item) => selectedCategory === 'All' || item.label === selectedCategory || item.label2 === selectedCategory)
					.sort((a, b) => (a.title1 || '').localeCompare(b.title1 || '')) as { code, title1, title2, price1, price2, price3, image, menu_no, label, label2, qty }}
					<Card
						{code}
						{title1}
						{title2}
						{price1}
						{price2}
						{price3}
						{label}
						{label2}
						{image}
						{menu_no}
						{qty}
						onAdd={handleAdd}
					/>
				{/each}
			</div>
		</div>
	</div>

	<!-- Right sidebar with fixed width -->
	<div class="flex-none w-[700px]">
        
		<!-- Orders Section -->
		<div class="fixed right-[350px] top-0 flex h-full w-[350px] flex-col items-center bg-gray-100 p-4 shadow-lg">
			<div class="mb-4 w-full rounded-md bg-green-800 py-2 text-center text-white">
				<p class="text-sm font-bold">Order Number {orderNumber}</p>
			</div>
		<!-- Added Table Number Dropdown -->
		<div class="mb-4 w-full">
			<label for="tableNumber" class="block text-gray-700">Table Number:</label>
			<select 
				bind:value={selectedTableNumber} 
				class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
			>
				<option value="">Select Table Number</option>
				
				<!-- Indoor Tables -->
				<optgroup label="Indoor Tables">
					{#each Array(20) as _, index}
						{@const tableNum = (index + 1).toString()}
						{@const isOccupied = tableStatus[tableNum] === true}
						{@const isReserved = reservedTables.includes(tableNum)}
						<option 
							value={tableNum} 
							disabled={isReserved} 
							class={isOccupied ? 'bg-yellow-300 text-black' : isReserved ? 'bg-red-500 text-white' : ''}
						>
							Table {tableNum} {isOccupied ? '(has orders)' : isReserved ? '(reserved)' : ''}
						</option>
					{/each}
				</optgroup>

				<!-- Outdoor Tables -->
				<optgroup label="Outdoor Tables">
					{#each Array(15) as _, index}
						{@const tableNum = `O${index + 1}`}
						{@const isOccupied = tableStatus[tableNum] === true}
						{@const isReserved = reservedTables.includes(tableNum)}
						<option 
							value={tableNum} 
							disabled={isReserved} 
							class={isOccupied ? 'bg-yellow-300 text-black' : isReserved ? 'bg-red-500 text-white' : ''}
						>
							Table {tableNum} {isOccupied ? '(has orders)' : isReserved ? '(reserved)' : ''}
						</option>
					{/each}
				</optgroup>

				<!-- Garden Tables -->
				<optgroup label="Garden Tables">
					{#each Array(6) as _, index}
						{@const tableNum = `G${index + 1}`}
						{@const isOccupied = tableStatus[tableNum] === true}
						{@const isReserved = reservedTables.includes(tableNum)}
						<option 
							value={tableNum} 
							disabled={isReserved} 
							class={isOccupied ? 'bg-yellow-300 text-black' : isReserved ? 'bg-red-500 text-white' : ''}
						>
							Table {tableNum} {isOccupied ? '(has orders)' : isReserved ? '(reserved)' : ''}
						</option>
					{/each}
				</optgroup>
			</select>
		</div>	
			<div class="mb-4 max-h-full w-full flex-grow space-y-2 overflow-y-auto">
				{#if orderedItems.length > 0}
					{#each orderedItems as item, index}
						<div class="flex flex-col rounded-lg bg-white p-4 shadow-md">
							<div class="flex items-center justify-between">
								<p class="text-gray-600">Code: {item.code}</p>
							</div>
							<div class="flex items-center justify-between">
								<p class="font-semibold text-gray-800">{item.order_name}</p>
								<p class="text-right font-semibold text-gray-800">₱{item.order_price}.00</p>
							</div>
							<div class="flex items-center justify-between">
								<p class="text-gray-600">Size: {item.order_size}</p>
							</div>
							{#if item.order_addons !== 'None'}
								<ul class="list-disc pl-5">
									{#if item.order_addons !== 'None'}
										<div class="flex items-center justify-between">
											<li class="text-gray-600">{item.order_addons}</li>
											<p class="text-right text-gray-600">₱{item.order_addons_price}.00</p>
										</div>
									{/if}
									{#if item.order_addons2 !== 'None'}
										<div class="flex items-center justify-between">
											<li class="text-gray-600">{item.order_addons2}</li>
											<p class="text-right text-gray-600">₱{item.order_addons_price2}.00</p>
										</div>
									{/if}
									{#if item.order_addons3 !== 'None'}
										<div class="flex items-center justify-between">
											<li class="text-gray-600">{item.order_addons3}</li>
											<p class="text-right text-gray-600">₱{item.order_addons_price3}.00</p>
										</div>
									{/if}
								</ul>
							{/if}
							
							<!-- Special Instructions display -->
							{#if item.special_instructions && item.special_instructions !== ''}
								<div class="mt-2 bg-yellow-50 p-2 rounded-md">
									<p class="text-xs font-medium text-yellow-800">Special instructions:</p>
									<p class="text-sm text-gray-700">{item.special_instructions}</p>
								</div>
							{/if}
							
							<!-- Quantity controls -->
							<div class="mt-2 flex items-center justify-between">
								<div class="flex items-center">
									<button 
										on:click={() => {
											const currentQty = Number(item.order_quantity);
											updateItemQuantity(index, currentQty - 1);
										}}
										class="h-8 w-8 rounded-l bg-gray-200 font-bold text-gray-700 hover:bg-gray-300"
									>
										-
									</button>
									<span class="flex h-8 w-12 items-center justify-center bg-gray-100 text-center">
										{item.order_quantity}
									</span>
									<button 
										on:click={() => {
											const currentQty = Number(item.order_quantity);
											updateItemQuantity(index, currentQty + 1);
										}}
										class="h-8 w-8 rounded-r bg-gray-200 font-bold text-gray-700 hover:bg-gray-300"
									>
										+
									</button>
								</div>
								<button on:click={() => voidOrder(index)} class="rounded-md bg-red-100 px-3 py-1 text-center text-red-600 hover:bg-red-200">
									Void
								</button>
							</div>
						</div>
					{/each}
				{:else}
					<p class="text-center text-gray-600">No items ordered yet.</p>
				{/if}
			</div>
			<div class="mt-auto w-full rounded-lg p-4 shadow-md">
				<div class="mb-4 flex w-full items-center justify-between border-b pb-2">
					<p class="font-semibold text-gray-700">Total Cost:</p>
					<p class="font-bold text-gray-800">
						₱{orderedItems
							.reduce(
								(total, item) =>
									total + parseFloat(item.order_price.toString().replace('₱', '').replace(',', '')),
								0
							)
							.toFixed(2)}
					</p>
				</div>
                <div class="grid h-[200px] w-full grid-cols-4 gap-2">
                    <button
                        class="col-span-2 rounded py-2 font-bold text-gray-800 {isDineIn
                            ? 'bg-blue-500 text-white'
                            : 'bg-gray-300'}"
                        on:click={() => {
                            isDineIn = true;
                            isTakeOut = false;
                        }}
                    >
                        Dine In
                    </button>
        
                    <button
                        class="col-span-2 rounded py-2 font-bold text-gray-800 {isTakeOut
                            ? 'bg-blue-500 text-white'
                            : 'bg-gray-300'}"
                        on:click={() => {
                            isDineIn = false;
                            isTakeOut = true;
                        }}
                    >
                        Take Out
                    </button>
        
                    {#each ['Refresh', 'Save Order'] as key, index}
                        <button
                            on:click={() => {
                                if (key === 'Refresh') {
                                    // Refresh the page
                                    window.location.reload(); // This will refresh the page
                                } else if (key === 'Save Order') {
                                    // Handle save order action
                                    handlePlaceOrder();
                                }

                                handleButtonClick(
                                    key,
                                    index,
                                    orderedItems,
                                    payment,
                                    handleBackspace,
                                    handleClear,
                                    voidOrder,
                                    handlePlaceOrder,
                                    handleNumberInput,
                                    isDineIn,
                                    isTakeOut
                                );
                                currentInputStore.update((store) => {
                                    return {
                                        ...store,
                                        currentInput: key, // Update the current input
                                        amountPaid: parseFloat(amountPaid.replace('₱', '').replace(',', ''))
                                    };
                                });
                            }}
                            class="col-span-2 rounded-lg py-2 font-bold text-white transition duration-200 
                                   {key === 'Save Order' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-red-600 hover:bg-red-700'}"
                        >
                            {key}
                        </button>
                    {/each}
                </div>
			</div>
		</div>

	<!-- Que Order Section -->
		<div class="fixed right-0 top-0 flex h-full w-[350px] flex-col items-center bg-gray-100 p-4 shadow-lg">
			<div class="mb-4 w-full rounded-md bg-green-800 py-2 text-center text-white">
				<p class="text-sm font-bold">Que Orders</p>
			</div>
			<div class="mb-4 max-h-full w-full flex-grow space-y-2 overflow-y-auto">
				{#if queuedOrders.length > 0}
					<!-- Group orders by table -->
					{#each [...new Set(queuedOrders.map(order => order.table_number))]
						.sort((a, b) => {
							// Get orders for each table
							const tableOrdersA = queuedOrders.filter(order => order.table_number === a);
							const tableOrdersB = queuedOrders.filter(order => order.table_number === b);
							
							// Check if all orders are delivered for each table
							const allDeliveredA = tableOrdersA.every(order => order.order_status === 'delivered');
							const allDeliveredB = tableOrdersB.every(order => order.order_status === 'delivered');
							
							// Sort by delivery status first (pending on top)
							if (allDeliveredA !== allDeliveredB) {
								return allDeliveredA ? 1 : -1; // Put non-delivered (pending) first
							}
							
							// If delivery status is the same, sort by table number
							return Number(a) - Number(b);
						}) as tableNum}
						{@const tableOrders = queuedOrders.filter(order => order.table_number === tableNum)}
						{@const allOrdersDelivered = tableOrders.every(order => order.order_status === 'delivered')}
						<div class="rounded-lg {allOrdersDelivered ? 'bg-gray-800' : 'bg-blue-100'} p-2 mb-3">
							<div class="flex items-center justify-between {allOrdersDelivered ? 'bg-gray-900' : 'bg-blue-500'} text-white p-2 rounded-t-lg">
								<h3 class="font-bold">Table {tableNum}</h3>
								<span class="{allOrdersDelivered ? 'bg-gray-700' : 'bg-white'} {allOrdersDelivered ? 'text-gray-300' : 'text-blue-500'} px-2 py-1 rounded-full text-xs font-bold">
									{tableOrders.length} orders
								</span>
							</div>
							
							<!-- Make the whole table section clickable -->
							<!-- svelte-ignore a11y-click-events-have-key-events -->
							<div 
								class="p-2 {allOrdersDelivered ? 'bg-gray-700 cursor-not-allowed opacity-75' : 'bg-white cursor-pointer hover:bg-blue-50'} rounded-b-lg transition-colors"
								on:click={() => !allOrdersDelivered && openTableDetails(tableNum)}
								on:keydown={(e) => !allOrdersDelivered && e.key === 'Enter' && openTableDetails(tableNum)}
							>
								<p class="text-center {allOrdersDelivered ? 'text-gray-300' : 'text-blue-600'} font-semibold mb-1">
									{allOrdersDelivered ? 'All orders delivered' : 'Click to view orders'}
								</p>
								
								{#each tableOrders as order}
								<div class="flex flex-col rounded-lg {allOrdersDelivered ? 'bg-gray-600 pointer-events-none' : 'bg-white'} p-4 shadow-md mt-2">
									<div class="flex items-center justify-between">
										<p class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-600'}">Order No: </p>
										<span class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-900'}">{order.receipt_number}</span>
									</div>
									{#if order.waiter_name}
									<div class="flex items-center justify-between">
										<p class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-600'}">Waiter:</p>
										<span class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-900'}">{order.waiter_name}</span>
									</div>
									{/if}
									<div class="flex items-center justify-between">
										<p class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-800'} font-semibold">Order Total Price:</p>
										<span class="{allOrdersDelivered ? 'text-gray-300' : 'text-gray-900'}">₱{order.total_amount}</span>
									</div>
									<div 
										class="flex items-center justify-between"
										role="button" 
										tabindex="0"
										on:click={() => !allOrdersDelivered && openTableDetails(tableNum)}
										on:keydown={(e) => !allOrdersDelivered && e.key === 'Enter' && openTableDetails(tableNum)}
									>
										
									</div>
								</div>
								{/each}
							</div>
						</div>
					{/each}
				{:else}
					<p class="text-center text-gray-600 text-lg">No queued orders available.</p>
				{/if}
			</div>
			<button on:click={() => isSleepActive = true} class="mt-2 w-full rounded-md bg-blue-500 px-4 py-2 text-white">
				Sleep
			</button>
		</div>
	</div>
</div>

{#if isVariationVisible}
	<div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-90 z-50">
		<div class="relative w-full max-w-md rounded-lg bg-white p-8 shadow-xl">
			<h2 class="mb-6 text-center text-2xl font-bold">
				Add {selectedItem?.title1} {selectedItem?.title2}
			</h2>
			<p class="mb-6 text-center text-lg">
				Price: ₱{displayedPrice} (Add-ons: ₱{calculateAddonsPrice(selectedAddons)})
			</p>
			{#if selectedItem?.image}
				<img
					src={`/foods/${selectedItem.image}`}
					alt={selectedItem.title1}
					class="mb-4 h-[400px] w-full rounded object-cover"
				/>
			{/if}

			<label for="size" class="mb-2 block text-sm font-medium">Size:</label>
			<div class="mb-6 flex w-full space-x-4">
				{#if selectedItem?.label === 'Iced Coffee' || selectedItem?.label === 'Hot Coffee' || selectedItem?.label === 'Frappe'}
					<button
						on:click={() => selectSize('16oz')}
						class="flex-1 rounded-md border border-gray-300 p-3 {selectedSize === '16oz'
							? 'bg-blue-500 text-white'
							: ''}">16oz</button
					>
					<button
						on:click={() => selectSize('22oz')}
						class="flex-1 rounded-md border border-gray-300 p-3 {selectedSize === '22oz'
							? 'bg-blue-500 text-white'
							: ''}">22oz</button
					>
				{:else}
					<button
						on:click={() => selectSize('Regular')}
						class="flex-1 rounded-md border border-gray-300 p-3 {selectedSize === 'Regular'
							? 'bg-blue-500 text-white'
							: ''}">Regular</button
					>
				{/if}
			</div>

			<label for="addons" class="mb-2 block text-sm font-medium">Add-ons:</label>
			<div class="mb-6">
				{#if selectedItem?.label === 'Tea' || selectedItem?.label2 === 'Tea'}
					<label class="mb-4 block">
						<input type="checkbox" bind:group={selectedAddons} value="Sugar" class="mr-2 h-6 w-6" />
						Sugar - ₱5
					</label>
					<label class="mb-4 block">
						<input type="checkbox" bind:group={selectedAddons} value="Bobba" class="mr-2 h-6 w-6" />
						Bobba - ₱10
					</label>
					<label class="mb-4 block">
						<input type="checkbox" bind:group={selectedAddons} value="Milk" class="mr-2 h-6 w-6" /> Milk
						- ₱7
					</label>
				{:else if selectedItem?.label === 'Pasta' || selectedItem?.label2 === 'Pasta'}
					<label class="mb-4 block">
						<input
							type="checkbox"
							bind:group={selectedAddons}
							value="Extra Cheese"
							class="mr-2 h-6 w-6"
						/> Extra Cheese - ₱15
					</label>
					<label class="mb-4 block">
						<input type="checkbox" bind:group={selectedAddons} value="Bacon" class="mr-2 h-6 w-6" />
						Bacon - ₱20
					</label>
					<label class="mb-4 block">
						<input
							type="checkbox"
							bind:group={selectedAddons}
							value="Olives"
							class="mr-2 h-6 w-6"
						/> Olives - ₱10
					</label>
				{:else if selectedItem?.label === 'Soda' || selectedItem?.label2 === 'Soda'}
					<!-- No add-ons for Soda -->
				{:else if selectedItem?.label === 'Ulam' || selectedItem?.label2 === 'Ulam'}
					<!-- No add-ons for Ulam -->
				{/if}
			</div>

			<!-- Special Instructions field -->
			<label for="special-instructions" class="mb-2 block text-sm font-medium">Special Instructions:</label>
			<textarea
				id="special-instructions"
				bind:value={specialInstructions}
				class="mb-6 w-full rounded-md border border-gray-300 p-3 text-sm"
				placeholder="E.g., Less ice, no shrimp (allergic), extra spicy, etc."
				rows="3"
			></textarea>

			<label for="quantity" class="mb-2 block text-sm font-medium">Quantity:</label>
			<div class="mb-6 flex justify-between">
				<button on:click={decreaseQuantity} class="flex-1 rounded-md border border-gray-300 p-3"
					>-</button
				>
				<input
					type="number"
					id="quantity"
					bind:value={quantity}
					min="1"
					class="mx-2 block w-full rounded-md border border-gray-300 p-3 text-center"
				/>
				<button on:click={increaseQuantity} class="flex-1 rounded-md border border-gray-300 p-3"
					>+</button
				>
			</div>

			<div class="flex justify-between">
				<button
					on:click={closePopup}
					class="rounded-md bg-red-500 px-6 py-3 text-white transition hover:bg-red-600"
					>Cancel</button
				>
				<button
					on:click={() => selectedItem && handleOrder(selectedItem)}
					class="rounded-md bg-blue-500 px-6 py-3 text-white transition hover:bg-blue-600"
					>Add to Order</button
				>
			</div>
		</div>
	</div>
{/if}

{#if isWaiterCodePopupVisible}
	<div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-90 z-50">
		<div class="relative w-full max-w-3xl rounded-lg bg-white p-12 shadow-xl">
			<h2 class="mb-6 text-center text-3xl font-bold">Enter Waiter Code</h2>
			<p class="mb-6 text-center text-gray-600 text-lg">Please enter your waiter code to process this order</p>
			<div
				class="mb-8 w-full rounded border border-gray-300 p-6 text-center text-5xl font-bold h-24 flex items-center justify-center"
			>
				{waiterCode ? '*'.repeat(waiterCode.length) : ''}
			</div>
			
			<!-- Numeric Keypad -->
			<div class="mb-8">
				<!-- Backspace and Clear buttons above the numeric keypad -->
				<div class="mb-6 flex justify-between gap-4">
					<button
						on:click={handleWaiterCodeClear}
						class="flex-1 flex h-20 items-center justify-center rounded-md bg-yellow-500 text-2xl font-bold text-white"
					>
						Clear
					</button>
					<button
						on:click={handleWaiterCodeBackspace}
						class="flex-1 flex h-20 items-center justify-center rounded-md bg-red-500 text-2xl font-bold text-white"
					>
						⌫
					</button>
				</div>
				
				<!-- Numbers row -->
				<div class="mb-6 flex justify-center gap-4">
					{#each [1, 2, 3, 4, 5, 6, 7, 8, 9, 0] as num}
						<button
							on:click={() => handleWaiterCodeInput(num.toString())}
							class="flex h-20 w-20 items-center justify-center rounded-md bg-gray-200 text-3xl font-bold hover:bg-gray-300"
						>
							{num}
						</button>
					{/each}
				</div>
			</div>

			<!-- Letter Keypad in QWERTY layout -->
			<div class="mt-8">
				<!-- First row -->
				<div class="mb-4 flex justify-center gap-2">
					{#each ['Q','W','E','R','T','Y','U','I','O','P'] as letter}
						<button
							on:click={() => handleWaiterCodeInput(letter)}
							class="flex h-20 w-14 items-center justify-center rounded-md bg-blue-100 text-2xl font-bold hover:bg-blue-200"
						>
							{letter}
						</button>
					{/each}
				</div>
				
				<!-- Second row -->
				<div class="mb-4 flex justify-center gap-2">
					{#each ['A','S','D','F','G','H','J','K','L'] as letter}
						<button
							on:click={() => handleWaiterCodeInput(letter)}
							class="flex h-20 w-14 items-center justify-center rounded-md bg-blue-100 text-2xl font-bold hover:bg-blue-200"
						>
							{letter}
						</button>
					{/each}
				</div>
				
				<!-- Third row -->
				<div class="mb-4 flex justify-center gap-2">
					{#each ['Z','X','C','V','B','N','M'] as letter}
						<button
							on:click={() => handleWaiterCodeInput(letter)}
							class="flex h-20 w-14 items-center justify-center rounded-md bg-blue-100 text-2xl font-bold hover:bg-blue-200"
						>
							{letter}
						</button>
					{/each}
				</div>
			</div>
			
			<div class="mt-8 flex justify-between">
				<button on:click={closeWaiterCodePopup} class="rounded-md bg-red-500 px-10 py-6 text-2xl font-bold text-white">
					Cancel
				</button>
				<button on:click={verifyWaiterCode} class="rounded-md bg-blue-500 px-10 py-6 text-2xl font-bold text-white">
					Confirm
				</button>
			</div>
		</div>
	</div>
{/if}



{#if isSleepActive}
	<button class="bg-cyan-950 h-screen w-full flex flex-col items-center justify-center z-50 fixed inset-0" on:click={() => isSleepActive = false} aria-label="Close Sleep Overlay">
		<ul class="circles">
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
			<li class="animate-pulse"></li>
		</ul>
		<div class="flex items-center justify-center w-full h-full">
			<img src="./icon.png" alt="Fallback description if image fails to load" class="max-w-full h-auto" aria-hidden="true" />
		</div>
	</button>
{/if}

<!-- Table Details Modal -->
{#if isTableDetailsModalVisible}
    <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-90 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
            <!-- Modal Header -->
            <div class="bg-blue-600 text-white px-6 py-4 flex justify-between items-center">
                <h2 class="text-2xl font-bold">Table {selectedTableDetails.tableNumber} Orders</h2>
                <div class="flex items-center gap-4">
                    <button 
                        on:click={markAllOrdersAsDone}
                        class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline transition duration-200"
                    >
                        Mark All as Done
                    </button>
                    <button 
                        on:click={closeTableDetailsModal}
                        class="bg-blue-700 hover:bg-blue-800 rounded-full p-2 focus:outline-none"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Modal Body -->
            <div class="p-6 overflow-y-auto flex-grow max-h-[calc(90vh-8rem)]">
                {#if selectedTableItems.length > 0}
                    <!-- Group items by receipt number -->
                    {#each [...new Set(selectedTableItems.map(item => item.receipt_number))].sort() as receiptNumber}
                        <div class="mb-8 border border-gray-200 rounded-lg shadow-md">
                            <div class="bg-gray-100 px-4 py-3 border-b rounded-t-lg">
                                <div class="flex justify-between items-center">
                                    <h3 class="text-lg font-bold text-gray-800">Order #{receiptNumber}</h3>
                                    <div>
                                        {#if selectedTableItems.find(item => item.receipt_number === receiptNumber)?.waiter_name}
                                            <span class="text-sm text-gray-600 mr-4">
                                                Waiter: {selectedTableItems.find(item => item.receipt_number === receiptNumber)?.waiter_name}
                                            </span>
                                        {/if}
 
										
                                    </div>
                                </div>
                            </div>
                            
                            <div class="p-4">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Addons</th>
                                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Delivered</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            {#each selectedTableItems.filter(item => item.receipt_number === receiptNumber) as item, itemIndex}
                                                <tr class={`hover:bg-gray-50 ${item.delivered ? 'bg-green-50' : ''}`}>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.order_name}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.order_size}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.order_quantity}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {#if item.order_addons !== 'None' || item.order_addons2 !== 'None' || item.order_addons3 !== 'None'}
                                                            <ul class="list-disc pl-5">
                                                                {#if item.order_addons && item.order_addons !== 'None'}<li>{item.order_addons} (+₱{item.order_addons_price})</li>{/if}
                                                                {#if item.order_addons2 && item.order_addons2 !== 'None'}<li>{item.order_addons2} (+₱{item.order_addons_price2})</li>{/if}
                                                                {#if item.order_addons3 && item.order_addons3 !== 'None'}<li>{item.order_addons3} (+₱{item.order_addons_price3})</li>{/if}
                                                            </ul>
                                                        {:else}
                                                            None
                                                        {/if}
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900 font-semibold">₱{item.item_total_price}</td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                        <div class="flex items-center justify-center space-x-2">
                                                            <input 
                                                                type="checkbox" 
                                                                checked={item.delivered} 
                                                                on:change={() => toggleDeliveryStatus(item)}
                                                                class="h-5 w-5 text-blue-600 rounded focus:ring-blue-500"
                                                                aria-label="Mark as delivered"
                                                            />
                                                            <button
                                                                on:click={() => voidIndividualItem(receiptNumber, itemIndex)}
                                                                class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs transition duration-200"
                                                            >
                                                                Void
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            {/each}
                                            <!-- Total row -->
                                            <tr class="bg-gray-50">
                                                <td colspan="4" class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">Total:</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-right text-gray-900">
                                                    ₱{selectedTableItems
                                                        .filter(item => item.receipt_number === receiptNumber)
                                                        .reduce((total, item) => total + parseFloat(item.item_total_price.toString()), 0)
                                                        .toFixed(2)}
                                                </td>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    {/each}
                {:else}
                    <p class="text-center text-gray-500 py-8">No detailed order information available for this table.</p>
                {/if}
            </div>
            
            <!-- Modal Footer -->
            <div class="bg-gray-100 px-6 py-4 border-t flex justify-between space-x-2">
                <button 
                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded focus:outline-none focus:shadow-outline transition duration-200"
                    on:click={closeTableDetailsModal}
                >
                    Confirm
                </button>
                <button 
                    class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded focus:outline-none focus:shadow-outline transition duration-200"
                    on:click={confirmVoidOrders}
                >
                    Void Que Orders
                </button>
            </div>
        </div>
    </div>
{/if}

<!-- Confirmation Modal -->
{#if isConfirmVoidVisible}
    <div class="fixed inset-0 flex items-center justify-center bg-black bg-opacity-70 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h2 class="text-lg font-bold mb-4">Confirm Void</h2>
            <p class="mb-4">Are you sure you want to void this order?</p>
            <div class="flex justify-between">
                <button 
                    on:click={voidOrdersConfirmed} 
                    class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2 rounded focus:outline-none transition duration-200"
                >
                    Yes, Void
                </button>
                <button 
                    on:click={closeConfirmVoid} 
                    class="flex-1 bg-gray-300 hover:bg-gray-400 text-black font-bold py-2 rounded focus:outline-none transition duration-200"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
{/if}

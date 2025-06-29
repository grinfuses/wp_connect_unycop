# Unycop WooCommerce Connector

## Overview
This WordPress plugin connects WooCommerce with the Unycop pharmacy management system via CSV file exchange. It automates the synchronization of product stock and order data between your online store and Unycop Win (installed in the pharmacy).

## Features
- Automatically updates WooCommerce product stock from a CSV file (`stocklocal.csv`) generated by Unycop Win.
- Generates an `orders.csv` file with completed WooCommerce orders for Unycop Win to import.
- Provides REST API endpoints for secure integration with the Unycop Win connector.
- Admin settings page to configure file paths, security token, and cron frequency.

## Requirements
- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.2+
- Unycop Win and its connector installed on the pharmacy PC

## Installation
1. Clone or download this repository.
2. Zip the plugin folder if needed.
3. Upload and activate the plugin via the WordPress admin panel.

## Configuration
After activation, go to **Settings > Unycop Connector** in the WordPress admin to configure:
- **CSV files path:** Directory where `orders.csv` and `stocklocal.csv` are stored.
- **Security token:** Token required to access the REST API endpoints.
- **Stock cron frequency:** How often the plugin checks for new stock updates.

## REST API Endpoints
All endpoints require the `token` parameter for authentication (set in the admin page).

### 1. Download Orders CSV
- **URL:** `/wp-json/unycop/v1/orders?token=YOUR_TOKEN`
- **Method:** GET
- **Response:** Returns the `orders.csv` file with all completed orders.

### 2. Trigger Stock Update
- **URL:** `/wp-json/unycop/v1/stock-update?token=YOUR_TOKEN`
- **Method:** POST
- **Response:** `{ "success": true }` if the stock update was processed.

## Workflow
1. **Order Export:**
   - Every time an order is completed in WooCommerce, `orders.csv` is updated automatically.
   - The Unycop Win connector can download this file via the REST API.
2. **Stock Import:**
   - Unycop Win generates `stocklocal.csv` with updated stock data and uploads it to the configured directory.
   - The plugin periodically (or via API trigger) reads this file and updates WooCommerce product stock.

## Stocklocal.csv Flow and Testing

### Minimal File Format Example
```
CN;Stock;PVP con IVA;IVA;Descripción
00000000000000;1;12.50;21;Producto de prueba
```
- **CN:** Must match the SKU of the product in WooCommerce.
- **Stock:** New stock value to set.
- **PVP con IVA:** Product price with tax.
- **IVA:** Tax percentage.
- **Descripción:** (Optional) Product description.

### How to Test Stock Update
1. Place your `stocklocal.csv` file in the `wp-content/uploads/unycop/` directory of your WordPress installation.
2. Trigger the stock update by making a **POST** request to:
   ```
   https://yourdomain.com/wp-json/unycop/v1/stock-update?token=YOUR_TOKEN
   ```
   You can use tools like Postman, Insomnia, or curl:
   ```bash
   curl -X POST "https://yourdomain.com/wp-json/unycop/v1/stock-update?token=YOUR_TOKEN"
   ```
   - Make sure to use the **POST** method, not GET.
3. If successful, you will receive:
   ```json
   { "success": true }
   ```
4. Check WooCommerce to verify that the product(s) have been updated according to the CSV.

## Support
For questions or support, contact: info@illoque.com

---
**Author:** jnaranjo
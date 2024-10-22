<?php
/*
Plugin Name: WooCommerce Unycop Connector
Description: Actualiza productos desde un CSV de stock y genera un archivo orders.csv con los pedidos sincronizándolo con Unycop, almacenándolo localmente cada hora.
Version: 1.0
Author: jnaranjo
*/

// Hook para programar la sincronización de productos y la generación del CSV de pedidos
register_activation_hook(__FILE__, 'wp_schedule_product_sync');
register_deactivation_hook(__FILE__, 'wp_clear_product_sync_schedule');

// Programar la tarea cada hora
function wp_schedule_product_sync() {
    if (!wp_next_scheduled('product_sync_event')) {
        wp_schedule_event(time(), 'hourly', 'product_sync_event');
    }
}

function wp_clear_product_sync_schedule() {
    $timestamp = wp_next_scheduled('product_sync_event');
    wp_unschedule_event($timestamp, 'product_sync_event');
}

// Función principal que se ejecuta cada hora
add_action('product_sync_event', 'sync_products_and_export_orders');

// Función que llama a las funciones de sincronización y exportación
function sync_products_and_export_orders() {
    sync_stock_from_csv(); // Actualiza productos desde el CSV de stock
    generate_orders_csv(); // Genera el archivo orders.csv y lo guarda localmente
}

// Función para actualizar productos desde el CSV de stock
function sync_stock_from_csv() {
    $csv_file = '/var/www/html/wp-content/uploads/unycop/stocklocal.csv'; // Especifica la ruta local
    
    if (!file_exists($csv_file)) {
        return;
    }

    // Abre el CSV y actualiza los productos
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        // Leer encabezados
        fgetcsv($handle, 1000, ";");

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            $cn = $data[0]; // CN del artículo (Referencia)
            $stock = $data[1]; // Existencias
            $price_with_tax = $data[2]; // PVP con IVA
            $iva = $data[3]; // Tipo de IVA
            $prospecto = $data[4]; // Enlace al prospecto PDF
            $ean13 = $data[5]; // Código de barras
            $description = $data[6]; // Descripción del medicamento
            $pc = $data[7]; // Último precio de coste
            $family = $data[8]; // Familia
            $category = $data[9]; // Categoría
            $subcategory = $data[10]; // Subcategoría
            $lab = $data[11]; // Laboratorio
            $pvp2 = $data[12]; // PVP2
            $locations = $data[13]; // Ubicaciones

            // Busca el producto por CN (SKU en WooCommerce)
            $product_id = wc_get_product_id_by_sku($cn);
            if ($product_id) {
                // Actualizar el producto
                $product = wc_get_product($product_id);

                // Actualiza stock
                wc_update_product_stock($product_id, $stock, 'set');

                // Actualiza precios
                $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                $product->set_regular_price($price_with_tax); // Precio con IVA
                $product->set_price($price_without_tax); // Precio sin IVA

                // Actualizar otros campos si es necesario
                update_post_meta($product_id, '_prospecto_url', $prospecto);
                $product->set_description($description);
                $product->set_sku($ean13);

                // Guardar producto actualizado
                $product->save();
            } else {
                // Crear producto si no existe
                $new_product = new WC_Product();
                $new_product->set_sku($cn);
                $new_product->set_name($description);
                $new_product->set_regular_price($price_with_tax);
                $new_product->set_stock_quantity($stock);
                $new_product->set_description($description);
                $new_product->save();
            }
        }
        fclose($handle);
    }
}

// Función para generar el archivo orders.csv y guardarlo en local
function generate_orders_csv() {
    // Obtener pedidos completados de WooCommerce
    $args = array(
        'status' => 'completed',
        'limit' => -1
    );
    $orders = wc_get_orders($args);

    // Crear el archivo CSV
    $csv_file = '/var/www/html/wp-content/uploads/unycop/orders.csv'; // Especifica la ruta local
    $handle = fopen($csv_file, 'w');

    // Encabezados del CSV según las especificaciones proporcionadas
    fputcsv($handle, array(
        'Referencia del pedido', 'ID del pedido', 'Fecha', 'Total Productos', 
        'Total Pago', 'Gastos de Envío', 'Precio Unitario sin IVA', 
        'Precio Unitario con IVA', 'ID Cliente Web', 'Nombre Cliente', 
        'Apellidos Cliente', 'Email Cliente', 'Teléfono Cliente', 
        'DNI', 'Dirección', 'CP', 'Ciudad', 'Provincia', 
        'Código Nacional del Producto', 'Cantidad', 'PVP Web'
    ), ';');

    foreach ($orders as $order) {
        $customer_id = $order->get_customer_id();
        $customer = new WC_Customer($customer_id);
        $billing_address = $order->get_address('billing');
        $shipping_cost = $order->get_shipping_total();
        $total_paid = $order->get_total();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $unit_price_excl_tax = $item->get_subtotal() / $item->get_quantity(); // Precio sin IVA
            $unit_price_incl_tax = $item->get_total() / $item->get_quantity(); // Precio con IVA
            $national_code = substr($product->get_sku(), 0, 6); // Suponiendo que el SKU contiene el código nacional

            // Formatear la fecha del pedido
            $order_date = $order->get_date_created()->date('d/m/Y H:i:s');

            // Crear una línea de datos en el CSV
            $data = array(
                $order->get_meta('observaciones_unycop', true), // Referencia del pedido (campo observaciones)
                $order->get_id(),
                $order_date,
                $order->get_subtotal(), // Total de productos con IVA
                $total_paid, // Total pagado
                $shipping_cost, // Gastos de envío
                $unit_price_excl_tax, // Precio unitario sin IVA
                $unit_price_incl_tax, // Precio unitario con IVA
                $customer_id,
                $billing_address['first_name'], // Nombre cliente
                $billing_address['last_name'], // Apellidos cliente
                $billing_address['email'], // Email cliente
                $billing_address['phone'], // Teléfono cliente
                $billing_address['dni'], // DNI (puede necesitarse un campo personalizado)
                $billing_address['address_1'], // Dirección
                $billing_address['postcode'], // Código postal
                $billing_address['city'], // Ciudad
                $billing_address['state'], // Provincia
                $national_code, // Código nacional del producto
                $item->get_quantity(), // Cantidad vendida
                $unit_price_excl_tax // PVP web (precio unitario sin IVA y sin descuento)
            );

            // Escribir la línea de datos en el CSV
            fputcsv($handle, $data, ';');
        }
    }

    fclose($handle);

    // El archivo se almacena en la ubicación especificada
}
<?php
/*
Plugin Name: WooCommerce Unycop Connector
Description: Sincroniza WooCommerce con Unycop Win importando el stock de productos desde un archivo CSV y exportando los pedidos completados a orders.csv. Incluye panel de configuración y endpoints REST API seguros para una integración eficiente en farmacia.
Version: 2.0
Author: jnaranjo - illoque.com
*/

// Hook para programar la sincronización de productos y la generación del CSV de pedidos
register_activation_hook(__FILE__, 'wp_schedule_product_sync');
register_deactivation_hook(__FILE__, 'wp_clear_product_sync_schedule');

// Programar la tarea cada hora (ahora configurable)
function wp_schedule_product_sync() {
    $frequency = get_option('unycop_cron_frequency', 'hourly');
    if (!wp_next_scheduled('product_sync_event')) {
        wp_schedule_event(time(), $frequency, 'product_sync_event');
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
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    
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
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/orders.csv';
    $handle = fopen($csv_file, 'w');

    // Encabezados del CSV según el orden del manual de Unycop
    fputcsv($handle, array(
        'Referencia del pedido', // 1
        'id del pedido',         // 2
        'Fecha',                // 3
        'Id cliente web',       // 4
        'Nombre cliente',       // 5
        'Apellidos cliente',    // 6
        'Email cliente',        // 7
        'Teléfono cliente',     // 8
        'DNI',                  // 9
        'dirección',            // 10
        'CP',                   // 11
        'Ciudad',               // 12
        'Provincia',            // 13
        'Código nacional del producto', // 14
        'Cantidad',             // 15
        'PVP web',              // 16
        'Total Productos',      // 17
        'Total pago',           // 18
        'Gastos de envío',      // 19
        'Precio unitario sin IVA', // 20
        'Precio unitario con IVA'  // 21
    ), ';');

    // Obtener pedidos completados de WooCommerce
    $args = array(
        'status' => 'completed',
        'limit' => -1
    );
    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        $customer_id = $order->get_customer_id();
        $customer = new WC_Customer($customer_id);
        $billing_address = $order->get_address('billing');
        $shipping_cost = $order->get_shipping_total();
        $total_paid = $order->get_total();
        $total_products = $order->get_subtotal() + $order->get_total_tax(); // Total productos con IVA

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $unit_price_excl_tax = $item->get_subtotal() / $item->get_quantity(); // Precio sin IVA
            $unit_price_incl_tax = $item->get_total() / $item->get_quantity(); // Precio con IVA
            $national_code = substr($product->get_sku(), 0, 6); // 6 primeras cifras del SKU
            $pvp_web = $unit_price_excl_tax; // Precio unitario sin IVA y sin descuento

            // Formatear la fecha del pedido
            $order_date = $order->get_date_created()->date('d/m/Y H:i:s');

            // Crear una línea de datos en el CSV en el orden correcto
            $data = array(
                $order->get_meta('observaciones_unycop', true), // 1 Referencia del pedido
                $order->get_id(),                               // 2 id del pedido
                $order_date,                                    // 3 Fecha
                $customer_id,                                   // 4 Id cliente web
                $billing_address['first_name'],                 // 5 Nombre cliente
                $billing_address['last_name'],                  // 6 Apellidos cliente
                $billing_address['email'],                      // 7 Email cliente
                $billing_address['phone'],                      // 8 Teléfono cliente
                $billing_address['dni'],                        // 9 DNI
                $billing_address['address_1'],                  // 10 dirección
                $billing_address['postcode'],                   // 11 CP
                $billing_address['city'],                       // 12 Ciudad
                $billing_address['state'],                      // 13 Provincia
                $national_code,                                 // 14 Código nacional del producto
                $item->get_quantity(),                          // 15 Cantidad
                $pvp_web,                                       // 16 PVP web
                $total_products,                                // 17 Total Productos (con IVA)
                $total_paid,                                    // 18 Total pago
                $shipping_cost,                                 // 19 Gastos de envío
                $unit_price_excl_tax,                           // 20 Precio unitario sin IVA
                $unit_price_incl_tax                            // 21 Precio unitario con IVA
            );

            // Escribir la línea de datos en el CSV
            fputcsv($handle, $data, ';');
        }
    }

    fclose($handle);
    // El archivo se almacena en la ubicación especificada
}

// Hook para generar orders.csv tras cada venta completada
add_action('woocommerce_order_status_completed', 'generate_orders_csv');

// =====================
// ADMINISTRACIÓN PLUGIN
// =====================

// Añadir página de opciones al menú de administración
add_action('admin_menu', 'unycop_connector_admin_menu');
function unycop_connector_admin_menu() {
    add_options_page(
        'Unycop Connector',
        'Unycop Connector',
        'manage_options',
        'unycop-connector-settings',
        'unycop_connector_settings_page'
    );
}

// Registrar opciones
add_action('admin_init', 'unycop_connector_register_settings');
function unycop_connector_register_settings() {
    register_setting('unycop_connector_options', 'unycop_csv_path');
    register_setting('unycop_connector_options', 'unycop_token');
    register_setting('unycop_connector_options', 'unycop_cron_frequency');
}

// Página de opciones
function unycop_connector_settings_page() {
    $upload_dir = wp_upload_dir();
    $default_path = $upload_dir['basedir'] . '/unycop/';
    ?>
    <div class="wrap">
        <h1>Configuración Unycop Connector</h1>
        <form method="post" action="options.php">
            <?php settings_fields('unycop_connector_options'); ?>
            <?php do_settings_sections('unycop_connector_options'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Ruta de los archivos CSV</th>
                    <td><input type="text" name="unycop_csv_path" value="<?php echo esc_attr(get_option('unycop_csv_path', $default_path)); ?>" size="60" /> <br><small>Déjalo vacío para usar la ruta por defecto de WordPress: <?php echo esc_html($default_path); ?></small></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Token de seguridad</th>
                    <td><input type="text" name="unycop_token" value="<?php echo esc_attr(get_option('unycop_token', 'unycop_secret_token')); ?>" size="40" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Frecuencia del cron de stock</th>
                    <td>
                        <select name="unycop_cron_frequency">
                            <?php $freq = get_option('unycop_cron_frequency', 'hourly'); ?>
                            <option value="hourly" <?php selected($freq, 'hourly'); ?>>Cada hora</option>
                            <option value="twicedaily" <?php selected($freq, 'twicedaily'); ?>>Dos veces al día</option>
                            <option value="daily" <?php selected($freq, 'daily'); ?>>Diario</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// =====================
// ENDPOINTS REST API PARA UNYCOP WIN
// =====================
add_action('rest_api_init', function () {
    register_rest_route('unycop/v1', '/orders', array(
        'methods' => 'GET',
        'callback' => 'unycop_api_get_orders_csv',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('unycop/v1', '/stock-update', array(
        'methods' => 'POST',
        'callback' => 'unycop_api_stock_update',
        'permission_callback' => '__return_true',
    ));
});

function unycop_api_check_token($request) {
    $token = isset($request['token']) ? $request['token'] : '';
    $valid_token = get_option('unycop_token', 'unycop_secret_token');
    return hash_equals($valid_token, $token);
}

// Endpoint para descargar orders.csv
function unycop_api_get_orders_csv($request) {
    // Log para debugging
    error_log('Unycop API: Petición GET a /orders recibida');
    
    if (!unycop_api_check_token($request)) {
        error_log('Unycop API: Token inválido - Token recibido: ' . (isset($request['token']) ? $request['token'] : 'NO_TOKEN'));
        return new WP_REST_Response(['error' => 'Token inválido'], 403);
    }
    
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/orders.csv';
    
    error_log('Unycop API: Ruta configurada: ' . $csv_path);
    error_log('Unycop API: Archivo completo: ' . $csv_file);
    error_log('Unycop API: ¿Existe el directorio? ' . (is_dir($csv_path) ? 'SÍ' : 'NO'));
    error_log('Unycop API: ¿Existe el archivo? ' . (file_exists($csv_file) ? 'SÍ' : 'NO'));
    
    if (!is_dir($csv_path)) {
        error_log('Unycop API: El directorio no existe: ' . $csv_path);
        return new WP_REST_Response(['error' => 'Directorio no encontrado: ' . $csv_path], 404);
    }
    
    if (!file_exists($csv_file)) {
        error_log('Unycop API: Archivo no encontrado en: ' . $csv_file);
        
        // Listar archivos en el directorio para debugging
        $files = scandir($csv_path);
        error_log('Unycop API: Archivos en el directorio: ' . print_r($files, true));
        
        return new WP_REST_Response(['error' => 'Archivo no encontrado: ' . $csv_file], 404);
    }
    
    error_log('Unycop API: Archivo encontrado, tamaño: ' . filesize($csv_file) . ' bytes');
    
    $csv_content = file_get_contents($csv_file);
    if ($csv_content === false) {
        error_log('Unycop API: Error al leer el archivo');
        return new WP_REST_Response(['error' => 'Error al leer el archivo'], 500);
    }
    
    error_log('Unycop API: Archivo leído correctamente, devolviendo contenido');
    
    return new WP_REST_Response($csv_content, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="orders.csv"'
    ]);
}

// Endpoint para forzar actualización de stock
function unycop_api_stock_update($request) {
    if (!unycop_api_check_token($request)) {
        return new WP_REST_Response(['error' => 'Token inválido'], 403);
    }
    sync_stock_from_csv();
    return new WP_REST_Response(['success' => true], 200);
}
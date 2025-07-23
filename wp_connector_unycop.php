<?php
/*
Plugin Name: WooCommerce Unycop Connector
Description: Sincroniza WooCommerce con Unycop Win importando el stock de productos desde un archivo CSV y exportando los pedidos completados a orders.csv. Incluye panel de configuración y endpoints REST API seguros para una integración eficiente en farmacia.
Version: 4.0
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

// Función para actualizar productos desde el CSV de stock (mejorada)
function sync_stock_from_csv() {
    // Auto-detección de ruta del archivo stocklocal.csv
    $csv_file = find_stocklocal_csv();
    
    if (!$csv_file) {
        error_log('UNYCOP SYNC: stocklocal.csv no encontrado');
        return 0;
    }

    $products_updated = 0;
    $products_created = 0;
    $errors = 0;
    
    // Log del inicio de sincronización
    error_log('UNYCOP SYNC: Iniciando sincronización automática desde ' . $csv_file);

    // Abre el CSV y actualiza los productos
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        // Leer encabezados
        fgetcsv($handle, 1000, ";");

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            try {
                // Validar que el registro tenga datos suficientes
                if (count($data) < 7) {
                    $errors++;
                    continue;
                }

                $cn = str_pad(trim($data[0]), 6, '0', STR_PAD_LEFT); // CN con ceros a la izquierda
                $stock = intval($data[1]); // Existencias
                $price_with_tax = floatval($data[2]); // PVP con IVA
                $iva = floatval($data[3]); // Tipo de IVA
                $prospecto = trim($data[4]); // Enlace al prospecto PDF
                $ean13 = trim($data[5]); // Código de barras
                $description = trim($data[6]); // Descripción del medicamento
                
                // Campos adicionales si existen
                $pc = isset($data[7]) ? floatval($data[7]) : 0; // Último precio de coste
                $family = isset($data[8]) ? trim($data[8]) : ''; // Familia
                $category = isset($data[9]) ? trim($data[9]) : ''; // Categoría
                $subcategory = isset($data[10]) ? trim($data[10]) : ''; // Subcategoría
                $lab = isset($data[11]) ? trim($data[11]) : ''; // Laboratorio
                $pvp2 = isset($data[12]) ? floatval($data[12]) : 0; // PVP2
                $locations = isset($data[13]) ? trim($data[13]) : ''; // Ubicaciones

                // Saltar si no hay datos esenciales
                if (empty($cn) || empty($description)) {
                    $errors++;
                    continue;
                }

                // Busca el producto por CN (SKU principal en WooCommerce)
                $product_id = wc_get_product_id_by_sku($cn);
                
                if ($product_id) {
                    // Actualizar producto existente
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        $errors++;
                        continue;
                    }

                    // Actualiza stock
                    wc_update_product_stock($product_id, $stock, 'set');
                    update_post_meta($product_id, '_manage_stock', 'yes');

                    // Actualiza precios
                    $price_without_tax = $iva > 0 ? $price_with_tax / (1 + ($iva / 100)) : $price_with_tax;
                    $product->set_regular_price($price_with_tax);
                    $product->set_price($price_without_tax);

                    // Actualizar metadatos
                    update_post_meta($product_id, '_prospecto_url', $prospecto);
                    update_post_meta($product_id, '_ean13', $ean13);
                    update_post_meta($product_id, '_cn_reference', $cn);
                    update_post_meta($product_id, '_precio_coste', $pc);
                    update_post_meta($product_id, '_familia', $family);
                    update_post_meta($product_id, '_categoria_unycop', $category);
                    update_post_meta($product_id, '_subcategoria', $subcategory);
                    update_post_meta($product_id, '_laboratorio', $lab);
                    update_post_meta($product_id, '_pvp2', $pvp2);
                    update_post_meta($product_id, '_ubicaciones', $locations);
                    
                    // Actualizar descripción si cambió
                    $product->set_description($description);

                    // Marcar como gestionado por Unycop
                    update_post_meta($product_id, '_unycop_managed', 'yes');
                    update_post_meta($product_id, '_unycop_last_sync', current_time('mysql'));

                    // Guardar producto actualizado
                    $product->save();
                    $products_updated++;
                    
                } else {
                    // Crear nuevo producto solo si está habilitado
                    $auto_create = get_option('unycop_auto_create_products', 'no');
                    if ($auto_create === 'yes') {
                        $new_product = new WC_Product();
                        $new_product->set_sku($cn);
                        $new_product->set_name($description);
                        $new_product->set_regular_price($price_with_tax);
                        $new_product->set_stock_quantity($stock);
                        $new_product->set_description($description);
                        $new_product->set_manage_stock(true);
                        $new_product->set_status('publish');
                        
                        // Calcular precio sin IVA
                        $price_without_tax = $iva > 0 ? $price_with_tax / (1 + ($iva / 100)) : $price_with_tax;
                        $new_product->set_price($price_without_tax);
                        
                        // Guardar producto
                        $new_product_id = $new_product->save();
                        
                        if ($new_product_id) {
                            // Guardar metadatos adicionales
                            update_post_meta($new_product_id, '_ean13', $ean13);
                            update_post_meta($new_product_id, '_cn_reference', $cn);
                            update_post_meta($new_product_id, '_prospecto_url', $prospecto);
                            update_post_meta($new_product_id, '_precio_coste', $pc);
                            update_post_meta($new_product_id, '_familia', $family);
                            update_post_meta($new_product_id, '_categoria_unycop', $category);
                            update_post_meta($new_product_id, '_subcategoria', $subcategory);
                            update_post_meta($new_product_id, '_laboratorio', $lab);
                            update_post_meta($new_product_id, '_pvp2', $pvp2);
                            update_post_meta($new_product_id, '_ubicaciones', $locations);
                            update_post_meta($new_product_id, '_unycop_managed', 'yes');
                            update_post_meta($new_product_id, '_unycop_last_sync', current_time('mysql'));
                            
                            $products_created++;
                        } else {
                            $errors++;
                        }
                    }
                }
                
            } catch (Exception $e) {
                error_log('UNYCOP SYNC ERROR: ' . $e->getMessage() . ' - Línea: ' . implode(';', $data));
                $errors++;
            }
        }
        fclose($handle);
    }
    
    // Log del resultado
    $total_processed = $products_updated + $products_created;
    error_log("UNYCOP SYNC COMPLETADO: {$products_updated} actualizados, {$products_created} creados, {$errors} errores");
    
    // Guardar estadísticas de la última sincronización
    update_option('unycop_last_sync_stats', array(
        'timestamp' => current_time('mysql'),
        'updated' => $products_updated,
        'created' => $products_created,
        'errors' => $errors,
        'total' => $total_processed
    ));
    
    return $total_processed;
}

// Función auxiliar para encontrar stocklocal.csv en múltiples ubicaciones
function find_stocklocal_csv() {
    $possible_paths = array();
    
    // 1. Ruta personalizada configurada
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $possible_paths[] = rtrim($custom_path, '/') . '/stocklocal.csv';
    }
    
    // 2. Directorio de uploads de WordPress
    $upload_dir = wp_upload_dir();
    $possible_paths[] = $upload_dir['basedir'] . '/unycop/stocklocal.csv';
    
    // 3. Directorio raíz de WordPress
    $possible_paths[] = ABSPATH . 'stocklocal.csv';
    
    // 4. Directorio del plugin
    $possible_paths[] = plugin_dir_path(__FILE__) . 'stocklocal.csv';
    
    // 5. Un nivel arriba del directorio de WordPress (para instalaciones en subdirectorio)
    $possible_paths[] = dirname(ABSPATH) . '/stocklocal.csv';
    
    // 6. Directorio específico de farmacia si existe
    $possible_paths[] = str_replace('/wp-content', '/farmacia/wp-content', $upload_dir['basedir']) . '/unycop/stocklocal.csv';
    $possible_paths[] = '/var/www/html/farmacia/wp-content/uploads/unycop/stocklocal.csv';
    
    // Buscar el archivo en todas las rutas posibles
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_readable($path)) {
            error_log('UNYCOP: stocklocal.csv encontrado en: ' . $path);
            return $path;
        }
    }
    
    return false;
}

// Función para generar el archivo orders.csv y guardarlo en local
function generate_orders_csv($order_id = null) {
    // Evitar generar múltiples veces en la misma petición
    static $is_generating = false;
    if ($is_generating) {
        return;
    }
    $is_generating = true;
    
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    
    // Asegurar que el directorio existe
    if (!is_dir($csv_path)) {
        wp_mkdir_p($csv_path);
    }
    
    $csv_file = $csv_path . '/orders.csv';
    
    // Log para debugging
    $log_message = 'UNYCOP ORDERS: Generando orders.csv';
    if ($order_id) {
        $log_message .= ' (triggered by order #' . $order_id . ')';
    }
    error_log($log_message);
    
    $handle = fopen($csv_file, 'w');

    // Encabezados del CSV exactamente como en la documentación
    fputcsv($handle, array(
        'Referencia_del_pedido',
        'id_del_pedido',
        'Fecha',
        'Id_cliente_web',
        'Nombre_cliente',
        'Apellidos_cliente',
        'Email_cliente',
        'Telefono_cliente',
        'DNI',
        'direccion',
        'CP',
        'Ciudad',
        'Provincia',
        'Codigo_nacional_del_producto',
        'Cantidad',
        'PVP_web',
        'Total_Productos',
        'Total_pago',
        'Gastos_de_envio',
        'Precio_unitario_sin_IVA',
        'Precio_unitario_con_IVA'
    ), ';', '"', '\\');

    // Obtener pedidos completados de WooCommerce
    $args = array(
        'status' => 'completed',
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'ASC'
    );
    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        $customer_id = $order->get_customer_id();
        $billing_address = $order->get_address('billing');
        $shipping_cost = $order->get_shipping_total();
        $total_paid = $order->get_total();
        
        // Calcular total de productos (subtotal + IVA)
        $subtotal = $order->get_subtotal();
        $total_tax = $order->get_total_tax();
        $total_products = $subtotal + $total_tax;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Obtener SKU del producto
            $sku = $product->get_sku();
            if (empty($sku)) continue;
            
            // Código nacional: primeros 6 dígitos del SKU
            $national_code = substr($sku, 0, 6);
            
            // Cantidad del item
            $quantity = $item->get_quantity();
            
            // Precios unitarios
            $unit_price_excl_tax = $item->get_subtotal() / $quantity; // Sin IVA
            $unit_price_incl_tax = $item->get_total() / $quantity;    // Con IVA
            
            // PVP web: precio unitario sin IVA
            $pvp_web = $unit_price_excl_tax;
            
            // Formatear la fecha del pedido (formato español)
            $order_date = $order->get_date_created()->date('d/m/Y H:i:s');
            
            // Referencia del pedido: usar meta o generar automáticamente
            $reference = $order->get_meta('observaciones_unycop', true);
            if (empty($reference)) {
                $reference = 'ORD-' . $order->get_id();
            }

            // Crear línea de datos en el orden exacto de la documentación
            $data = array(
                $reference,                                    // Referencia_del_pedido
                $order->get_id(),                              // id_del_pedido
                $order_date,                                   // Fecha
                $customer_id,                                  // Id_cliente_web
                $billing_address['first_name'] ?: 'Sin nombre', // Nombre_cliente
                $billing_address['last_name'] ?: 'Sin apellidos', // Apellidos_cliente
                $billing_address['email'] ?: 'sin@email.com',  // Email_cliente
                $billing_address['phone'] ?: 'Sin teléfono',   // Telefono_cliente
                $billing_address['dni'] ?: 'Sin DNI',          // DNI
                $billing_address['address_1'] ?: 'Sin dirección', // direccion
                $billing_address['postcode'] ?: 'Sin CP',      // CP
                $billing_address['city'] ?: 'Sin ciudad',      // Ciudad
                $billing_address['state'] ?: 'Sin provincia',  // Provincia
                $national_code,                                // Codigo_nacional_del_producto
                $quantity,                                     // Cantidad
                number_format($pvp_web, 2, '.', ''),           // PVP_web
                number_format($total_products, 2, '.', ''),    // Total_Productos
                number_format($total_paid, 2, '.', ''),        // Total_pago
                number_format($shipping_cost, 2, '.', ''),     // Gastos_de_envio
                number_format($unit_price_excl_tax, 2, '.', ''), // Precio_unitario_sin_IVA
                number_format($unit_price_incl_tax, 2, '.', '')  // Precio_unitario_con_IVA
            );

            // Escribir la línea de datos en el CSV
            fputcsv($handle, $data, ';', '"', '\\');
        }
    }

    fclose($handle);
    
    // Log para debugging
    error_log('UNYCOP ORDERS: Archivo orders.csv generado en: ' . $csv_file . ' con ' . $total_orders . ' pedidos');
    
    // Resetear la variable estática para permitir futuras generaciones
    $is_generating = false;
}

// Hook para generar orders.csv tras cada venta completada
add_action('woocommerce_order_status_completed', 'generate_orders_csv');

// Hook para generar orders.csv cuando se crea un pedido (cualquier estado)
add_action('woocommerce_new_order', 'generate_orders_csv');

// Hook para generar orders.csv cuando cambia el estado de un pedido
add_action('woocommerce_order_status_changed', 'generate_orders_csv');

// =====================
// HANDLERS AJAX PARA MEJORAS DE PEDIDOS
// =====================

// Handler para obtener estadísticas de pedidos
add_action('wp_ajax_unycop_get_orders_stats', 'unycop_get_orders_stats_handler');
function unycop_get_orders_stats_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_orders_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }
    
    $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : null;
    $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : null;
    
    $stats = unycop_get_orders_statistics($date_from, $date_to);
    wp_send_json_success($stats);
}

// Handler para generar CSV de pedidos con filtros
add_action('wp_ajax_unycop_generate_orders_csv', 'unycop_generate_orders_csv_handler');
function unycop_generate_orders_csv_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_orders_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }
    
    // Generar el archivo orders.csv con todos los pedidos completados
    generate_orders_csv();
    
    // Obtener información del archivo generado
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/orders.csv';
    
    if (file_exists($csv_file)) {
        $file_size = filesize($csv_file);
        $file_date = date('d/m/Y H:i:s', filemtime($csv_file));
        $lines = count(file($csv_file));
        $total_orders = $lines - 1; // Restar la línea de encabezados
        
        $result = array(
            'file_path' => $csv_file,
            'file_name' => basename($csv_file),
            'total_orders' => $total_orders,
            'total_items' => $total_orders, // Aproximación
            'file_size' => $file_size,
            'file_date' => $file_date,
            'date_from' => null,
            'date_to' => null,
            'status' => 'completed'
        );
        
        wp_send_json_success($result);
    } else {
        wp_send_json_error('No se pudo generar el archivo orders.csv');
    }
}

// Handler para obtener lista de pedidos
add_action('wp_ajax_unycop_get_orders_list', 'unycop_get_orders_list_handler');
function unycop_get_orders_list_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_orders_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    $filters = array(
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'completed',
        'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '',
        'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : ''
    );
    
    $result = unycop_get_orders_list($page, $per_page, $filters);
    wp_send_json_success($result);
}

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
    
    // Agregar subpágina de diagnóstico
    add_submenu_page(
        'options-general.php',
        'UNYCOP Diagnóstico',
        'UNYCOP Diagnóstico',
        'manage_options',
        'unycop-diagnostic',
        'unycop_diagnostic_page'
    );
    
    // Agregar subpágina de gestión de pedidos
    add_submenu_page(
        'options-general.php',
        'UNYCOP Gestión de Pedidos',
        '📦 UNYCOP Pedidos',
        'manage_options',
        'unycop-orders',
        'unycop_orders_management_page'
    );
}

// Registrar opciones
add_action('admin_init', 'unycop_connector_register_settings');
function unycop_connector_register_settings() {
    register_setting('unycop_connector_options', 'unycop_csv_path');
    register_setting('unycop_connector_options', 'unycop_token');
    register_setting('unycop_connector_options', 'unycop_cron_frequency');
    register_setting('unycop_connector_options', 'unycop_auto_create_products');
    register_setting('unycop_connector_options', 'unycop_ftp_url');
    register_setting('unycop_connector_options', 'unycop_barcode_api_key'); // Nuevo campo para la API de códigos de barras
}

// Añadir scripts y estilos para el botón de actualización
add_action('admin_enqueue_scripts', 'unycop_admin_scripts');
function unycop_admin_scripts($hook) {
    if ($hook != 'settings_page_unycop-connector-settings') {
        return;
    }
    
    wp_enqueue_script('jquery');
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            $("#update-stock-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Actualizando...");
                
                // Mostrar mensaje de estado
                $("#stock-update-status").html("<div class=\'notice notice-info inline\'><p>Actualizando stock desde stocklocal.csv...</p></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_update_stock_ajax",
                        nonce: "' . wp_create_nonce('unycop_update_stock_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "✅ <strong>Actualización completada</strong><br>";
                            statusHtml += "📦 Productos actualizados: " + data.products_updated + "<br>";
                            statusHtml += "🆕 Productos creados: " + data.products_created + "<br>";
                            statusHtml += "⏰ Fecha: " + data.timestamp;
                            statusHtml += "</p></div>";
                            
                            if (data.errors && data.errors.length > 0) {
                                statusHtml += "<div class=\'notice notice-warning inline\'><p>";
                                statusHtml += "⚠️ <strong>Errores encontrados:</strong><br>";
                                data.errors.forEach(function(error) {
                                    statusHtml += "• " + error + "<br>";
                                });
                                statusHtml += "</p></div>";
                            }
                            
                            $("#stock-update-status").html(statusHtml);
                            
                            // Mostrar detalles de productos
                            if (data.details && data.details.length > 0) {
                                showProductDetails(data.details);
                            }
                            
                            // Actualizar historial de logs
                            if (data.logs) {
                                updateLogsHistory(data.logs);
                            }
                        } else {
                            $("#stock-update-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#stock-update-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al actualizar el stock</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Función para mostrar detalles de productos
            function showProductDetails(details) {
                var detailsHtml = "<div class=\'card\' style=\'max-width: 800px; margin-top: 20px;\'>";
                detailsHtml += "<h3>📋 Detalles de Productos Procesados</h3>";
                detailsHtml += "<div style=\'max-height: 400px; overflow-y: auto;\'>";
                detailsHtml += "<table class=\'wp-list-table widefat fixed striped\'>";
                detailsHtml += "<thead><tr>";
                detailsHtml += "<th>Acción</th><th>SKU</th><th>Nombre</th><th>Stock</th><th>Precio</th><th>IVA</th><th>Lab</th>";
                detailsHtml += "</tr></thead><tbody>";
                
                details.forEach(function(product) {
                    var actionIcon = product.action === "updated" ? "🔄" : "🆕";
                    var actionText = product.action === "updated" ? "Actualizado" : "Creado";
                    
                    detailsHtml += "<tr>";
                    detailsHtml += "<td>" + actionIcon + " " + actionText + "</td>";
                    detailsHtml += "<td><code>" + product.sku + "</code></td>";
                    detailsHtml += "<td>" + product.name + "</td>";
                    
                    if (product.action === "updated") {
                        detailsHtml += "<td>" + product.old_stock + " → " + product.new_stock + "</td>";
                        detailsHtml += "<td>" + product.old_price + "€ → " + product.new_price + "€</td>";
                    } else {
                        detailsHtml += "<td>" + product.stock + "</td>";
                        detailsHtml += "<td>" + product.price + "€</td>";
                    }
                    
                    detailsHtml += "<td>" + product.iva + "</td>";
                    detailsHtml += "<td>" + product.lab + "</td>";
                    detailsHtml += "</tr>";
                });
                
                detailsHtml += "</tbody></table>";
                detailsHtml += "</div></div>";
                
                $("#product-details").html(detailsHtml);
            }
            
            // Función para actualizar historial de logs
            function updateLogsHistory(logs) {
                var logsHtml = "<div class=\'card\' style=\'max-width: 800px; margin-top: 20px;\'>";
                logsHtml += "<h3>📊 Historial de Actualizaciones</h3>";
                logsHtml += "<div style=\'max-height: 300px; overflow-y: auto;\'>";
                logsHtml += "<table class=\'wp-list-table widefat fixed striped\'>";
                logsHtml += "<thead><tr>";
                logsHtml += "<th>Fecha</th><th>Actualizados</th><th>Creados</th><th>Errores</th><th>Archivo</th>";
                logsHtml += "</tr></thead><tbody>";
                
                logs.reverse().forEach(function(log) {
                    var date = new Date(log.timestamp);
                    var formattedDate = date.toLocaleString("es-ES");
                    var errorCount = log.errors ? log.errors.length : 0;
                    var fileSize = (log.file_size / 1024).toFixed(1) + " KB";
                    
                    logsHtml += "<tr>";
                    logsHtml += "<td>" + formattedDate + "</td>";
                    logsHtml += "<td>" + log.products_updated + "</td>";
                    logsHtml += "<td>" + log.products_created + "</td>";
                    logsHtml += "<td>" + (errorCount > 0 ? "<span style=\'color: #d63638;\'>" + errorCount + "</span>" : "0") + "</td>";
                    logsHtml += "<td>" + fileSize + "</td>";
                    logsHtml += "</tr>";
                });
                
                logsHtml += "</tbody></table>";
                logsHtml += "</div></div>";
                
                $("#logs-history").html(logsHtml);
            }
            
            // Botón de actualización rápida
            $("#quick-update-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Actualizando...");
                
                // Mostrar mensaje de estado con spinner
                $("#stock-update-status").html("<div class=\'notice notice-info inline\'><p>⚡ <strong>Ejecutando actualización rápida de stock y precio...</strong></p><div style=\'text-align: center; margin: 10px 0;\'><div style=\'display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #0073aa; border-radius: 50%; animation: spin 1s linear infinite;\'></div><p style=\'margin: 5px 0; font-size: 12px; color: #666;\'>🔄 Procesando productos...</p></div></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_quick_update_ajax",
                        nonce: "' . wp_create_nonce('unycop_quick_update_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "⚡ <strong>Actualización rápida completada</strong><br>";
                            statusHtml += "📦 Productos con cambios: " + data.products_updated + "<br>";
                            statusHtml += "📈 Cambios de stock: " + data.stock_changes + "<br>";
                            statusHtml += "💰 Cambios de precio: " + data.price_changes + "<br>";
                            statusHtml += "📊 Productos procesados: " + data.total_processed + " de " + data.total_in_woo + " (WooCommerce)<br>";
                            statusHtml += "📋 Productos en CSV: " + data.total_in_csv + "<br>";
                            if (data.products_without_sku > 0) {
                                statusHtml += "⚠️ Productos sin SKU: " + data.products_without_sku + "<br>";
                            }
                            if (data.products_not_loaded > 0) {
                                statusHtml += "⚠️ Productos no cargados: " + data.products_not_loaded + "<br>";
                            }
                            statusHtml += "⏱️ Tiempo de ejecución: " + data.execution_time + "<br>";
                            statusHtml += "⏰ Fecha: " + data.timestamp;
                            statusHtml += "</p></div>";
                            
                            // Mostrar detalles de cambios si existen
                            if (data.changes_details && data.changes_details.length > 0) {
                                statusHtml += "<div class=\'card\' style=\'max-width: 800px; margin-top: 15px;\'>";
                                statusHtml += "<h3>📋 Detalles de Productos Modificados</h3>";
                                statusHtml += "<div style=\'max-height: 400px; overflow-y: auto;\'>";
                                statusHtml += "<table class=\'wp-list-table widefat fixed striped\'>";
                                statusHtml += "<thead><tr>";
                                statusHtml += "<th>SKU</th><th>Nombre</th><th>Stock</th><th>Precio</th><th>Cambios</th>";
                                statusHtml += "</tr></thead><tbody>";
                                
                                data.changes_details.forEach(function(change) {
                                    var changes = [];
                                    if (change.stock_changed) {
                                        var oldStock = change.old_stock !== null && change.old_stock !== \'\' ? change.old_stock : \'Sin stock\';
                                        changes.push(\'📈 Stock: \' + oldStock + \' → \' + change.new_stock);
                                    }
                                    if (change.price_changed) {
                                        var oldPrice = change.old_price && change.old_price !== \'\' ? change.old_price + \'€\' : \'Sin precio\';
                                        changes.push(\'💰 Precio: \' + oldPrice + \' → \' + change.new_price + \'€\');
                                    }
                                    
                                    statusHtml += "<tr>";
                                    statusHtml += "<td><strong>" + change.sku + "</strong></td>";
                                    statusHtml += "<td>" + change.name + "</td>";
                                    statusHtml += "<td>" + change.new_stock + "</td>";
                                    statusHtml += "<td>" + change.new_price + "€</td>";
                                    statusHtml += "<td>" + changes.join("<br>") + "</td>";
                                    statusHtml += "</tr>";
                                });
                                
                                statusHtml += "</tbody></table>";
                                statusHtml += "</div></div>";
                            }
                            
                            if (data.errors && data.errors.length > 0) {
                                statusHtml += "<div class=\'notice notice-warning inline\'><p>";
                                statusHtml += "⚠️ <strong>Errores encontrados:</strong><br>";
                                data.errors.forEach(function(error) {
                                    statusHtml += "• " + error + "<br>";
                                });
                                statusHtml += "</p></div>";
                            }
                            
                            $("#stock-update-status").html(statusHtml);
                        } else {
                            $("#stock-update-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#stock-update-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al ejecutar actualización rápida</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Carga de imágenes
            $("#load-images-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Cargando imágenes...");
                
                // Mostrar mensaje de estado
                $("#images-load-status").html("<div class=\'notice notice-info inline\'><p>Buscando y descargando imágenes para productos sin imagen...</p></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_load_images",
                        nonce: "' . wp_create_nonce('unycop_load_images_nonce') . '",
                        batch_size: 50,
                        offset: 0,
                        reset: true
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "✅ <strong>Carga de imágenes completada</strong><br>";
                            statusHtml += "🖼️ Imágenes cargadas: " + data.images_loaded + "<br>";
                            statusHtml += "⏰ Fecha: " + new Date().toLocaleString("es-ES");
                            statusHtml += "</p></div>";
                            
                            if (data.errors && data.errors.length > 0) {
                                statusHtml += "<div class=\'notice notice-warning inline\'><p>";
                                statusHtml += "⚠️ <strong>Errores encontrados:</strong><br>";
                                data.errors.forEach(function(error) {
                                    statusHtml += "• " + error + "<br>";
                                });
                                statusHtml += "</p></div>";
                            }
                            
                            $("#images-load-status").html(statusHtml);
                        } else {
                            $("#images-load-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#images-load-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al cargar imágenes</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Botón para ejecutar sincronización
            $("#trigger-sync-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Sincronizando...");
                
                // Mostrar mensaje de estado
                $("#sync-status").html("<div class=\'notice notice-info inline\'><p>🔄 Ejecutando sincronización automática...</p></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_trigger_sync",
                        nonce: "' . wp_create_nonce('unycop_trigger_sync_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var stats = data.stats;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "✅ <strong>Sincronización completada</strong><br>";
                            if (stats) {
                                statusHtml += "📦 Productos actualizados: " + stats.updated + "<br>";
                                statusHtml += "🆕 Productos creados: " + stats.created + "<br>";
                                statusHtml += "❌ Errores: " + stats.errors + "<br>";
                                var successRate = stats.total > 0 ? Math.round(((stats.updated + stats.created) / stats.total) * 100) : 0;
                                if (successRate >= 95) {
                                    statusHtml += "🎉 Tasa de éxito: " + successRate + "% - Excelente";
                                } else if (successRate >= 80) {
                                    statusHtml += "⚠️ Tasa de éxito: " + successRate + "% - Parcial";
                                } else {
                                    statusHtml += "❌ Tasa de éxito: " + successRate + "% - Problemas";
                                }
                            } else {
                                statusHtml += "📦 Productos procesados: " + data.products_processed;
                            }
                            statusHtml += "<br>⏰ Fecha: " + new Date().toLocaleString("es-ES");
                            statusHtml += "</p></div>";
                            
                            $("#sync-status").html(statusHtml);
                            
                            // Recargar la página después de 3 segundos para actualizar estadísticas
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            $("#sync-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#sync-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión durante la sincronización</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Botón para reactivar cron
            $("#reactivate-cron-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Reactivando...");
                
                // Mostrar mensaje de estado
                $("#sync-status").html("<div class=\'notice notice-info inline\'><p>⚡ Reactivando cron...</p></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_reactivate_cron",
                        nonce: "' . wp_create_nonce('unycop_reactivate_cron_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "✅ <strong>Cron reactivado correctamente</strong><br>";
                            statusHtml += "⏰ Próxima ejecución: " + data.next_execution;
                            statusHtml += "</p></div>";
                            
                            $("#sync-status").html(statusHtml);
                            
                            // Recargar la página después de 2 segundos para actualizar interfaz
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $("#sync-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#sync-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al reactivar cron</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Botón para generar orders.csv
            $("#generate-orders-csv-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Generando...");
                
                // Mostrar mensaje de estado
                $("#orders-status").html("<div class=\'notice notice-info inline\'><p>Generando archivo orders.csv...</p></div>");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_generate_orders_csv",
                        nonce: "' . wp_create_nonce('unycop_orders_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                            statusHtml += "✅ <strong>Archivo orders.csv generado correctamente</strong><br>";
                            statusHtml += "📄 Archivo: " + data.file_name + "<br>";
                            statusHtml += "📦 Pedidos procesados: " + data.total_orders + "<br>";
                            statusHtml += "🛍️ Artículos totales: " + data.total_items + "<br>";
                            statusHtml += "📅 Período: " + (data.date_from || "Todos") + " - " + (data.date_to || "Hoy") + "<br>";
                            statusHtml += "📁 Ubicación: " + data.file_path;
                            statusHtml += "</p></div>";
                            
                            $("#orders-status").html(statusHtml);
                            
                            // Recargar la página para mostrar la información actualizada
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $("#orders-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#orders-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al generar orders.csv</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
            
            // Botón para ver estadísticas de pedidos
            $("#view-orders-stats-btn").on("click", function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var originalText = $btn.text();
                
                // Deshabilitar botón y mostrar loading
                $btn.prop("disabled", true).text("Cargando...");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "unycop_get_orders_stats",
                        nonce: "' . wp_create_nonce('unycop_orders_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            var stats = response.data;
                            var statsHtml = "<div class=\'card\' style=\'max-width: 800px; margin-top: 20px;\'>";
                            statsHtml += "<h3>📊 Estadísticas de Pedidos</h3>";
                            statsHtml += "<div style=\'display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;\'>";
                            statsHtml += "<div style=\'background: #f9f9f9; padding: 15px; border-radius: 5px; text-align: center;\'>";
                            statsHtml += "<div style=\'font-size: 2em; font-weight: bold; color: #0073aa;\'>" + stats.total_orders + "</div>";
                            statsHtml += "<div style=\'color: #666;\'>Total Pedidos</div>";
                            statsHtml += "</div>";
                            statsHtml += "<div style=\'background: #f9f9f9; padding: 15px; border-radius: 5px; text-align: center;\'>";
                            statsHtml += "<div style=\'font-size: 2em; font-weight: bold; color: #0073aa;\'>" + stats.total_revenue.toFixed(2) + "€</div>";
                            statsHtml += "<div style=\'color: #666;\'>Ingresos Totales</div>";
                            statsHtml += "</div>";
                            statsHtml += "<div style=\'background: #f9f9f9; padding: 15px; border-radius: 5px; text-align: center;\'>";
                            statsHtml += "<div style=\'font-size: 2em; font-weight: bold; color: #0073aa;\'>" + stats.average_order_value.toFixed(2) + "€</div>";
                            statsHtml += "<div style=\'color: #666;\'>Ticket Promedio</div>";
                            statsHtml += "</div>";
                            statsHtml += "<div style=\'background: #f9f9f9; padding: 15px; border-radius: 5px; text-align: center;\'>";
                            statsHtml += "<div style=\'font-size: 2em; font-weight: bold; color: #0073aa;\'>" + stats.total_items + "</div>";
                            statsHtml += "<div style=\'color: #666;\'>Artículos Vendidos</div>";
                            statsHtml += "</div>";
                            statsHtml += "</div>";
                            
                            // Mostrar productos más vendidos
                            if (Object.keys(stats.top_products).length > 0) {
                                statsHtml += "<h4>🏆 Productos Más Vendidos</h4>";
                                statsHtml += "<table class=\'wp-list-table widefat fixed striped\'>";
                                statsHtml += "<thead><tr><th>Producto</th><th>Cantidad</th><th>Ingresos</th></tr></thead><tbody>";
                                
                                Object.values(stats.top_products).slice(0, 10).forEach(function(product) {
                                    statsHtml += "<tr>";
                                    statsHtml += "<td>" + product.name + "</td>";
                                    statsHtml += "<td>" + product.quantity + "</td>";
                                    statsHtml += "<td>" + product.revenue.toFixed(2) + "€</td>";
                                    statsHtml += "</tr>";
                                });
                                
                                statsHtml += "</tbody></table>";
                            }
                            
                            statsHtml += "</div>";
                            
                            $("#orders-stats").html(statsHtml).show();
                        } else {
                            $("#orders-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                        }
                    },
                    error: function() {
                        $("#orders-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión al cargar estadísticas</p></div>");
                    },
                    complete: function() {
                        // Restaurar botón
                        $btn.prop("disabled", false).text(originalText);
                    }
                });
            });
        });
    ');
}

// AJAX handler para actualizar stock
add_action('wp_ajax_unycop_update_stock_ajax', 'unycop_update_stock_ajax_handler');

// AJAX handler para actualización rápida
add_action('wp_ajax_unycop_quick_update_ajax', 'unycop_quick_update_ajax_handler');
add_action('wp_ajax_nopriv_unycop_quick_update_ajax', 'unycop_quick_update_ajax_handler');

// Handler AJAX de prueba simple
add_action('wp_ajax_unycop_test_ajax', 'unycop_test_ajax_handler');
add_action('wp_ajax_nopriv_unycop_test_ajax', 'unycop_test_ajax_handler');
function unycop_update_stock_ajax_handler() {
    // Verificar nonce
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_update_stock_nonce')) {
        wp_die('Error de seguridad');
    }
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes');
    }
    
    try {
        $result = sync_stock_from_csv_detailed();
        wp_send_json_success($result);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Handler AJAX para actualización rápida
function unycop_quick_update_ajax_handler() {
    // Log básico para verificar que el handler se ejecuta
    error_log('UNYCOP AJAX: ===== INICIO ACTUALIZACIÓN RÁPIDA =====');
    error_log('UNYCOP AJAX: Handler ejecutándose...');
    
    // Verificar POST data
    if (!isset($_POST['nonce'])) {
        error_log('UNYCOP AJAX ERROR: No se recibió nonce');
        wp_send_json_error('No se recibió nonce');
        return;
    }
    
    error_log('UNYCOP AJAX: POST data recibida: ' . print_r($_POST, true));
    
    // Verificar nonce
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_quick_update_nonce')) {
        error_log('UNYCOP AJAX ERROR: Nonce inválido');
        wp_send_json_error('Error de seguridad - nonce inválido');
        return;
    }
    
    error_log('UNYCOP AJAX: Nonce válido');
    
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        error_log('UNYCOP AJAX ERROR: Permisos insuficientes');
        wp_send_json_error('Permisos insuficientes');
        return;
    }
    
    error_log('UNYCOP AJAX: Permisos verificados');
    
    // Verificar que WooCommerce esté cargado
    if (!class_exists('WooCommerce')) {
        error_log('UNYCOP AJAX ERROR: Clase WooCommerce no está disponible');
        wp_send_json_error('WooCommerce no está disponible');
        return;
    }
    
    error_log('UNYCOP AJAX: WooCommerce clase disponible');
    
    // Verificar que WooCommerce esté disponible
    if (!function_exists('wc_get_product_id_by_sku')) {
        error_log('UNYCOP AJAX ERROR: WooCommerce no está disponible');
        wp_send_json_error('WooCommerce no está disponible');
        return;
    }
    
    error_log('UNYCOP AJAX: Funciones WooCommerce disponibles');
    
    // Verificar archivo CSV
    error_log('UNYCOP AJAX: Verificando archivo CSV...');
    $csv_file = find_stocklocal_csv();
    if (!$csv_file) {
        error_log('UNYCOP AJAX ERROR: Archivo CSV no encontrado');
        wp_send_json_error('Archivo CSV no encontrado');
        return;
    }
    error_log('UNYCOP AJAX: Archivo CSV encontrado: ' . $csv_file);
    
    // Verificar que el archivo existe y es legible
    if (!file_exists($csv_file)) {
        error_log('UNYCOP AJAX ERROR: Archivo CSV no existe: ' . $csv_file);
        wp_send_json_error('Archivo CSV no existe');
        return;
    }
    if (!is_readable($csv_file)) {
        error_log('UNYCOP AJAX ERROR: Archivo CSV no es legible: ' . $csv_file);
        wp_send_json_error('Archivo CSV no es legible');
        return;
    }
    error_log('UNYCOP AJAX: Archivo CSV existe y es legible');
    
    // Ejecutar la función de sincronización paso a paso
    error_log('UNYCOP AJAX: Iniciando verificación de sync_stock_and_price_only...');
    
    // Paso 1: Verificar que la función existe
    if (!function_exists('sync_stock_and_price_only')) {
        error_log('UNYCOP AJAX ERROR: Función sync_stock_and_price_only no existe');
        wp_send_json_error('Función sync_stock_and_price_only no existe');
        return;
    }
    error_log('UNYCOP AJAX: Función sync_stock_and_price_only existe');
    
    // Paso 2: Intentar ejecutar la función
    error_log('UNYCOP AJAX: Intentando ejecutar sync_stock_and_price_only...');
    $start_time = microtime(true);
    
    try {
        $result = sync_stock_and_price_only();
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        
        error_log('UNYCOP AJAX: Resultado de sync_stock_and_price_only: ' . print_r($result, true));
        error_log('UNYCOP AJAX: Tiempo de ejecución: ' . $execution_time . ' segundos');
        
        $response_data = array(
            'products_updated' => $result['products_updated'],
            'stock_changes' => $result['stock_changes'],
            'price_changes' => $result['price_changes'],
            'errors' => $result['errors'],
            'changes_details' => isset($result['changes_details']) ? $result['changes_details'] : array(),
            'total_processed' => isset($result['total_processed']) ? $result['total_processed'] : 0,
            'total_in_csv' => isset($result['total_in_csv']) ? $result['total_in_csv'] : 0,
            'total_in_woo' => isset($result['total_in_woo']) ? $result['total_in_woo'] : 0,
            'products_without_sku' => isset($result['products_without_sku']) ? $result['products_without_sku'] : 0,
            'products_not_loaded' => isset($result['products_not_loaded']) ? $result['products_not_loaded'] : 0,
            'execution_time' => $execution_time . ' segundos',
            'timestamp' => current_time('mysql'),
            'csv_file' => $csv_file,
            'step' => 'Sincronización completada'
        );
        
        error_log('UNYCOP AJAX: Enviando respuesta exitosa: ' . json_encode($response_data));
        wp_send_json_success($response_data);
        
    } catch (Exception $e) {
        error_log('UNYCOP AJAX ERROR: Excepción en sync_stock_and_price_only');
        error_log('UNYCOP AJAX ERROR: Mensaje: ' . $e->getMessage());
        error_log('UNYCOP AJAX ERROR: Archivo: ' . $e->getFile());
        error_log('UNYCOP AJAX ERROR: Línea: ' . $e->getLine());
        error_log('UNYCOP AJAX ERROR: Trace: ' . $e->getTraceAsString());
        wp_send_json_error('Error en sync_stock_and_price_only: ' . $e->getMessage());
    } catch (Error $e) {
        error_log('UNYCOP AJAX ERROR: Error fatal en sync_stock_and_price_only');
        error_log('UNYCOP AJAX ERROR: Mensaje: ' . $e->getMessage());
        error_log('UNYCOP AJAX ERROR: Archivo: ' . $e->getFile());
        error_log('UNYCOP AJAX ERROR: Línea: ' . $e->getLine());
        wp_send_json_error('Error fatal en sync_stock_and_price_only: ' . $e->getMessage());
    }
    
    // Código original comentado temporalmente - ACTIVANDO PASO A PASO
    /*
    try {
        // Verificar POST data
        if (!isset($_POST['nonce'])) {
            error_log('UNYCOP AJAX ERROR: No se recibió nonce');
            wp_send_json_error('No se recibió nonce');
            return;
        }
        
        error_log('UNYCOP AJAX: POST data recibida: ' . print_r($_POST, true));
        
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'unycop_quick_update_nonce')) {
            error_log('UNYCOP AJAX ERROR: Nonce inválido');
            wp_send_json_error('Error de seguridad - nonce inválido');
            return;
        }
        
        error_log('UNYCOP AJAX: Nonce válido');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            error_log('UNYCOP AJAX ERROR: Permisos insuficientes');
            wp_send_json_error('Permisos insuficientes');
            return;
        }
        
        error_log('UNYCOP AJAX: Permisos verificados');
        
        // Verificar que WooCommerce esté cargado
        if (!class_exists('WooCommerce')) {
            error_log('UNYCOP AJAX ERROR: Clase WooCommerce no está disponible');
            wp_send_json_error('WooCommerce no está disponible');
            return;
        }
        
        error_log('UNYCOP AJAX: WooCommerce clase disponible');
        
        // Verificar que WooCommerce esté disponible
        if (!function_exists('wc_get_product_id_by_sku')) {
            error_log('UNYCOP AJAX ERROR: WooCommerce no está disponible');
            wp_send_json_error('WooCommerce no está disponible');
            return;
        }
        
        error_log('UNYCOP AJAX: Funciones WooCommerce disponibles');
        
        // Ejecutar la función de sincronización paso a paso
        error_log('UNYCOP AJAX: Iniciando verificación paso a paso...');
        
        // Paso 1: Verificar archivo CSV
        error_log('UNYCOP AJAX: Paso 1 - Verificando archivo CSV...');
        $csv_file = find_stocklocal_csv();
        if (!$csv_file) {
            error_log('UNYCOP AJAX ERROR: Archivo CSV no encontrado');
            wp_send_json_error('Archivo CSV no encontrado');
            return;
        }
        error_log('UNYCOP AJAX: Archivo CSV encontrado: ' . $csv_file);
        
        // Paso 2: Verificar que el archivo existe y es legible
        if (!file_exists($csv_file)) {
            error_log('UNYCOP AJAX ERROR: Archivo CSV no existe: ' . $csv_file);
            wp_send_json_error('Archivo CSV no existe');
            return;
        }
        if (!is_readable($csv_file)) {
            error_log('UNYCOP AJAX ERROR: Archivo CSV no es legible: ' . $csv_file);
            wp_send_json_error('Archivo CSV no es legible');
            return;
        }
        error_log('UNYCOP AJAX: Archivo CSV existe y es legible');
        
        // Paso 3: Intentar abrir el archivo
        error_log('UNYCOP AJAX: Paso 3 - Intentando abrir archivo CSV...');
        $handle = fopen($csv_file, "r");
        if ($handle === FALSE) {
            error_log('UNYCOP AJAX ERROR: No se pudo abrir el archivo CSV');
            wp_send_json_error('No se pudo abrir el archivo CSV');
            return;
        }
        error_log('UNYCOP AJAX: Archivo CSV abierto correctamente');
        
        // Paso 4: Leer encabezados
        error_log('UNYCOP AJAX: Paso 4 - Leyendo encabezados...');
        $headers = fgetcsv($handle, 1000, ";");
        if ($headers === FALSE) {
            error_log('UNYCOP AJAX ERROR: No se pudieron leer los encabezados');
            fclose($handle);
            wp_send_json_error('No se pudieron leer los encabezados del CSV');
            return;
        }
        error_log('UNYCOP AJAX: Encabezados leídos: ' . print_r($headers, true));
        
        // Paso 5: Leer primera línea de datos
        error_log('UNYCOP AJAX: Paso 5 - Leyendo primera línea de datos...');
        $first_data = fgetcsv($handle, 1000, ";");
        if ($first_data === FALSE) {
            error_log('UNYCOP AJAX ERROR: No se pudo leer la primera línea de datos');
            fclose($handle);
            wp_send_json_error('No se pudo leer la primera línea de datos');
            return;
        }
        error_log('UNYCOP AJAX: Primera línea de datos: ' . print_r($first_data, true));
        
        fclose($handle);
        
        // Paso 6: Ejecutar función completa (con manejo de errores)
        error_log('UNYCOP AJAX: Paso 6 - Ejecutando sync_stock_and_price_only...');
        $start_time = microtime(true);
        
        try {
            $result = sync_stock_and_price_only();
            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 2);
            
            error_log('UNYCOP AJAX: Resultado de sync_stock_and_price_only: ' . print_r($result, true));
            error_log('UNYCOP AJAX: Tiempo de ejecución: ' . $execution_time . ' segundos');
            
            $response_data = array(
                'products_updated' => $result['products_updated'],
                'stock_changes' => $result['stock_changes'],
                'price_changes' => $result['price_changes'],
                'errors' => $result['errors'],
                'execution_time' => $execution_time . ' segundos',
                'timestamp' => current_time('mysql'),
                'csv_file' => $csv_file,
                'headers' => $headers,
                'first_data_sample' => array_slice($first_data, 0, 3) // Solo primeros 3 campos
            );
            
            error_log('UNYCOP AJAX: Enviando respuesta exitosa: ' . json_encode($response_data));
            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            error_log('UNYCOP AJAX ERROR: Excepción en sync_stock_and_price_only');
            error_log('UNYCOP AJAX ERROR: Mensaje: ' . $e->getMessage());
            error_log('UNYCOP AJAX ERROR: Archivo: ' . $e->getFile());
            error_log('UNYCOP AJAX ERROR: Línea: ' . $e->getLine());
                    wp_send_json_error('Error en sync_stock_and_price_only: ' . $e->getMessage());
    }
    
    } catch (Exception $e) {
        error_log('UNYCOP AJAX ERROR: Excepción capturada');
        error_log('UNYCOP AJAX ERROR: Mensaje: ' . $e->getMessage());
        error_log('UNYCOP AJAX ERROR: Archivo: ' . $e->getFile());
        error_log('UNYCOP AJAX ERROR: Línea: ' . $e->getLine());
        error_log('UNYCOP AJAX ERROR: Trace: ' . $e->getTraceAsString());
        
        wp_send_json_error('Error en actualización rápida: ' . $e->getMessage());
    }
    */
}

// Handler AJAX de prueba simple
function unycop_test_ajax_handler() {
    error_log('UNYCOP TEST AJAX: Handler de prueba ejecutándose...');
    
    try {
        // Respuesta simple
        $response_data = array(
            'message' => 'Handler de prueba funcionando correctamente',
            'timestamp' => current_time('mysql'),
            'php_version' => phpversion(),
            'wordpress_version' => get_bloginfo('version')
        );
        
        error_log('UNYCOP TEST AJAX: Enviando respuesta de prueba');
        wp_send_json_success($response_data);
        
    } catch (Exception $e) {
        error_log('UNYCOP TEST AJAX ERROR: ' . $e->getMessage());
        wp_send_json_error('Error en prueba: ' . $e->getMessage());
    }
}

// Función detallada para actualizar productos con logs
function sync_stock_from_csv_detailed() {
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    
    if (!file_exists($csv_file)) {
        return array(
            'products_updated' => 0,
            'products_created' => 0,
            'errors' => array('Archivo stocklocal.csv no encontrado en: ' . $csv_file),
            'details' => array(),
            'timestamp' => current_time('mysql')
        );
    }

    $products_updated = 0;
    $products_created = 0;
    $errors = array();
    $details = array();

    // Abre el CSV y actualiza los productos
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        // Leer encabezados
        $headers = fgetcsv($handle, 1000, ";");
        $row_number = 1;

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            $row_number++;
            
            try {
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
                    // Actualizar el producto existente
                    $product = wc_get_product($product_id);
                    $old_stock = $product->get_stock_quantity();
                    $old_price = $product->get_regular_price();

                    // Actualiza stock
                    wc_update_product_stock($product_id, $stock, 'set');
                    update_post_meta($product_id, '_manage_stock', 'yes');

                    // Actualiza precios
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $product->set_regular_price($price_with_tax); // Precio con IVA
                    $product->set_price($price_without_tax); // Precio sin IVA

                                    // Actualizar otros campos si es necesario
                update_post_meta($product_id, '_prospecto_url', $prospecto);
                update_post_meta($product_id, '_ean13', $ean13); // Guardar EAN13 como metadato
                update_post_meta($product_id, '_cn_reference', $cn); // Guardar CN como metadato
                $product->set_description($description);

                    // Guardar producto actualizado
                    $product->save();
                    $products_updated++;

                    // Registrar detalles del producto actualizado
                    $details[] = array(
                        'action' => 'updated',
                        'product_id' => $product_id,
                        'sku' => $cn,
                        'name' => $description,
                        'old_stock' => $old_stock,
                        'new_stock' => $stock,
                        'old_price' => $old_price,
                        'new_price' => $price_with_tax,
                        'iva' => $iva . '%',
                        'lab' => $lab,
                        'category' => $category
                    );
                } else {
                    // Crear producto nuevo
                    $new_product = new WC_Product();
                    $new_product->set_sku($cn);
                    $new_product->set_name($description);
                    $new_product->set_regular_price($price_with_tax);
                    $new_product->set_stock_quantity($stock);
                    $new_product->set_description($description);
                    $new_product->set_manage_stock(true);
                    $new_product->set_status('publish');
                    
                    // Establecer precio sin IVA
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $new_product->set_price($price_without_tax);
                    
                    $new_product_id = $new_product->save();
                    $products_created++;

                    // Registrar detalles del producto creado
                    $details[] = array(
                        'action' => 'created',
                        'product_id' => $new_product_id,
                        'sku' => $cn,
                        'name' => $description,
                        'stock' => $stock,
                        'price' => $price_with_tax,
                        'iva' => $iva . '%',
                        'lab' => $lab,
                        'category' => $category
                    );
                }
            } catch (Exception $e) {
                $errors[] = "Fila $row_number: " . $e->getMessage();
            }
        }
        fclose($handle);
    }
    
    // Guardar log de la actualización
    $log_entry = array(
        'timestamp' => current_time('mysql'),
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'errors' => $errors,
        'file_size' => filesize($csv_file),
        'file_date' => date('Y-m-d H:i:s', filemtime($csv_file))
    );
    
    // Guardar en opciones de WordPress (mantener solo los últimos 10 logs)
    $logs = get_option('unycop_update_logs', array());
    $logs[] = $log_entry;
    if (count($logs) > 10) {
        $logs = array_slice($logs, -10);
    }
    update_option('unycop_update_logs', $logs);
    
    return array(
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'errors' => $errors,
        'details' => $details,
        'timestamp' => current_time('mysql'),
        'logs' => $logs
    );
}

// Función para buscar y descargar imagen por EAN13
function unycop_find_and_download_image($ean13, $product_id) {
    if (empty($ean13)) {
        return false;
    }
    
    // Verificar si ya tiene imagen
    $product = wc_get_product($product_id);
    if ($product && $product->get_image_id()) {
        return true; // Ya tiene imagen
    }
    
    // URLs comunes donde buscar imágenes de productos farmacéuticos
    $image_urls = array();
    
    // 1. Unycop FTP (si está configurado) - FUENTE PRINCIPAL
    $ftp_url = get_option('unycop_ftp_url', '');
    if (!empty($ftp_url)) {
        $image_urls[] = rtrim($ftp_url, '/') . '/images/' . $ean13 . '.jpg';
        $image_urls[] = rtrim($ftp_url, '/') . '/images/' . $ean13 . '.png';
        $image_urls[] = rtrim($ftp_url, '/') . '/productos/' . $ean13 . '.jpg';
        $image_urls[] = rtrim($ftp_url, '/') . '/productos/' . $ean13 . '.png';
        $image_urls[] = rtrim($ftp_url, '/') . '/fotos/' . $ean13 . '.jpg';
        $image_urls[] = rtrim($ftp_url, '/') . '/fotos/' . $ean13 . '.png';
    }
    
    // 2. APIs específicas de productos farmacéuticos
    // OpenFoodFacts (algunos productos farmacéuticos están aquí)
    $image_urls[] = 'https://world.openfoodfacts.org/api/v0/product/' . $ean13 . '.json';
    
    // APIs adicionales gratuitas para productos farmacéuticos
    // Vademecum API (español)
    $image_urls[] = 'https://www.vademecum.es/api/product/' . $ean13 . '.json';
    
    // CIMA API (AEMPS - España)
    $image_urls[] = 'https://cima.aemps.es/cima/rest/medicamentos?nregistro=' . $ean13;
    
    // 3. Generador de códigos de barras como fallback
    $image_urls[] = 'https://barcode.tec-it.com/barcode.ashx?data=' . $ean13 . '&code=EAN13&multiplebarcodes=false&translate-esc=false&unit=Fit&dpi=96&imagetype=Png&rotation=0&color=%23000000&bgcolor=%23ffffff&codepage=Default&validate=false&qunit=Mm&quiet=0&hidehrt=False';
    
    // 4. APIs adicionales (requieren configuración)
    $barcode_api_key = get_option('unycop_barcode_api_key', '');
    if (!empty($barcode_api_key)) {
        $image_urls[] = 'https://api.barcodelookup.com/v3/products?barcode=' . $ean13 . '&key=' . $barcode_api_key;
    }
    
    foreach ($image_urls as $image_url) {
        if (empty($image_url)) continue;
        
        $upload_dir = wp_upload_dir();
        $image_path = $upload_dir['path'] . '/product-' . $ean13 . '.jpg';
        
        // Intentar descargar la imagen o datos JSON
        $response = wp_remote_get($image_url, array(
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'WooCommerce Unycop Connector/1.0'
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $content = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            
            // Si es JSON, intentar extraer URL de imagen
            if (strpos($content_type, 'application/json') !== false) {
                $json_data = json_decode($content, true);
                if ($json_data) {
                    // Buscar URL de imagen en diferentes formatos de API
                    $image_url_from_api = null;
                    
                    // OpenFoodFacts API
                    if (isset($json_data['product']['image_front_url'])) {
                        $image_url_from_api = $json_data['product']['image_front_url'];
                    } elseif (isset($json_data['product']['image_url'])) {
                        $image_url_from_api = $json_data['product']['image_url'];
                    } elseif (isset($json_data['product']['image_small_url'])) {
                        $image_url_from_api = $json_data['product']['image_small_url'];
                    }
                    
                    // BarcodeLookup API
                    if (isset($json_data['products'][0]['images'][0])) {
                        $image_url_from_api = $json_data['products'][0]['images'][0];
                    }
                    
                    if ($image_url_from_api) {
                        // Descargar la imagen desde la URL extraída
                        $image_response = wp_remote_get($image_url_from_api, array(
                            'timeout' => 30,
                            'sslverify' => false,
                            'user-agent' => 'WooCommerce Unycop Connector/1.0'
                        ));
                        
                        if (!is_wp_error($image_response) && wp_remote_retrieve_response_code($image_response) === 200) {
                            $image_data = wp_remote_retrieve_body($image_response);
                            
                            // Verificar que es realmente una imagen
                            $image_content_type = wp_remote_retrieve_header($image_response, 'content-type');
                            if (strpos($image_content_type, 'image/') === 0) {
                                // Guardar la imagen
                                if (file_put_contents($image_path, $image_data)) {
                                    // Asociar la imagen al producto
                                    $attachment_id = unycop_attach_image_to_product($image_path, $product_id, $ean13);
                                    if ($attachment_id) {
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // Es una imagen directa - verificar que es realmente una imagen
                if (strpos($content_type, 'image/') === 0) {
                    if (file_put_contents($image_path, $content)) {
                        // Asociar la imagen al producto
                        $attachment_id = unycop_attach_image_to_product($image_path, $product_id, $ean13);
                        if ($attachment_id) {
                            return true;
                        }
                    }
                }
            }
        }
    }
    
    return false;
}

// Función para asociar imagen al producto
function unycop_attach_image_to_product($image_path, $product_id, $ean13) {
    if (!file_exists($image_path)) {
        return false;
    }
    
    // Verificar tipo de archivo
    $file_type = wp_check_filetype(basename($image_path), null);
    if (!$file_type['type']) {
        return false;
    }
    
    // Preparar datos del archivo
    $upload_dir = wp_upload_dir();
    $attachment = array(
        'post_mime_type' => $file_type['type'],
        'post_title' => 'Producto ' . $ean13,
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    // Insertar el archivo en la biblioteca de medios
    $attachment_id = wp_insert_attachment($attachment, $image_path, $product_id);
    
    if (!is_wp_error($attachment_id)) {
        // Generar tamaños de imagen
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $image_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        // Asociar al producto
        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_image_id($attachment_id);
            $product->save();
        }
        
        return $attachment_id;
    }
    
    return false;
}

// Función para cargar imágenes en lote
function unycop_batch_load_images($batch_size = 50) {
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $batch_size,
        'meta_query' => array(
            array(
                'key' => '_ean13',
                'compare' => 'EXISTS'
            ),
            array(
                'key' => '_product_image_loaded',
                'compare' => 'NOT EXISTS'
            )
        )
    );
    
    $products = get_posts($args);
    $images_loaded = 0;
    $errors = array();
    
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        if (!$product) continue;
        
        $ean13 = get_post_meta($product_post->ID, '_ean13', true);
        if (empty($ean13)) continue;
        
        // Intentar cargar imagen
        if (unycop_find_and_download_image($ean13, $product_post->ID)) {
            update_post_meta($product_post->ID, '_product_image_loaded', 'yes');
            $images_loaded++;
        } else {
            $errors[] = 'No se pudo cargar imagen para EAN13: ' . $ean13;
        }
    }
    
    return array(
        'images_loaded' => $images_loaded,
        'errors' => $errors
    );
}

// Página de opciones
function unycop_connector_settings_page() {
    $upload_dir = wp_upload_dir();
    $default_path = $upload_dir['basedir'] . '/unycop/';
    ?>
    <div class="wrap">
        <h1>Configuración Unycop Connector</h1>
        
        <!-- Sección de migración inicial -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>🔄 Migración Inicial desde stocklocal.csv</h2>
            <p><strong>⚠️ PRIMERA VEZ:</strong> Usa esta función para corregir el mapeo de campos CN/EAN13 usando el archivo stocklocal.csv</p>
            
            <div class="notice notice-warning inline">
                <p><strong>Esta función hará lo siguiente:</strong></p>
                <ul>
                    <li>📋 <strong>Leerá stocklocal.csv</strong> y corregirá el mapeo de campos</li>
                    <li>🔄 <strong>CN (columna 1)</strong> → SKU y metadato _cn_reference</li>
                    <li>📱 <strong>EAN13 (columna 6)</strong> → metadato _ean13</li>
                    <li>💰 <strong>Actualizará</strong> stock, precios y descripciones</li>
                    <li>✅ <strong>Dejará todo</strong> preparado para futuras sincronizaciones</li>
                </ul>
            </div>
            
            <button id="migrate-initial-btn" class="button button-primary" style="margin-bottom: 10px;">
                🔄 Ejecutar Migración Inicial
            </button>
            
            <div id="migrate-initial-status"></div>
        </div>

        <!-- Sección de copia de seguridad -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>💾 Gestión de Copias de Seguridad</h2>
            <p>Descarga y restaura copias de seguridad de productos. <strong>Recomendado hacer backup antes de cada cambio importante.</strong></p>
            
            <!-- Backup -->
            <div style="margin-bottom: 15px;">
                <h4>📥 Crear Backup</h4>
                <button id="backup-products-btn" class="button button-secondary" style="margin-bottom: 10px;">
                    💾 Descargar Backup de Productos
                </button>
                <div id="backup-status"></div>
            </div>
            
            <!-- Restaurar -->
            <div style="margin-bottom: 15px;">
                <h4>📤 Restaurar desde Backup</h4>
                <p><strong>⚠️ CUIDADO:</strong> Esta función restaurará productos desde un archivo CSV de backup. Sobrescribirá los datos actuales.</p>
                
                <input type="file" id="restore-file-input" accept=".csv" style="margin-bottom: 10px;">
                <br>
                <button id="restore-products-btn" class="button button-primary" style="margin-bottom: 10px;" disabled>
                    📤 Restaurar Productos desde CSV
                </button>
                <div id="restore-status"></div>
            </div>
        </div>

        <!-- Sección de actualización manual de stock -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>Actualización Manual de Stock</h2>
            <p>Haz clic en el botón para actualizar manualmente el stock de productos desde el archivo <code>stocklocal.csv</code>.</p>
            
            <button id="update-stock-btn" class="button button-primary" style="margin-bottom: 10px;">
                🔄 Actualizar Stock Ahora
            </button>
            
            <button id="quick-update-btn" class="button button-secondary" style="margin-bottom: 10px; margin-left: 10px;">
                ⚡ Actualización Rápida (Solo Stock/Precio)
            </button>
            
            <div id="stock-update-status"></div>
            
            <!-- Sección para mostrar detalles de productos procesados -->
            <div id="product-details"></div>
            
            <!-- Sección para mostrar historial de logs -->
            <div id="logs-history">
                <?php
                // Mostrar logs existentes al cargar la página
                $existing_logs = get_option('unycop_update_logs', array());
                if (!empty($existing_logs)) {
                    echo '<div class="card" style="max-width: 800px; margin-top: 20px;">';
                    echo '<h3>📊 Historial de Actualizaciones</h3>';
                    echo '<div style="max-height: 300px; overflow-y: auto;">';
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr>';
                    echo '<th>Fecha</th><th>Actualizados</th><th>Creados</th><th>Errores</th><th>Archivo</th>';
                    echo '</tr></thead><tbody>';
                    
                    $logs = array_reverse($existing_logs);
                    foreach ($logs as $log) {
                        $date = date('d/m/Y H:i:s', strtotime($log['timestamp']));
                        $error_count = isset($log['errors']) ? count($log['errors']) : 0;
                        $file_size = isset($log['file_size']) ? number_format($log['file_size'] / 1024, 1) . ' KB' : 'N/A';
                        
                        echo '<tr>';
                        echo '<td>' . esc_html($date) . '</td>';
                        echo '<td>' . esc_html($log['products_updated']) . '</td>';
                        echo '<td>' . esc_html($log['products_created']) . '</td>';
                        echo '<td>' . ($error_count > 0 ? '<span style="color: #d63638;">' . $error_count . '</span>' : '0') . '</td>';
                        echo '<td>' . esc_html($file_size) . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    echo '</div></div>';
                }
                ?>
            </div>
            
            <hr style="margin: 20px 0;">
            
            <h3>Información del archivo stocklocal.csv</h3>
            <?php
            $custom_path = get_option('unycop_csv_path', '');
            if ($custom_path) {
                $csv_path = rtrim($custom_path, '/');
            } else {
                $csv_path = $upload_dir['basedir'] . '/unycop';
            }
            $csv_file = $csv_path . '/stocklocal.csv';
            
            // Información de debug
            echo '<div class="notice notice-info inline" style="margin-bottom: 10px;"><p>';
            echo '<strong>🔍 Información de Debug:</strong><br>';
            echo '<strong>WordPress upload dir:</strong> ' . esc_html($upload_dir['basedir']) . '<br>';
            echo '<strong>Ruta configurada:</strong> ' . esc_html($custom_path ?: 'Por defecto') . '<br>';
            echo '<strong>Ruta calculada:</strong> ' . esc_html($csv_path) . '<br>';
            echo '<strong>Archivo completo:</strong> ' . esc_html($csv_file);
            echo '</p></div>';
            
            // Verificar si el archivo existe y mostrar información
            if (file_exists($csv_file)) {
                $file_size = filesize($csv_file);
                $file_date = date('d/m/Y H:i:s', filemtime($csv_file));
                echo '<div class="notice notice-info inline"><p>';
                echo '<strong>Archivo encontrado:</strong> ' . esc_html($csv_file) . '<br>';
                echo '<strong>Tamaño:</strong> ' . number_format($file_size) . ' bytes<br>';
                echo '<strong>Última modificación:</strong> ' . $file_date;
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-warning inline"><p>';
                echo '<strong>Archivo no encontrado:</strong> ' . esc_html($csv_file) . '<br>';
                echo 'Asegúrate de que el archivo stocklocal.csv existe en la ruta configurada.';
                echo '</p></div>';
            }
            ?>
        </div>
        
        <!-- Sección de gestión de pedidos -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>📦 Gestión de Pedidos</h2>
            <p>El archivo <code>orders.csv</code> se genera automáticamente cada vez que se crea o completa un pedido, y también manualmente desde este panel.</p>
            
            <button id="generate-orders-csv-btn" class="button button-primary" style="margin-bottom: 10px;">
                📄 Generar orders.csv (Manual)
            </button>
            
            <button id="view-orders-stats-btn" class="button button-secondary" style="margin-bottom: 10px; margin-left: 10px;">
                📊 Ver Estadísticas de Pedidos
            </button>
            
            <div id="orders-status"></div>
            <div id="orders-stats" style="display: none;"></div>
            
            <!-- Información sobre generación automática -->
            <div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin: 15px 0;">
                <h4 style="margin-top: 0;">🔄 Generación Automática</h4>
                <p style="margin-bottom: 0;">El archivo <code>orders.csv</code> se actualiza automáticamente en estos casos:</p>
                <ul style="margin: 10px 0 0 20px;">
                    <li>✅ Cuando se crea un nuevo pedido</li>
                    <li>✅ Cuando se completa un pedido</li>
                    <li>✅ Cuando cambia el estado de un pedido</li>
                </ul>
            </div>
            
            <!-- Información del archivo orders.csv -->
            <hr style="margin: 20px 0;">
            <h3>Información del archivo orders.csv</h3>
            <?php
            $orders_csv_file = $csv_path . '/orders.csv';
            
            if (file_exists($orders_csv_file)) {
                $file_size = filesize($orders_csv_file);
                $file_date = date('d/m/Y H:i:s', filemtime($orders_csv_file));
                $lines = count(file($orders_csv_file));
                
                echo '<div class="notice notice-info inline"><p>';
                echo '<strong>📄 Archivo orders.csv encontrado:</strong><br>';
                echo '<strong>Ruta:</strong> ' . esc_html($orders_csv_file) . '<br>';
                echo '<strong>Tamaño:</strong> ' . number_format($file_size) . ' bytes<br>';
                echo '<strong>Líneas:</strong> ' . $lines . ' (incluyendo encabezados)<br>';
                echo '<strong>Última modificación:</strong> ' . $file_date . '<br>';
                echo '<strong>📋 Pedidos en el archivo:</strong> ' . ($lines - 1) . ' pedidos';
                echo '</p></div>';
                
                // Mostrar vista previa del archivo
                if ($lines > 1) {
                    echo '<div class="card" style="max-width: 800px; margin-top: 15px;">';
                    echo '<h4>👁️ Vista previa del archivo orders.csv</h4>';
                    echo '<div style="max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; font-family: monospace; font-size: 12px;">';
                    
                    $handle = fopen($orders_csv_file, 'r');
                    $line_count = 0;
                    while (($line = fgets($handle)) !== false && $line_count < 5) {
                        echo htmlspecialchars($line) . '<br>';
                        $line_count++;
                    }
                    fclose($handle);
                    
                    if ($lines > 5) {
                        echo '<em>... y ' . ($lines - 5) . ' líneas más</em>';
                    }
                    echo '</div></div>';
                }
            } else {
                echo '<div class="notice notice-warning inline"><p>';
                echo '<strong>📄 Archivo orders.csv no encontrado:</strong><br>';
                echo '<strong>Ruta esperada:</strong> ' . esc_html($orders_csv_file) . '<br>';
                echo 'Haz clic en "Generar orders.csv" para crear el archivo con los pedidos completados.';
                echo '</p></div>';
            }
            ?>
        </div>
        
        <!-- Sección de información sobre referencias -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>📋 Información sobre Referencias y EAN13</h2>
            <div class="notice notice-info inline">
                <p><strong>Referencia (CN - Código Nacional):</strong></p>
                <ul>
                    <li>Es el código interno de Unycop (columna 1 del CSV)</li>
                    <li>Se usa como SKU principal en WooCommerce</li>
                    <li>Tiene formato fijo de 6 dígitos (rellenado con ceros a la izquierda)</li>
                    <li>Ejemplo: 000524, 001254, 002034, 012985, 100766</li>
                </ul>
                <p><strong>EAN13 (Código de Barras):</strong></p>
                <ul>
                    <li>Es el código de barras estándar (columna 6 del CSV)</li>
                    <li>Tiene 13 dígitos normalmente</li>
                    <li>Se usa para identificación internacional</li>
                    <li>Se guarda como metadato del producto</li>
                    <li>Ejemplo: 8470000052446, 8436558880160</li>
                </ul>
                <p><strong>Diferencias importantes:</strong></p>
                <ul>
                    <li>El plugin usa CN como SKU principal (6 dígitos fijos)</li>
                    <li>El EAN13 se guarda como metadato para búsquedas y imágenes (13 dígitos)</li>
                    <li>Ambos códigos se mantienen sincronizados</li>
                    <li>El CN es más corto y fácil de manejar que el EAN13</li>
                </ul>
            </div>
        </div>

        <!-- Sección de carga de imágenes -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>🖼️ Carga Automática de Imágenes</h2>
            <p>Esta función busca y descarga automáticamente imágenes para los productos usando el código EAN13.</p>
            
            <button id="load-images-btn" class="button button-secondary" style="margin-bottom: 10px;">
                🖼️ Cargar Imágenes Ahora
            </button>
            
            <div id="images-load-status"></div>
            
            <?php
            // Mostrar estadísticas de imágenes
            $total_products = wc_get_products(array('limit' => -1, 'return' => 'ids'));
            $products_with_images = 0;
            $products_with_ean13 = 0;
            
            foreach ($total_products as $product_id) {
                $product = wc_get_product($product_id);
                if ($product && $product->get_image_id()) {
                    $products_with_images++;
                }
                if (get_post_meta($product_id, '_ean13', true)) {
                    $products_with_ean13++;
                }
            }
            
            $total_count = count($total_products);
            $image_percentage = $total_count > 0 ? round(($products_with_images / $total_count) * 100, 1) : 0;
            ?>
            
            <div class="notice notice-info inline">
                <p><strong>📊 Estadísticas de Imágenes:</strong></p>
                <ul>
                    <li>Total de productos: <strong><?php echo $total_count; ?></strong></li>
                    <li>Productos con imágenes: <strong><?php echo $products_with_images; ?></strong> (<?php echo $image_percentage; ?>%)</li>
                    <li>Productos con EAN13: <strong><?php echo $products_with_ean13; ?></strong></li>
                    <li>Productos sin imagen: <strong><?php echo $total_count - $products_with_images; ?></strong></li>
                </ul>
                
                <p><strong>🔍 Fuentes de imágenes (en orden de prioridad):</strong></p>
                <ol>
                    <li><strong>FTP de Unycop</strong> - URL configurada + EAN13 + extensión (.jpg/.png)</li>
                    <li><strong>OpenFoodFacts API</strong> - Base de datos gratuita de productos</li>
                    <li><strong>Vademecum API</strong> - Base de datos española de medicamentos</li>
                    <li><strong>CIMA API (AEMPS)</strong> - Base de datos oficial española</li>
                    <li><strong>Generador de códigos de barras</strong> - Código EAN13 como imagen (siempre funciona)</li>
                    <li><strong>BarcodeLookup API</strong> - Requiere clave de API (opcional)</li>
                </ol>
                
                <p><strong>💡 Recomendaciones:</strong></p>
                <ul>
                    <li><strong>Sin FTP:</strong> El generador de códigos de barras siempre funcionará como fallback</li>
                    <li><strong>APIs españolas:</strong> Vademecum y CIMA pueden tener mejor cobertura para productos españoles</li>
                    <li><strong>Resultado garantizado:</strong> Al menos tendrás el código de barras como imagen identificativa</li>
                    <li><strong>Futuro:</strong> Si consigues acceso al FTP de Unycop, solo configura la URL y se usarán las imágenes reales</li>
                </ul>
            </div>
        </div>

        <!-- Sección de sincronización automática -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px;">
            <h2>🔄 Sincronización Automática</h2>
            <p>El plugin revisa automáticamente el archivo <code>stocklocal.csv</code> y actualiza los productos según la frecuencia configurada.</p>
            
            <?php
            // Obtener estadísticas de la última sincronización
            $last_sync_stats = get_option('unycop_last_sync_stats', array());
            $cron_frequency = get_option('unycop_cron_frequency', 'hourly');
            $auto_create = get_option('unycop_auto_create_products', 'no');
            
            // Traducir frecuencia
            $freq_text = array(
                'hourly' => 'Cada hora',
                'twicedaily' => 'Dos veces al día',
                'daily' => 'Diario'
            );
            
            // Verificar si el cron está activo
            $next_scheduled = wp_next_scheduled('product_sync_event');
            ?>
            
            <div class="notice notice-info inline">
                <p><strong>⚙️ Configuración Actual:</strong></p>
                <ul>
                    <li><strong>Frecuencia:</strong> <?php echo isset($freq_text[$cron_frequency]) ? $freq_text[$cron_frequency] : $cron_frequency; ?></li>
                    <li><strong>Estado del Cron:</strong> 
                        <?php if ($next_scheduled): ?>
                            <span style="color: #00a32a;">✅ Activo</span> - Próxima ejecución: <?php echo date('d/m/Y H:i:s', $next_scheduled); ?>
                        <?php else: ?>
                            <span style="color: #d63638;">❌ Inactivo</span>
                        <?php endif; ?>
                    </li>
                    <li><strong>Crear productos nuevos:</strong> <?php echo $auto_create === 'yes' ? '✅ Sí' : '❌ No'; ?></li>
                    <li><strong>Archivo CSV:</strong> 
                        <?php 
                        $csv_file = find_stocklocal_csv();
                        if ($csv_file): ?>
                            <span style="color: #00a32a;">✅ Encontrado</span> - <?php echo esc_html($csv_file); ?>
                        <?php else: ?>
                            <span style="color: #d63638;">❌ No encontrado</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <?php if (!empty($last_sync_stats)): ?>
            <div class="notice notice-success inline">
                <p><strong>📊 Última Sincronización Automática:</strong></p>
                <ul>
                    <li><strong>Fecha:</strong> <?php echo date('d/m/Y H:i:s', strtotime($last_sync_stats['timestamp'])); ?></li>
                    <li><strong>Productos actualizados:</strong> <?php echo $last_sync_stats['updated']; ?></li>
                    <li><strong>Productos creados:</strong> <?php echo $last_sync_stats['created']; ?></li>
                    <li><strong>Errores:</strong> <?php echo $last_sync_stats['errors']; ?></li>
                    <li><strong>Total procesado:</strong> <?php echo $last_sync_stats['total']; ?></li>
                    <?php 
                    $success_rate = $last_sync_stats['total'] > 0 ? round((($last_sync_stats['updated'] + $last_sync_stats['created']) / $last_sync_stats['total']) * 100, 1) : 0;
                    ?>
                    <li><strong>Tasa de éxito:</strong> 
                        <?php if ($success_rate >= 95): ?>
                            <span style="color: #00a32a;">🎉 <?php echo $success_rate; ?>% - Excelente</span>
                        <?php elseif ($success_rate >= 80): ?>
                            <span style="color: #ffb900;">⚠️ <?php echo $success_rate; ?>% - Parcial</span>
                        <?php else: ?>
                            <span style="color: #d63638;">❌ <?php echo $success_rate; ?>% - Problemas</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
            <?php else: ?>
            <div class="notice notice-warning inline">
                <p><strong>⏳ No se han registrado sincronizaciones automáticas aún.</strong></p>
                <p>La primera sincronización se ejecutará según la frecuencia configurada, o puedes forzarla manualmente con el botón "Actualizar Stock Ahora" de arriba.</p>
            </div>
            <?php endif; ?>

            <!-- Botones de control -->
            <div style="margin-top: 15px;">
                <button id="trigger-sync-btn" class="button button-secondary" style="margin-right: 10px;">
                    🔄 Ejecutar Sincronización Ahora
                </button>
                
                <?php if (!$next_scheduled): ?>
                <button id="reactivate-cron-btn" class="button button-primary">
                    ⚡ Reactivar Cron
                </button>
                <?php endif; ?>
            </div>
            
            <div id="sync-status" style="margin-top: 10px;"></div>
        </div>

        <!-- Formulario de configuración -->
        <div class="card" style="max-width: 800px;">
            <h2>Configuración General</h2>
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
                            <br><small>
                                <strong>Recomendaciones:</strong><br>
                                • <strong>Cada hora:</strong> Farmacias pequeñas (0-100 productos)<br>
                                • <strong>Dos veces al día:</strong> Farmacias medianas (100-500 productos)<br>
                                • <strong>Diario:</strong> Farmacias grandes (500+ productos)
                            </small>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Crear productos automáticamente</th>
                        <td>
                            <label>
                                <input type="checkbox" name="unycop_auto_create_products" value="yes" <?php checked(get_option('unycop_auto_create_products', 'no'), 'yes'); ?> />
                                Crear nuevos productos cuando aparezcan en stocklocal.csv
                            </label>
                            <br><small>
                                <strong>⚠️ Cuidado:</strong> Si está activado, cualquier producto nuevo en stocklocal.csv se creará automáticamente.<br>
                                <strong>Recomendado:</strong> Desactivar esta opción y usar la migración inicial para mayor control.
                            </small>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">URL del FTP de Unycop (para imágenes)</th>
                        <td><input type="text" name="unycop_ftp_url" value="<?php echo esc_attr(get_option('unycop_ftp_url', '')); ?>" size="60" /> <br><small>URL base del FTP donde están las imágenes (ej: ftp://usuario:password@servidor.com/images/)</small></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Clave de API para BarcodeLookup (opcional)</th>
                        <td><input type="text" name="unycop_barcode_api_key" value="<?php echo esc_attr(get_option('unycop_barcode_api_key', '')); ?>" size="40" /> <br><small>Si tienes una clave de API para BarcodeLookup, pégala aquí para mejorar la búsqueda de imágenes.</small></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
    </div>
    <?php
}

// =====================
// REGISTRO DE ENDPOINTS API REST
// =====================

// Registrar endpoints de la API REST
add_action('rest_api_init', function() {
    // Endpoint para descargar orders.csv
    register_rest_route('unycop/v1', '/orders', array(
        'methods' => 'GET',
        'callback' => 'unycop_api_get_orders_csv',
        'permission_callback' => '__return_true'
    ));
    
    // Endpoint para forzar actualización de stock
    register_rest_route('unycop/v1', '/stock-update', array(
        'methods' => 'POST',
        'callback' => 'unycop_api_stock_update',
        'permission_callback' => '__return_true'
    ));
    
    // Endpoint para actualización rápida
    register_rest_route('unycop/v1', '/quick-update', array(
        'methods' => 'POST',
        'callback' => 'unycop_api_quick_update',
        'permission_callback' => '__return_true'
    ));
    
    // Endpoint para estadísticas de pedidos
    register_rest_route('unycop/v1', '/orders-stats', array(
        'methods' => 'GET',
        'callback' => 'unycop_api_get_orders_stats',
        'permission_callback' => '__return_true'
    ));
});

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
    register_rest_route('unycop/v1', '/quick-update', array(
        'methods' => 'POST',
        'callback' => 'unycop_api_quick_update',
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

// Endpoint para actualización rápida de solo stock y precio
function unycop_api_quick_update($request) {
    if (!unycop_api_check_token($request)) {
        return new WP_REST_Response(['error' => 'Token inválido'], 403);
    }
    
    $start_time = microtime(true);
    $products_updated = sync_stock_and_price_only();
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    return new WP_REST_Response([
        'success' => true,
        'products_updated' => $products_updated,
        'execution_time' => $execution_time . ' segundos',
        'type' => 'quick_update'
    ], 200);
}

// Endpoint para obtener estadísticas de pedidos
function unycop_api_get_orders_stats($request) {
    if (!unycop_api_check_token($request)) {
        return new WP_REST_Response(['error' => 'Token inválido'], 403);
    }
    
    $date_from = $request->get_param('date_from');
    $date_to = $request->get_param('date_to');
    
    $stats = unycop_get_orders_statistics($date_from, $date_to);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $stats,
        'timestamp' => current_time('mysql')
    ], 200);
}

// =====================
// PROCESAMIENTO POR LOTES DE STOCKLOCAL.CSV
// =====================

// Nueva acción AJAX para procesar lotes
add_action('wp_ajax_unycop_update_stock_chunk', 'unycop_update_stock_chunk_handler');
function unycop_update_stock_chunk_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_update_stock_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    $chunk_size = 100;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $reset = isset($_POST['reset']) ? boolval($_POST['reset']) : false;

    // Si es el primer lote o se pide reset, limpiar progreso anterior
    if ($offset === 0 || $reset) {
        delete_option('unycop_stock_chunk_progress');
    }

    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    if (!file_exists($csv_file)) {
        wp_send_json_error('Archivo stocklocal.csv no encontrado en: ' . $csv_file);
    }

    $handle = fopen($csv_file, 'r');
    if (!$handle) {
        wp_send_json_error('No se pudo abrir el archivo CSV.');
    }

    // Leer cabecera
    $header = fgetcsv($handle, 0, ";");
    $current = 0;
    $processed = 0;
    $products_updated = 0;
    $products_created = 0;
    $errors = array();
    $details = array();

    // Saltar hasta el offset
    while ($current < $offset && ($row = fgetcsv($handle, 0, ";")) !== false) {
        $current++;
    }

    // Procesar el chunk
    while ($processed < $chunk_size && ($data = fgetcsv($handle, 0, ";")) !== false) {
        $current++;
        $processed++;
        try {
            $cn = $data[0];
            $stock = $data[1];
            $price_with_tax = $data[2];
            $iva = $data[3];
            $prospecto = $data[4];
            $ean13 = $data[5];
            $description = $data[6];
            $pc = $data[7];
            $family = $data[8];
            $category = $data[9];
            $subcategory = $data[10];
            $lab = $data[11];
            $pvp2 = $data[12];
            $locations = $data[13];

            $product_id = wc_get_product_id_by_sku($cn);
            if ($product_id) {
                $product = wc_get_product($product_id);
                $old_stock = $product->get_stock_quantity();
                $old_price = $product->get_regular_price();
                wc_update_product_stock($product_id, $stock, 'set');
                update_post_meta($product_id, '_manage_stock', 'yes');
                $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                $product->set_regular_price($price_with_tax);
                $product->set_price($price_without_tax);
                update_post_meta($product_id, '_prospecto_url', $prospecto);
                $product->set_description($description);
                $product->set_sku($ean13);
                $product->save();
                $products_updated++;
                $details[] = array(
                    'action' => 'updated',
                    'product_id' => $product_id,
                    'sku' => $cn,
                    'name' => $description,
                    'old_stock' => $old_stock,
                    'new_stock' => $stock,
                    'old_price' => $old_price,
                    'new_price' => $price_with_tax,
                    'iva' => $iva . '%',
                    'lab' => $lab,
                    'category' => $category
                );
            } else {
                $new_product = new WC_Product();
                $new_product->set_sku($cn);
                $new_product->set_name($description);
                $new_product->set_regular_price($price_with_tax);
                $new_product->set_stock_quantity($stock);
                $new_product->set_description($description);
                $new_product->set_manage_stock(true);
                $new_product->set_status('publish');
                $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                $new_product->set_price($price_without_tax);
                $new_product_id = $new_product->save();
                $products_created++;
                $details[] = array(
                    'action' => 'created',
                    'product_id' => $new_product_id,
                    'sku' => $cn,
                    'name' => $description,
                    'stock' => $stock,
                    'price' => $price_with_tax,
                    'iva' => $iva . '%',
                    'lab' => $lab,
                    'category' => $category
                );
            }
        } catch (Exception $e) {
            $errors[] = "Fila $current: " . $e->getMessage();
        }
    }
    $more = !feof($handle);
    fclose($handle);

    // Guardar progreso
    $progress = array(
        'offset' => $offset + $processed,
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'errors' => $errors,
        'details' => $details,
        'timestamp' => current_time('mysql')
    );
    update_option('unycop_stock_chunk_progress', $progress);

    // Calcular total de filas (sin cabecera)
    $total_rows = get_option('unycop_stock_chunk_total_rows');
    if (!$total_rows) {
        $total_rows = 0;
        if (($h = fopen($csv_file, 'r')) !== false) {
            fgetcsv($h, 0, ";"); // skip header
            while (fgetcsv($h, 0, ";") !== false) {
                $total_rows++;
            }
            fclose($h);
        }
        update_option('unycop_stock_chunk_total_rows', $total_rows);
    }

    wp_send_json_success(array(
        'offset' => $offset + $processed,
        'total' => $total_rows,
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'errors' => $errors,
        'details' => $details,
        'more' => $more,
        'lote' => ceil(($offset + $processed) / $chunk_size),
        'total_lotes' => ceil($total_rows / $chunk_size),
    ));
}

// Handler AJAX para cargar imágenes
add_action('wp_ajax_unycop_load_images', 'unycop_load_images_handler');

// Hook para el manejador AJAX de backup de productos
add_action('wp_ajax_unycop_backup_products', 'unycop_backup_products_handler');

// Hook para el manejador AJAX de migración inicial
add_action('wp_ajax_unycop_migrate_initial', 'unycop_migrate_initial_handler');

// Hook para el manejador AJAX de migración inicial por lotes
add_action('wp_ajax_unycop_migrate_initial_chunk', 'unycop_migrate_initial_chunk_handler');

// Hook para el manejador AJAX de restauración de productos
add_action('wp_ajax_unycop_restore_products', 'unycop_restore_products_handler');
function unycop_load_images_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_load_images_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $reset = isset($_POST['reset']) ? boolval($_POST['reset']) : false;

    // Si es el primer lote o se pide reset, limpiar progreso anterior
    if ($offset === 0 || $reset) {
        delete_option('unycop_images_chunk_progress');
    }

    $result = unycop_batch_load_images($batch_size);
    
    // Guardar progreso
    $progress = array(
        'offset' => $offset + $batch_size,
        'images_loaded' => $result['images_loaded'],
        'errors' => $result['errors'],
        'timestamp' => current_time('mysql')
    );
    update_option('unycop_images_chunk_progress', $progress);

    wp_send_json_success(array(
        'images_loaded' => $result['images_loaded'],
        'errors' => $result['errors'],
        'batch_size' => $batch_size,
        'offset' => $offset + $batch_size
    ));
}

// =====================
// FRONTEND JS PARA PROCESO AUTOMÁTICO POR LOTES
// =====================
add_action('admin_enqueue_scripts', 'unycop_admin_scripts_chunk');
function unycop_admin_scripts_chunk($hook) {
    if ($hook != 'settings_page_unycop-connector-settings') {
        return;
    }
    wp_enqueue_script('jquery');
    $nonce = wp_create_nonce('unycop_update_stock_nonce');
    $backup_nonce = wp_create_nonce('unycop_backup_products_nonce');
    $migrate_nonce = wp_create_nonce('unycop_migrate_initial_nonce');
    $restore_nonce = wp_create_nonce('unycop_restore_products_nonce');
    $js = <<<EOT
jQuery(document).ready(function($) {
    var chunkSize = 100;
    var processing = false;
    var loteActual = 1;
    var totalLotes = 1;
    var totalProductos = 0;
    var offset = 0;
    var resumen = {updated: 0, created: 0, errors: []};

    function procesarLote(offset, reset) {
        processing = true;
        var data = {
            action: 'unycop_update_stock_chunk',
            nonce: '$nonce',
            offset: offset,
            reset: reset ? 1 : 0
        };
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    var d = response.data;
                    loteActual = d.lote;
                    totalLotes = d.total_lotes;
                    totalProductos = d.total;
                    resumen.updated += d.products_updated;
                    resumen.created += d.products_created;
                    resumen.errors = resumen.errors.concat(d.errors);
                    $('#stock-update-status').html('<div class="notice notice-info inline"><p>Procesando lote ' + loteActual + ' de ' + totalLotes + '...<br>Productos procesados: ' + d.offset + ' de ' + totalProductos + '</p></div>');
                    if (d.more) {
                        setTimeout(function() { procesarLote(d.offset, false); }, 300);
                    } else {
                        mostrarResumen();
                        processing = false;
                    }
                } else {
                    $('#stock-update-status').html('<div class="notice notice-error inline"><p>❌ Error: ' + response.data + '</p></div>');
                    processing = false;
                }
            },
            error: function() {
                $('#stock-update-status').html('<div class="notice notice-error inline"><p>❌ Error de conexión al procesar el lote</p></div>');
                processing = false;
            }
        });
    }

    function mostrarResumen() {
        var html = '<div class="notice notice-success inline"><p>✅ ¡Actualización completada!<br>Productos actualizados: ' + resumen.updated + '<br>Productos creados: ' + resumen.created + '<br>Errores: ' + resumen.errors.length + '</p></div>';
        if (resumen.errors.length > 0) {
            html += '<div class="notice notice-warning inline"><p>Errores:<br>';
            resumen.errors.forEach(function(e) { html += e + '<br>'; });
            html += '</p></div>';
        }
        $('#stock-update-status').html(html);
    }

    $('#update-stock-btn').on('click', function(e) {
        e.preventDefault();
        if (processing) return;
        resumen = {updated: 0, created: 0, errors: []};
        $('#stock-update-status').html('<div class="notice notice-info inline"><p>Iniciando procesamiento por lotes...</p></div>');
        procesarLote(0, true);
    });

    // Manejar botón de backup de productos
    $('#backup-products-btn').on('click', function(e) {
        e.preventDefault();
        
        $('#backup-status').html('<div class="notice notice-info inline"><p>💾 Generando backup de productos...</p></div>');
        
        // Crear formulario temporal para descarga
        var form = $('<form>', {
            'method': 'POST',
            'action': ajaxurl,
            'target': '_blank'
        });
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'unycop_backup_products'
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'nonce',
            'value': '$backup_nonce'
        }));
        
        // Añadir al DOM y enviar
        $('body').append(form);
        form.submit();
        
        // Limpiar formulario después de un momento
        setTimeout(function() {
            form.remove();
            $('#backup-status').html('<div class="notice notice-success inline"><p>✅ Backup generado y descargado correctamente</p></div>');
        }, 2000);
    });

    // Variables para migración inicial
    var migracionProcesando = false;
    var migracionLoteActual = 1;
    var migracionTotalLotes = 1;
    var migracionTotalProductos = 0;
    var migracionOffset = 0;
    var migracionResumen = {migrated: 0, updated: 0, created: 0, errors: []};

    function procesarMigracionLote(offset, reset) {
        migracionProcesando = true;
        var data = {
            action: 'unycop_migrate_initial_chunk',
            nonce: '$migrate_nonce',
            offset: offset,
            reset: reset ? 1 : 0
        };
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    var d = response.data;
                    migracionLoteActual = d.lote;
                    migracionTotalLotes = d.total_lotes;
                    migracionTotalProductos = d.total;
                    migracionResumen.migrated = d.products_migrated;
                    migracionResumen.updated = d.products_updated;
                    migracionResumen.created = d.products_created;
                    migracionResumen.errors = d.errors;
                    
                    var productosEnLote = d.details.length;
                    var productosModificados = d.offset;
                    
                    var html = '<div class="notice notice-info inline"><p>🔄 Procesando migración lote ' + migracionLoteActual + ' de ' + migracionTotalLotes + '...<br>';
                    html += '📦 Productos procesados: ' + productosModificados + ' de ' + migracionTotalProductos + '<br>';
                    html += '✅ En este lote: ' + productosEnLote + ' productos<br>';
                    
                    // Mostrar algunos productos del lote actual
                    if (d.details && d.details.length > 0) {
                        html += '<strong>Últimos productos:</strong><br>';
                        var ultimosProductos = d.details.slice(-3); // Mostrar los últimos 3
                        ultimosProductos.forEach(function(producto, index) {
                            var icono = producto.action === 'created' ? '➕' : '🔄';
                            var accion = producto.action === 'created' ? 'Creado' : 'Migrado';
                            html += icono + ' ' + accion + ': ' + producto.name.substring(0, 50);
                            if (producto.name.length > 50) html += '...';
                            html += '<br>';
                        });
                    }
                    
                    html += '</p></div>';
                    $('#migrate-initial-status').html(html);
                    
                    if (d.more) {
                        setTimeout(function() { procesarMigracionLote(d.offset, false); }, 1000); // Aumentar pausa a 1 segundo
                    } else {
                        mostrarResumenMigracion();
                        migracionProcesando = false;
                    }
                } else {
                    $('#migrate-initial-status').html('<div class="notice notice-error inline"><p>❌ Error: ' + response.data + '</p></div>');
                    migracionProcesando = false;
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = '';
                if (status === 'timeout') {
                    errorMsg = '⏱️ Timeout - El servidor tardó demasiado en responder. Intenta de nuevo.';
                } else if (status === 'error') {
                    errorMsg = '🔌 Error de conexión - Verifica la conexión al servidor.';
                } else {
                    errorMsg = '❌ Error: ' + status + ' - ' + error;
                }
                $('#migrate-initial-status').html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                migracionProcesando = false;
            },
            timeout: 120000 // Timeout de 2 minutos
        });
    }

    function mostrarResumenMigracion() {
        var porcentajeExito = migracionTotalProductos > 0 ? Math.round((migracionResumen.migrated / migracionTotalProductos) * 100) : 0;
        
        var html = '<div class="notice notice-success inline"><p>✅ ¡Migración inicial completada!<br>';
        html += '<strong>📊 Resumen del proceso:</strong><br>';
        html += '🔄 Total procesado: ' + migracionTotalProductos + ' productos<br>';
        html += '✅ Productos migrados: ' + migracionResumen.migrated + ' (' + porcentajeExito + '%)<br>';
        html += '📝 Productos actualizados: ' + migracionResumen.updated + '<br>';
        html += '➕ Productos creados: ' + migracionResumen.created + '<br>';
        html += '❌ Errores: ' + migracionResumen.errors.length + '<br>';
        html += '📦 Lotes procesados: ' + migracionTotalLotes + '</p></div>';
        
        if (migracionResumen.errors.length > 0) {
            html += '<div class="notice notice-warning inline"><p><strong>⚠️ Errores encontrados:</strong><br>';
            migracionResumen.errors.slice(0, 10).forEach(function(e) { html += '• ' + e + '<br>'; });
            if (migracionResumen.errors.length > 10) {
                html += '• ... y ' + (migracionResumen.errors.length - 10) + ' errores más<br>';
            }
            html += '</p></div>';
        }
        
        // Mensaje de éxito o advertencia según el porcentaje
        if (porcentajeExito >= 95) {
            html += '<div class="notice notice-success inline"><p>🎉 <strong>Migración excelente!</strong> Más del 95% de productos procesados correctamente.</p></div>';
        } else if (porcentajeExito >= 80) {
            html += '<div class="notice notice-warning inline"><p>⚠️ <strong>Migración parcial.</strong> Revisa los errores para completar el proceso.</p></div>';
        } else {
            html += '<div class="notice notice-error inline"><p>❌ <strong>Migración con problemas.</strong> Muchos errores encontrados, revisa la configuración.</p></div>';
        }
        
        $('#migrate-initial-status').html(html);
    }

    // Manejar botón de migración inicial
    $('#migrate-initial-btn').on('click', function(e) {
        e.preventDefault();
        
        if (migracionProcesando) return;
        
        if (!confirm('⚠️ IMPORTANTE: Esta operación actualizará todos los productos usando stocklocal.csv y corregirá el mapeo CN/EAN13.\\n\\nSe procesará por lotes para evitar problemas de memoria.\\n\\n¿Estás seguro de continuar?')) {
            return;
        }
        
        migracionResumen = {migrated: 0, updated: 0, created: 0, errors: []};
        $('#migrate-initial-status').html('<div class="notice notice-info inline"><p>🔄 Iniciando migración inicial por lotes...</p></div>');
        
        // Primero inicializar la migración
        var data = {
            action: 'unycop_migrate_initial',
            nonce: '$migrate_nonce'
        };
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success && response.data.start_chunk) {
                    migracionTotalProductos = response.data.total_rows;
                    migracionTotalLotes = Math.ceil(migracionTotalProductos / 50); // Actualizado para chunks de 50
                    procesarMigracionLote(0, true);
                } else {
                    $('#migrate-initial-status').html('<div class="notice notice-error inline"><p>❌ Error al iniciar migración: ' + (response.data || 'Error desconocido') + '</p></div>');
                }
            },
            error: function() {
                $('#migrate-initial-status').html('<div class="notice notice-error inline"><p>❌ Error de conexión al iniciar migración</p></div>');
            }
        });
    });

    // Manejar selección de archivo para restaurar
    $('#restore-file-input').on('change', function() {
        var file = this.files[0];
        if (file && file.name.endsWith('.csv')) {
            $('#restore-products-btn').prop('disabled', false);
        } else {
            $('#restore-products-btn').prop('disabled', true);
            if (file) {
                alert('Por favor selecciona un archivo CSV válido');
            }
        }
    });

    // Manejar botón de restaurar productos
    $('#restore-products-btn').on('click', function(e) {
        e.preventDefault();
        
        var fileInput = $('#restore-file-input')[0];
        if (!fileInput.files[0]) {
            alert('Por favor selecciona un archivo CSV para restaurar');
            return;
        }
        
        if (!confirm('⚠️ ADVERTENCIA: Esta operación restaurará productos desde el backup CSV y SOBRESCRIBIRÁ los datos actuales.\\n\\n¿Estás seguro de continuar?')) {
            return;
        }
        
        $('#restore-status').html('<div class="notice notice-info inline"><p>📤 Restaurando productos desde backup CSV...</p></div>');
        
        // Crear FormData para enviar el archivo
        var formData = new FormData();
        formData.append('action', 'unycop_restore_products');
        formData.append('nonce', '$restore_nonce');
        formData.append('backup_file', fileInput.files[0]);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    var html = '<div class="notice notice-success inline"><p>✅ ¡Restauración completada!<br>';
                    html += 'Productos restaurados: ' + response.data.products_restored + '<br>';
                    html += 'Productos actualizados: ' + response.data.products_updated + '<br>';
                    html += 'Productos creados: ' + response.data.products_created + '<br>';
                    html += 'Errores: ' + response.data.errors.length + '</p></div>';
                    
                    if (response.data.errors.length > 0) {
                        html += '<div class="notice notice-warning inline"><p>Errores encontrados:<br>';
                        response.data.errors.forEach(function(error) {
                            html += error + '<br>';
                        });
                        html += '</p></div>';
                    }
                    
                    $('#restore-status').html(html);
                    
                    // Limpiar input
                    $('#restore-file-input').val('');
                    $('#restore-products-btn').prop('disabled', true);
                } else {
                    $('#restore-status').html('<div class="notice notice-error inline"><p>❌ Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $('#restore-status').html('<div class="notice notice-error inline"><p>❌ Error de conexión durante la restauración</p></div>');
            }
        });
    });
});
EOT;
    wp_add_inline_script('jquery', $js);
}

// =====================
// FUNCIÓN PARA GENERAR BACKUP DE PRODUCTOS
// =====================
function unycop_backup_products_handler() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_backup_products_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    try {
        // Generar el backup CSV
        $backup_content = unycop_generate_products_backup_csv();
        
        // Nombre del archivo con timestamp
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_productos_unycop_{$timestamp}.csv";
        
        // Configurar headers para descarga
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        
        // Añadir BOM para UTF-8 (compatibilidad con Excel)
        echo "\xEF\xBB\xBF";
        echo $backup_content;
        
        exit;
        
    } catch (Exception $e) {
        wp_send_json_error('Error al generar backup: ' . $e->getMessage());
    }
}

function unycop_generate_products_backup_csv() {
    // Obtener todos los productos de WooCommerce
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish'
    ));
    
    // Crear contenido CSV
    $csv_content = '';
    
    // Encabezados del CSV (mismo formato que stocklocal.csv de Unycop)
    $headers = array(
        'CN', // Código Nacional (SKU)
        'Stock', // Existencias
        'PVP_con_IVA', // PVP con IVA
        'IVA', // Tipo de IVA
        'Prospecto', // Enlace al prospecto PDF
        'EAN13', // Código de barras
        'Descripcion', // Descripción del medicamento
        'Precio_Coste', // Último precio de coste
        'Familia', // Familia
        'Categoria', // Categoría
        'Subcategoria', // Subcategoría
        'Laboratorio', // Laboratorio
        'PVP2', // PVP2
        'Ubicaciones', // Ubicaciones
        'ID_WooCommerce', // ID del producto en WooCommerce
        'Estado', // Estado del producto
        'Fecha_Creacion', // Fecha de creación
        'Fecha_Modificacion' // Fecha de última modificación
    );
    
    // Añadir encabezados al CSV
    $csv_content .= implode(';', $headers) . "\n";
    
    // Procesar cada producto
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        $stock = $product->get_stock_quantity();
        $price = $product->get_regular_price();
        $description = $product->get_description();
        $name = $product->get_name();
        
        // Obtener metadatos específicos de Unycop
        $ean13 = get_post_meta($product_id, '_ean13', true);
        $cn_reference = get_post_meta($product_id, '_cn_reference', true);
        $prospecto_url = get_post_meta($product_id, '_prospecto_url', true);
        
        // Usar CN como referencia principal (SKU), con fallback
        $cn = !empty($cn_reference) ? $cn_reference : $sku;
        
        // Obtener categorías del producto
        $categories = wp_get_post_terms($product_id, 'product_cat');
        $category_names = array();
        foreach ($categories as $category) {
            $category_names[] = $category->name;
        }
        $categoria = implode(', ', $category_names);
        
        // Obtener fechas
        $post = get_post($product_id);
        $fecha_creacion = $post->post_date;
        $fecha_modificacion = $post->post_modified;
        
        // Calcular IVA aproximado (basado en el precio)
        $iva = 21; // IVA por defecto
        
        // Preparar fila de datos
        $row_data = array(
            $cn ?: 'N/A', // CN
            $stock ?: '0', // Stock
            $price ?: '0.00', // PVP con IVA
            $iva, // IVA
            $prospecto_url ?: '', // Prospecto
            $ean13 ?: '', // EAN13
            $description ?: $name, // Descripción
            '', // Precio coste (no disponible en WooCommerce)
            '', // Familia (no disponible)
            $categoria, // Categoría
            '', // Subcategoría (no disponible)
            '', // Laboratorio (no disponible)
            '', // PVP2 (no disponible)
            '', // Ubicaciones (no disponible)
            $product_id, // ID WooCommerce
            $product->get_status(), // Estado
            $fecha_creacion, // Fecha creación
            $fecha_modificacion // Fecha modificación
        );
        
        // Escapar caracteres especiales para CSV
        $escaped_data = array();
        foreach ($row_data as $field) {
            // Escapar comillas y punto y coma
            $field = str_replace('"', '""', $field);
            if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                $field = '"' . $field . '"';
            }
            $escaped_data[] = $field;
        }
        
        // Añadir fila al CSV
        $csv_content .= implode(';', $escaped_data) . "\n";
    }
    
    return $csv_content;
}

// =====================
// FUNCIÓN PARA MIGRACIÓN INICIAL DESDE stocklocal.csv
// =====================
function unycop_migrate_initial_handler() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_migrate_initial_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    // Limpiar progreso anterior al iniciar
    delete_option('unycop_migrate_initial_progress');
    delete_option('unycop_migrate_initial_total_rows');
    
    // Verificar que existe el archivo (con autodetección)
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    
    // Si no existe, intentar autodetectar en ubicaciones comunes
    if (!file_exists($csv_file)) {
        $possible_paths = array(
            ABSPATH . 'wp-content/uploads/unycop/stocklocal.csv',
            WP_CONTENT_DIR . '/uploads/unycop/stocklocal.csv',
            dirname(__FILE__) . '/stocklocal.csv', // Carpeta del plugin
            ABSPATH . '../wp-content/uploads/unycop/stocklocal.csv'
        );
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $csv_file = $path;
                $csv_path = dirname($path);
                // Guardar la ruta encontrada automáticamente
                update_option('unycop_csv_path', $csv_path);
                break;
            }
        }
    }
    
    if (!file_exists($csv_file)) {
        // Información adicional de diagnóstico
        $debug_info = array(
            'ruta_configurada' => $custom_path,
            'ruta_calculada' => $csv_path,
            'archivo_completo' => $csv_file,
            'directorio_existe' => is_dir($csv_path) ? 'SÍ' : 'NO',
            'archivos_en_directorio' => is_dir($csv_path) ? scandir($csv_path) : 'Directorio no existe'
        );
        wp_send_json_error('No se encontró el archivo stocklocal.csv en: ' . $csv_file . ' | Diagnóstico: ' . json_encode($debug_info));
    }

    // Contar total de filas para progreso
    $total_rows = 0;
    if (($h = fopen($csv_file, 'r')) !== false) {
        fgetcsv($h, 0, ";"); // skip header
        while (fgetcsv($h, 0, ";") !== false) {
            $total_rows++;
        }
        fclose($h);
    }
    update_option('unycop_migrate_initial_total_rows', $total_rows);
    
    wp_send_json_success(array(
        'message' => 'Migración inicial iniciada por lotes',
        'total_rows' => $total_rows,
        'start_chunk' => true
    ));
}

function unycop_execute_initial_migration() {
    // Verificar que existe el archivo stocklocal.csv
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    
    if (!file_exists($csv_file)) {
        return array(
            'success' => false,
            'message' => 'No se encontró el archivo stocklocal.csv en: ' . $csv_file
        );
    }

    $products_updated = 0;
    $products_created = 0;
    $products_migrated = 0;
    $errors = array();
    $details = array();

    // Obtener todos los productos existentes para mapear por SKU actual
    $existing_products = wc_get_products(array('limit' => -1));
    $product_map = array();
    
    foreach ($existing_products as $product) {
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $product_map[$sku] = $product->get_id();
        }
    }

    // Procesar el archivo CSV
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        // Leer encabezados
        fgetcsv($handle, 1000, ";");

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            try {
                $cn = $data[0]; // CN del artículo (Referencia) - 6 dígitos
                $stock = $data[1]; // Existencias
                $price_with_tax = $data[2]; // PVP con IVA
                $iva = $data[3]; // Tipo de IVA
                $prospecto = $data[4]; // Enlace al prospecto PDF
                $ean13 = $data[5]; // Código de barras - 13 dígitos
                $description = $data[6]; // Descripción del medicamento
                $pc = $data[7]; // Último precio de coste
                $family = $data[8]; // Familia
                $category = $data[9]; // Categoría
                $subcategory = $data[10]; // Subcategoría
                $lab = $data[11]; // Laboratorio
                $pvp2 = $data[12]; // PVP2
                $locations = $data[13]; // Ubicaciones

                // Buscar producto existente por SKU actual (puede estar mal mapeado)
                $product_id = null;
                
                // Primero buscar por CN (correcto)
                if (isset($product_map[$cn])) {
                    $product_id = $product_map[$cn];
                }
                // Si no existe, buscar por EAN13 (mal mapeo previo)
                elseif (isset($product_map[$ean13])) {
                    $product_id = $product_map[$ean13];
                }
                // También buscar usando la función de WooCommerce
                else {
                    $product_id = wc_get_product_id_by_sku($cn);
                    if (!$product_id) {
                        $product_id = wc_get_product_id_by_sku($ean13);
                    }
                }

                if ($product_id) {
                    // ACTUALIZAR producto existente con mapeo correcto
                    $product = wc_get_product($product_id);

                    // Actualizar SKU al CN correcto (6 dígitos)
                    $old_sku = $product->get_sku();
                    $product->set_sku($cn);

                    // Actualizar stock
                    wc_update_product_stock($product_id, $stock, 'set');
                    update_post_meta($product_id, '_manage_stock', 'yes');

                    // Actualizar precios
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $product->set_regular_price($price_with_tax);
                    $product->set_price($price_without_tax);

                    // Actualizar descripción
                    $product->set_description($description);

                    // MAPEO CORRECTO DE METADATOS
                    update_post_meta($product_id, '_cn_reference', $cn); // CN (6 dígitos)
                    update_post_meta($product_id, '_ean13', $ean13); // EAN13 (13 dígitos)
                    update_post_meta($product_id, '_prospecto_url', $prospecto);

                    // Guardar producto
                    $product->save();

                    $details[] = array(
                        'action' => 'migrated',
                        'product_id' => $product_id,
                        'name' => $description,
                        'old_sku' => $old_sku,
                        'new_sku' => $cn,
                        'cn' => $cn,
                        'ean13' => $ean13,
                        'stock' => $stock,
                        'price' => $price_with_tax
                    );

                    $products_migrated++;
                    $products_updated++;

                } else {
                    // CREAR nuevo producto con mapeo correcto
                    $new_product = new WC_Product();
                    $new_product->set_sku($cn); // SKU = CN (6 dígitos)
                    $new_product->set_name($description);
                    $new_product->set_regular_price($price_with_tax);
                    $new_product->set_stock_quantity($stock);
                    $new_product->set_description($description);
                    $new_product->set_manage_stock(true);
                    $new_product->set_status('publish');
                    
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $new_product->set_price($price_without_tax);
                    
                    // Guardar producto
                    $new_product_id = $new_product->save();
                    
                    // MAPEO CORRECTO DE METADATOS
                    update_post_meta($new_product_id, '_cn_reference', $cn); // CN (6 dígitos)
                    update_post_meta($new_product_id, '_ean13', $ean13); // EAN13 (13 dígitos)
                    update_post_meta($new_product_id, '_prospecto_url', $prospecto);

                    $details[] = array(
                        'action' => 'created',
                        'product_id' => $new_product_id,
                        'name' => $description,
                        'sku' => $cn,
                        'cn' => $cn,
                        'ean13' => $ean13,
                        'stock' => $stock,
                        'price' => $price_with_tax
                    );

                    $products_created++;
                }

            } catch (Exception $e) {
                $errors[] = "Error en fila con CN $cn: " . $e->getMessage();
            }
        }
        fclose($handle);
    }

    return array(
        'success' => true,
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'products_migrated' => $products_migrated,
        'errors' => $errors,
        'details' => $details
    );
}

// Función para manejar migración inicial por lotes
function unycop_migrate_initial_chunk_handler() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_migrate_initial_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $chunk_size = 50; // Procesar 50 productos por lote (más seguro)
    $reset = isset($_POST['reset']) ? boolval($_POST['reset']) : false;

    // Si es reset, limpiar progreso
    if ($reset) {
        delete_option('unycop_migrate_initial_progress');
    }

    // Obtener progreso actual
    $progress = get_option('unycop_migrate_initial_progress', array(
        'products_updated' => 0,
        'products_created' => 0,
        'products_migrated' => 0,
        'errors' => array(),
        'details' => array()
    ));

    // Procesar lote
    $results = unycop_execute_initial_migration_chunk($offset, $chunk_size);
    
    if (!$results['success']) {
        wp_send_json_error($results['message']);
    }

    // Actualizar progreso acumulado
    $progress['products_updated'] += $results['products_updated'];
    $progress['products_created'] += $results['products_created'];
    $progress['products_migrated'] += $results['products_migrated'];
    $progress['errors'] = array_merge($progress['errors'], $results['errors']);
    $progress['details'] = array_merge($progress['details'], $results['details']);
    $progress['timestamp'] = current_time('mysql');

    // Guardar progreso
    update_option('unycop_migrate_initial_progress', $progress);

    // Calcular total de filas
    $total_rows = get_option('unycop_migrate_initial_total_rows', 0);

    wp_send_json_success(array(
        'offset' => $offset + $results['processed'],
        'total' => $total_rows,
        'products_updated' => $progress['products_updated'],
        'products_created' => $progress['products_created'],
        'products_migrated' => $progress['products_migrated'],
        'errors' => $progress['errors'],
        'details' => $results['details'], // Solo detalles del lote actual
        'more' => $results['more'],
        'lote' => ceil(($offset + $results['processed']) / $chunk_size),
        'total_lotes' => ceil($total_rows / $chunk_size),
    ));
}

function unycop_execute_initial_migration_chunk($offset, $chunk_size) {
    // Aumentar límites para evitar timeouts
    ini_set('max_execution_time', 300); // 5 minutos
    ini_set('memory_limit', '512M'); // Más memoria
    // Obtener archivo CSV
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    $csv_file = $csv_path . '/stocklocal.csv';
    
    if (!file_exists($csv_file)) {
        return array(
            'success' => false,
            'message' => 'No se encontró el archivo stocklocal.csv'
        );
    }

    $products_updated = 0;
    $products_created = 0;
    $products_migrated = 0;
    $errors = array();
    $details = array();
    $processed = 0;

    // Obtener todos los productos existentes para mapeo rápido (solo una vez)
    static $product_map = null;
    if ($product_map === null) {
        $existing_products = wc_get_products(array('limit' => -1));
        $product_map = array();
        foreach ($existing_products as $product) {
            $sku = $product->get_sku();
            if (!empty($sku)) {
                $product_map[$sku] = $product->get_id();
            }
        }
    }

    // Procesar el archivo CSV
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        // Leer encabezados
        fgetcsv($handle, 1000, ";");

        // Saltar filas hasta el offset
        $current = 0;
        while ($current < $offset && fgetcsv($handle, 1000, ";") !== FALSE) {
            $current++;
        }

        // Procesar chunk
        while ($processed < $chunk_size && ($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            try {
                $cn = $data[0]; // CN del artículo (Referencia) - 6 dígitos
                $stock = $data[1]; // Existencias
                $price_with_tax = $data[2]; // PVP con IVA
                $iva = $data[3]; // Tipo de IVA
                $prospecto = $data[4]; // Enlace al prospecto PDF
                $ean13 = $data[5]; // Código de barras - 13 dígitos
                $description = $data[6]; // Descripción del medicamento
                $pc = $data[7]; // Último precio de coste
                $family = $data[8]; // Familia
                $category = $data[9]; // Categoría
                $subcategory = $data[10]; // Subcategoría
                $lab = $data[11]; // Laboratorio
                $pvp2 = $data[12]; // PVP2
                $locations = $data[13]; // Ubicaciones

                // Buscar producto existente
                $product_id = null;
                
                // Primero buscar por CN (correcto)
                if (isset($product_map[$cn])) {
                    $product_id = $product_map[$cn];
                }
                // Si no existe, buscar por EAN13 (mal mapeo previo)
                elseif (isset($product_map[$ean13])) {
                    $product_id = $product_map[$ean13];
                }

                if ($product_id) {
                    // ACTUALIZAR producto existente con mapeo correcto
                    $product = wc_get_product($product_id);
                    $old_sku = $product->get_sku();

                    // Actualizar SKU al CN correcto (6 dígitos)
                    $product->set_sku($cn);

                    // Actualizar stock
                    wc_update_product_stock($product_id, $stock, 'set');
                    update_post_meta($product_id, '_manage_stock', 'yes');

                    // Actualizar precios
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $product->set_regular_price($price_with_tax);
                    $product->set_price($price_without_tax);

                    // Actualizar descripción
                    $product->set_description($description);

                    // MAPEO CORRECTO DE METADATOS
                    update_post_meta($product_id, '_cn_reference', $cn); // CN (6 dígitos)
                    update_post_meta($product_id, '_ean13', $ean13); // EAN13 (13 dígitos)
                    update_post_meta($product_id, '_prospecto_url', $prospecto);

                    // Guardar producto
                    $product->save();

                    $details[] = array(
                        'action' => 'migrated',
                        'product_id' => $product_id,
                        'name' => $description,
                        'old_sku' => $old_sku,
                        'new_sku' => $cn,
                        'cn' => $cn,
                        'ean13' => $ean13
                    );

                    $products_migrated++;
                    $products_updated++;

                } else {
                    // CREAR nuevo producto con mapeo correcto
                    $new_product = new WC_Product();
                    $new_product->set_sku($cn); // SKU = CN (6 dígitos)
                    $new_product->set_name($description);
                    $new_product->set_regular_price($price_with_tax);
                    $new_product->set_stock_quantity($stock);
                    $new_product->set_description($description);
                    $new_product->set_manage_stock(true);
                    $new_product->set_status('publish');
                    
                    $price_without_tax = $price_with_tax / (1 + ($iva / 100));
                    $new_product->set_price($price_without_tax);
                    
                    // Guardar producto
                    $new_product_id = $new_product->save();
                    
                    // MAPEO CORRECTO DE METADATOS
                    update_post_meta($new_product_id, '_cn_reference', $cn); // CN (6 dígitos)
                    update_post_meta($new_product_id, '_ean13', $ean13); // EAN13 (13 dígitos)
                    update_post_meta($new_product_id, '_prospecto_url', $prospecto);

                    // Actualizar mapa para futuros lotes
                    $product_map[$cn] = $new_product_id;

                    $details[] = array(
                        'action' => 'created',
                        'product_id' => $new_product_id,
                        'name' => $description,
                        'sku' => $cn,
                        'cn' => $cn,
                        'ean13' => $ean13
                    );

                    $products_created++;
                    $products_migrated++;
                }

                $processed++;

            } catch (Exception $e) {
                $errors[] = "Error en fila " . ($offset + $processed + 1) . " con CN '$cn': " . $e->getMessage();
                $processed++;
            }
        }
        
        $more = !feof($handle);
        fclose($handle);
    }

    return array(
        'success' => true,
        'products_updated' => $products_updated,
        'products_created' => $products_created,
        'products_migrated' => $products_migrated,
        'errors' => $errors,
        'details' => $details,
        'processed' => $processed,
        'more' => $more
    );
}

// =====================
// FUNCIÓN PARA RESTAURAR PRODUCTOS DESDE CSV BACKUP
// =====================
function unycop_restore_products_handler() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_restore_products_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    // Verificar que se subió un archivo
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('No se subió ningún archivo o hubo un error en la subida');
    }

    $file = $_FILES['backup_file'];
    
    // Verificar tipo de archivo
    if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        wp_send_json_error('El archivo debe ser un CSV');
    }

    // Procesar el archivo
    $results = unycop_process_restore_csv($file['tmp_name']);
    
    if ($results['success']) {
        wp_send_json_success(array(
            'products_restored' => $results['products_restored'],
            'products_created' => $results['products_created'],
            'products_updated' => $results['products_updated'],
            'errors' => $results['errors'],
            'details' => $results['details']
        ));
    } else {
        wp_send_json_error($results['message']);
    }
}

function unycop_process_restore_csv($csv_file_path) {
    if (!file_exists($csv_file_path)) {
        return array(
            'success' => false,
            'message' => 'No se pudo acceder al archivo CSV'
        );
    }

    $products_restored = 0;
    $products_created = 0;
    $products_updated = 0;
    $errors = array();
    $details = array();

    // Procesar el archivo CSV
    if (($handle = fopen($csv_file_path, "r")) !== FALSE) {
        // Leer encabezados
        $headers = fgetcsv($handle, 1000, ";");
        
        // Verificar que es un backup válido (debe tener columnas específicas)
        $required_columns = array('CN', 'Stock', 'PVP_con_IVA', 'EAN13', 'Descripcion');
        $missing_columns = array();
        
        foreach ($required_columns as $required) {
            if (!in_array($required, $headers)) {
                $missing_columns[] = $required;
            }
        }
        
        if (!empty($missing_columns)) {
            fclose($handle);
            return array(
                'success' => false,
                'message' => 'El archivo CSV no parece ser un backup válido. Faltan columnas: ' . implode(', ', $missing_columns)
            );
        }

        // Mapear índices de columnas
        $column_map = array_flip($headers);

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            try {
                // Extraer datos usando el mapeo de columnas
                $cn = isset($column_map['CN']) ? $data[$column_map['CN']] : '';
                $stock = isset($column_map['Stock']) ? $data[$column_map['Stock']] : '0';
                $price = isset($column_map['PVP_con_IVA']) ? $data[$column_map['PVP_con_IVA']] : '0';
                $ean13 = isset($column_map['EAN13']) ? $data[$column_map['EAN13']] : '';
                $description = isset($column_map['Descripcion']) ? $data[$column_map['Descripcion']] : '';
                $iva = isset($column_map['IVA']) ? $data[$column_map['IVA']] : '21';
                $categoria = isset($column_map['Categoria']) ? $data[$column_map['Categoria']] : '';
                $prospecto = isset($column_map['Prospecto']) ? $data[$column_map['Prospecto']] : '';
                $wc_id = isset($column_map['ID_WooCommerce']) ? $data[$column_map['ID_WooCommerce']] : '';

                // Saltar filas vacías
                if (empty($cn) && empty($description)) {
                    continue;
                }

                // Buscar producto existente
                $product_id = null;
                
                // Primero buscar por ID de WooCommerce si existe
                if (!empty($wc_id) && is_numeric($wc_id)) {
                    $product = wc_get_product($wc_id);
                    if ($product && $product->exists()) {
                        $product_id = $wc_id;
                    }
                }
                
                // Si no se encontró por ID, buscar por SKU (CN)
                if (!$product_id && !empty($cn)) {
                    $product_id = wc_get_product_id_by_sku($cn);
                }

                if ($product_id) {
                    // ACTUALIZAR producto existente
                    $product = wc_get_product($product_id);
                    $old_name = $product->get_name();
                    $old_stock = $product->get_stock_quantity();
                    $old_price = $product->get_regular_price();

                    // Actualizar datos básicos
                    if (!empty($cn)) {
                        $product->set_sku($cn);
                    }
                    if (!empty($description)) {
                        $product->set_name($description);
                        $product->set_description($description);
                    }
                    if (!empty($price)) {
                        $product->set_regular_price($price);
                        // Calcular precio sin IVA
                        $iva_rate = is_numeric($iva) ? floatval($iva) : 21;
                        $price_without_tax = $price / (1 + ($iva_rate / 100));
                        $product->set_price($price_without_tax);
                    }
                    if (!empty($stock)) {
                        $product->set_stock_quantity(intval($stock));
                    }

                    // Actualizar metadatos
                    if (!empty($cn)) {
                        update_post_meta($product_id, '_cn_reference', $cn);
                    }
                    if (!empty($ean13)) {
                        update_post_meta($product_id, '_ean13', $ean13);
                    }
                    if (!empty($prospecto)) {
                        update_post_meta($product_id, '_prospecto_url', $prospecto);
                    }

                    // Guardar producto
                    $product->save();

                    $details[] = array(
                        'action' => 'restored',
                        'product_id' => $product_id,
                        'name' => $description,
                        'cn' => $cn,
                        'old_stock' => $old_stock,
                        'new_stock' => $stock,
                        'old_price' => $old_price,
                        'new_price' => $price
                    );

                    $products_updated++;
                    $products_restored++;

                } else {
                    // CREAR nuevo producto
                    if (empty($cn) || empty($description)) {
                        $errors[] = "Fila sin CN o descripción - omitida";
                        continue;
                    }

                    $new_product = new WC_Product();
                    $new_product->set_sku($cn);
                    $new_product->set_name($description);
                    $new_product->set_description($description);
                    $new_product->set_manage_stock(true);
                    $new_product->set_status('publish');
                    
                    if (!empty($price)) {
                        $new_product->set_regular_price($price);
                        // Calcular precio sin IVA
                        $iva_rate = is_numeric($iva) ? floatval($iva) : 21;
                        $price_without_tax = $price / (1 + ($iva_rate / 100));
                        $new_product->set_price($price_without_tax);
                    }
                    
                    if (!empty($stock)) {
                        $new_product->set_stock_quantity(intval($stock));
                    }
                    
                    // Guardar producto
                    $new_product_id = $new_product->save();
                    
                    // Añadir metadatos
                    if (!empty($cn)) {
                        update_post_meta($new_product_id, '_cn_reference', $cn);
                    }
                    if (!empty($ean13)) {
                        update_post_meta($new_product_id, '_ean13', $ean13);
                    }
                    if (!empty($prospecto)) {
                        update_post_meta($new_product_id, '_prospecto_url', $prospecto);
                    }

                    $details[] = array(
                        'action' => 'created',
                        'product_id' => $new_product_id,
                        'name' => $description,
                        'cn' => $cn,
                        'stock' => $stock,
                        'price' => $price
                    );

                    $products_created++;
                    $products_restored++;
                }

            } catch (Exception $e) {
                $errors[] = "Error en fila con CN '$cn': " . $e->getMessage();
            }
        }
        fclose($handle);
    }

    return array(
        'success' => true,
        'products_restored' => $products_restored,
        'products_created' => $products_created,
        'products_updated' => $products_updated,
        'errors' => $errors,
        'details' => $details
    );
}

// =====================
// HANDLERS AJAX PARA SINCRONIZACIÓN AUTOMÁTICA
// =====================

// Handler para ejecutar sincronización manualmente
add_action('wp_ajax_unycop_trigger_sync', 'unycop_trigger_sync_handler');
function unycop_trigger_sync_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_trigger_sync_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    try {
        // Ejecutar sincronización
        $products_processed = sync_stock_from_csv();
        
        // Obtener estadísticas de la última sincronización
        $last_sync_stats = get_option('unycop_last_sync_stats', array());
        
        if ($products_processed > 0) {
            wp_send_json_success(array(
                'message' => 'Sincronización completada correctamente',
                'products_processed' => $products_processed,
                'stats' => $last_sync_stats
            ));
        } else {
            wp_send_json_error('No se procesaron productos. Revisa que el archivo stocklocal.csv exista y tenga datos válidos.');
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error durante la sincronización: ' . $e->getMessage());
    }
}

// Handler para reactivar el cron
add_action('wp_ajax_unycop_reactivate_cron', 'unycop_reactivate_cron_handler');
function unycop_reactivate_cron_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'unycop_reactivate_cron_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
    }

    try {
        // Limpiar cron existente por si acaso
        wp_clear_product_sync_schedule();
        
        // Programar nuevo cron
        wp_schedule_product_sync();
        
        // Verificar que se programó correctamente
        $next_scheduled = wp_next_scheduled('product_sync_event');
        
        if ($next_scheduled) {
            wp_send_json_success(array(
                'message' => 'Cron reactivado correctamente',
                'next_execution' => date('d/m/Y H:i:s', $next_scheduled)
            ));
        } else {
            wp_send_json_error('Error al reactivar el cron');
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error al reactivar el cron: ' . $e->getMessage());
    }
}

// Función optimizada para actualizaciones rápidas de solo stock y precio
function sync_stock_and_price_only() {
    // Configurar límites de tiempo y memoria para AJAX
    set_time_limit(300); // 5 minutos
    ini_set('memory_limit', '256M');
    
    // Verificar que WooCommerce esté disponible
    if (!function_exists('wc_get_product_id_by_sku')) {
        error_log('UNYCOP SYNC ERROR: WooCommerce no está disponible en sync_stock_and_price_only');
        return array(
            'products_updated' => 0,
            'stock_changes' => 0,
            'price_changes' => 0,
            'errors' => 1
        );
    }
    
    $csv_file = find_stocklocal_csv();
    
    if (!$csv_file) {
        error_log('UNYCOP SYNC: stocklocal.csv no encontrado');
        return array(
            'products_updated' => 0,
            'stock_changes' => 0,
            'price_changes' => 0,
            'errors' => 1
        );
    }

    $products_updated = 0;
    $stock_changes = 0;
    $price_changes = 0;
    $errors = 0;
    $products_without_sku = 0;
    $products_not_loaded = 0;
    $changes_details = array(); // Array para almacenar detalles de cambios
    
    error_log('UNYCOP SYNC: ===== INICIO ACTUALIZACIÓN RÁPIDA =====');
    error_log('UNYCOP SYNC: Iniciando actualización rápida de stock y precio desde ' . $csv_file);

    // Primero cargar todos los datos del CSV en un array para acceso rápido
    $csv_data = array();
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        fgetcsv($handle, 1000, ";"); // Saltar encabezados
        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            if (count($data) >= 7) {
                $cn = trim($data[0]); // No forzar 6 dígitos, usar el valor tal como está
                if (!empty($cn)) {
                    $csv_data[$cn] = array(
                        'stock' => intval($data[1]),
                        'price' => floatval($data[2]),
                        'iva' => floatval($data[3])
                    );
                    // Log para debug del formato
                    error_log("UNYCOP SYNC DEBUG: Cargando CSV - CN original: '{$data[0]}', CN procesado: '{$cn}'");
                }
            }
        }
        fclose($handle);
    }
    
    error_log("UNYCOP SYNC: Cargados " . count($csv_data) . " productos del CSV");
    error_log("UNYCOP SYNC: SKUs en CSV: " . implode(', ', array_keys($csv_data)));
    
    // Mostrar algunos ejemplos de datos del CSV para debug
    $sample_count = 0;
    foreach ($csv_data as $sku => $data) {
        if ($sample_count < 5) {
            error_log("UNYCOP SYNC DEBUG: Muestra CSV - SKU: {$sku}, Stock: {$data['stock']}, Precio: {$data['price']}");
            $sample_count++;
        } else {
            break;
        }
    }

    // Ahora recorrer todos los productos de WooCommerce
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_sku',
                'compare' => 'EXISTS'
            )
        )
    );
    
    $products_query = new WP_Query($args);
    $total_woo_products = $products_query->found_posts;
    $processed = 0;
    
    error_log("UNYCOP SYNC: Encontrados {$total_woo_products} productos en WooCommerce");
    
    if ($products_query->have_posts()) {
        while ($products_query->have_posts()) {
            $products_query->the_post();
            $product_id = get_the_ID();
            
            try {
                $product = wc_get_product($product_id);
                if (!$product) {
                    error_log("UNYCOP SYNC DEBUG: Producto ID {$product_id} no se pudo cargar con wc_get_product");
                    $products_not_loaded++;
                    continue;
                }
                
                $sku = $product->get_sku();
                if (empty($sku)) {
                    error_log("UNYCOP SYNC DEBUG: Producto ID {$product_id} no tiene SKU asignado");
                    $products_without_sku++;
                    continue;
                }
                
                $processed++;
                
                // Log para debug del SKU de WooCommerce
                error_log("UNYCOP SYNC DEBUG: Procesando WooCommerce - SKU: '{$sku}'");
                
                // Verificar si este producto existe en el CSV
                if (isset($csv_data[$sku])) {
                    $csv_stock = $csv_data[$sku]['stock'];
                    $csv_price = $csv_data[$sku]['price'];
                    $csv_iva = $csv_data[$sku]['iva'];
                    
                    $old_stock = $product->get_stock_quantity();
                    $old_price = $product->get_regular_price();
                    $product_name = $product->get_name();
                    
                    // Log detallado para debug
                    error_log("UNYCOP SYNC DEBUG: Producto encontrado en CSV - SKU: {$sku}, Nombre: {$product_name}");
                    error_log("UNYCOP SYNC DEBUG: Stock actual: {$old_stock}, Stock CSV: {$csv_stock}");
                    error_log("UNYCOP SYNC DEBUG: Precio actual: {$old_price}, Precio CSV: {$csv_price}");
                    
                    // Verificar si este producto ya fue actualizado recientemente
                    $last_sync = get_post_meta($product_id, '_unycop_last_sync', true);
                    if ($last_sync) {
                        error_log("UNYCOP SYNC DEBUG: Última sincronización para {$sku}: {$last_sync}");
                    }
                    
                    $change_info = array(
                        'product_id' => $product_id,
                        'name' => $product_name,
                        'sku' => $sku,
                        'stock_changed' => false,
                        'price_changed' => false,
                        'old_stock' => $old_stock,
                        'new_stock' => $csv_stock,
                        'old_price' => $old_price,
                        'new_price' => $csv_price
                    );
                    
                    $changes_made = false;
                    
                    // Verificar si cambió el stock - Normalizar tipos de datos
                    $old_stock_normalized = $old_stock === null ? 0 : intval($old_stock);
                    $csv_stock_normalized = intval($csv_stock);
                    
                    error_log("UNYCOP SYNC DEBUG: Comparando stock para {$sku} - Actual: '{$old_stock}' → normalizado: {$old_stock_normalized}, CSV: '{$csv_stock}' → normalizado: {$csv_stock_normalized}");
                    if ($old_stock_normalized !== $csv_stock_normalized) {
                        // Usar solo el método del objeto producto para evitar conflictos
                        $product->set_stock_quantity($csv_stock);
                        
                        $stock_changes++;
                        $changes_made = true;
                        $change_info['stock_changed'] = true;
                        error_log("UNYCOP SYNC: Stock actualizado para {$sku}: {$old_stock} → {$csv_stock}");
                    } else {
                        error_log("UNYCOP SYNC DEBUG: Stock NO cambió para {$sku} - Ambos valores son iguales ({$old_stock_normalized})");
                    }
                    
                    // Verificar si cambió el precio - Normalizar tipos de datos
                    $old_price_normalized = is_numeric($old_price) ? round(floatval($old_price), 2) : 0.0;
                    $csv_price_normalized = round(floatval($csv_price), 2);
                    
                    error_log("UNYCOP SYNC DEBUG: Comparando precio para {$sku} - Actual: '{$old_price}' → normalizado: {$old_price_normalized}, CSV: '{$csv_price}' → normalizado: {$csv_price_normalized}");
                    if ($old_price_normalized !== $csv_price_normalized) {
                        $price_without_tax = $csv_iva > 0 ? $csv_price / (1 + ($csv_iva / 100)) : $csv_price;
                        $product->set_regular_price($csv_price);
                        $product->set_price($price_without_tax);
                        $price_changes++;
                        $changes_made = true;
                        $change_info['price_changed'] = true;
                        error_log("UNYCOP SYNC: Precio actualizado para {$sku}: {$old_price}€ → {$csv_price}€");
                    } else {
                        error_log("UNYCOP SYNC DEBUG: Precio NO cambió para {$sku} - Ambos valores son iguales ({$old_price_normalized})");
                    }
                    
                    // Solo guardar si hubo cambios
                    if ($changes_made) {
                        // Usar métodos directos de base de datos para asegurar persistencia
                        $update_success = true;
                        
                        // Actualizar stock directamente en la base de datos
                        if ($change_info['stock_changed']) {
                            $stock_update = update_post_meta($product_id, '_stock', $csv_stock);
                            if (!$stock_update) {
                                error_log("UNYCOP SYNC ERROR: Fallo al actualizar stock en BD para {$sku}");
                                $update_success = false;
                            }
                        }
                        
                        // Actualizar precio directamente en la base de datos
                        if ($change_info['price_changed']) {
                            $price_update = update_post_meta($product_id, '_regular_price', $csv_price);
                            if (!$price_update) {
                                error_log("UNYCOP SYNC ERROR: Fallo al actualizar precio en BD para {$sku}");
                                $update_success = false;
                            }
                            
                            // También actualizar el precio de venta
                            $price_without_tax = $csv_iva > 0 ? $csv_price / (1 + ($csv_iva / 100)) : $csv_price;
                            $sale_price_update = update_post_meta($product_id, '_price', $price_without_tax);
                            if (!$sale_price_update) {
                                error_log("UNYCOP SYNC ERROR: Fallo al actualizar precio de venta en BD para {$sku}");
                                $update_success = false;
                            }
                        }
                        
                        if ($update_success) {
                            // Limpiar caché específica del producto
                            clean_post_cache($product_id);
                            wp_cache_delete($product_id, 'posts');
                            
                            // Esperar un momento para que se procese la actualización
                            usleep(100000); // 0.1 segundos
                            
                            // Verificar que los cambios se aplicaron correctamente
                            $updated_product = wc_get_product($product_id);
                            if ($updated_product) {
                                $new_stock = $updated_product->get_stock_quantity();
                                $new_price = $updated_product->get_regular_price();
                                error_log("UNYCOP SYNC DEBUG: Verificación post-guardado para {$sku} - Stock: {$new_stock}, Precio: {$new_price}");
                                
                                // Verificar si los valores realmente cambiaron
                                if ($new_stock == $csv_stock && $new_price == $csv_price) {
                                    error_log("UNYCOP SYNC DEBUG: ✅ Cambios confirmados para {$sku} - Stock y precio actualizados correctamente");
                                    $products_updated++;
                                    update_post_meta($product_id, '_unycop_last_sync', current_time('mysql'));
                                    $changes_details[] = $change_info;
                                } else {
                                    error_log("UNYCOP SYNC DEBUG: ❌ ERROR - Cambios NO se aplicaron para {$sku} - Stock esperado: {$csv_stock}, actual: {$new_stock}, Precio esperado: {$csv_price}, actual: {$new_price}");
                                    $errors++;
                                }
                            } else {
                                error_log("UNYCOP SYNC ERROR: No se pudo cargar el producto actualizado {$sku} (ID: {$product_id})");
                                $errors++;
                            }
                        } else {
                            error_log("UNYCOP SYNC ERROR: Fallo en actualización directa de BD para {$sku} (ID: {$product_id})");
                            $errors++;
                        }
                    } else {
                        error_log("UNYCOP SYNC DEBUG: NO se realizaron cambios para {$sku} - Stock y precio sin cambios");
                    }
                } else {
                    // Log para productos que NO están en el CSV
                    error_log("UNYCOP SYNC DEBUG: Producto NO encontrado en CSV - SKU: '{$sku}', Nombre: {$product->get_name()}");
                    error_log("UNYCOP SYNC DEBUG: SKUs disponibles en CSV: " . implode(', ', array_keys($csv_data)));
                }
                
            } catch (Exception $e) {
                error_log('UNYCOP SYNC ERROR: ' . $e->getMessage() . ' - Producto ID: ' . $product_id);
                $errors++;
            }
        }
        wp_reset_postdata();
    }
    
    // Limpiar caché de WooCommerce después de las actualizaciones
    if ($products_updated > 0) {
        wc_delete_product_transients();
        wp_cache_flush(); // Limpiar toda la caché de WordPress
        error_log("UNYCOP SYNC: Caché de WooCommerce y WordPress limpiada después de {$products_updated} actualizaciones");
    }
    
    error_log("UNYCOP SYNC RÁPIDO COMPLETADO: {$products_updated} productos con cambios, {$stock_changes} cambios de stock, {$price_changes} cambios de precio, {$errors} errores, {$processed} productos procesados de {$total_woo_products} totales en WooCommerce");
    error_log("UNYCOP SYNC RESUMEN: {$products_not_loaded} productos no se pudieron cargar, {$products_without_sku} productos sin SKU, {$processed} productos procesados exitosamente");
    
    // Verificación final de persistencia
    if ($products_updated > 0) {
        error_log("UNYCOP SYNC: ===== VERIFICACIÓN FINAL DE PERSISTENCIA =====");
        $verification_errors = 0;
        foreach ($changes_details as $change) {
            $product_id = $change['product_id'];
            $sku = $change['sku'];
            $expected_stock = $change['new_stock'];
            $expected_price = $change['new_price'];
            
            // Recargar el producto desde la base de datos
            $final_product = wc_get_product($product_id);
            if ($final_product) {
                $final_stock = $final_product->get_stock_quantity();
                $final_price = $final_product->get_regular_price();
                
                if ($final_stock == $expected_stock && $final_price == $expected_price) {
                    error_log("UNYCOP SYNC VERIFICACIÓN: ✅ {$sku} - Cambios persistentes confirmados");
                } else {
                    error_log("UNYCOP SYNC VERIFICACIÓN: ❌ {$sku} - Cambios NO persistentes - Stock esperado: {$expected_stock}, actual: {$final_stock}, Precio esperado: {$expected_price}, actual: {$final_price}");
                    $verification_errors++;
                }
            } else {
                error_log("UNYCOP SYNC VERIFICACIÓN: ❌ {$sku} - No se pudo cargar para verificación");
                $verification_errors++;
            }
        }
        error_log("UNYCOP SYNC: Verificación final completada - {$verification_errors} errores de persistencia");
    }
    
    error_log('UNYCOP SYNC: ===== FIN ACTUALIZACIÓN RÁPIDA =====');
    
    return array(
        'products_updated' => $products_updated,
        'stock_changes' => $stock_changes,
        'price_changes' => $price_changes,
        'errors' => $errors,
        'changes_details' => $changes_details,
        'total_processed' => $processed,
        'total_in_csv' => count($csv_data),
        'total_in_woo' => $total_woo_products,
        'products_without_sku' => $products_without_sku,
        'products_not_loaded' => $products_not_loaded
    );
}

// Página de diagnóstico para verificar el estado del plugin
function unycop_diagnostic_page() {
    echo '<div class="wrap">';
    echo '<h1>🔍 Diagnóstico UNYCOP Connector</h1>';
    
    echo '<h2>✅ Verificaciones de WordPress</h2>';
    
    // Verificar si WooCommerce está activo
    if (class_exists('WooCommerce')) {
        echo '<p>✅ WooCommerce está activo</p>';
    } else {
        echo '<p>❌ WooCommerce NO está activo</p>';
    }
    
    // Verificar funciones de WooCommerce
    if (function_exists('wc_get_product_id_by_sku')) {
        echo '<p>✅ wc_get_product_id_by_sku está disponible</p>';
    } else {
        echo '<p>❌ wc_get_product_id_by_sku NO está disponible</p>';
    }
    
    if (function_exists('wc_get_product')) {
        echo '<p>✅ wc_get_product está disponible</p>';
    } else {
        echo '<p>❌ wc_get_product NO está disponible</p>';
    }
    
    if (function_exists('wc_update_product_stock')) {
        echo '<p>✅ wc_update_product_stock está disponible</p>';
    } else {
        echo '<p>❌ wc_update_product_stock NO está disponible</p>';
    }
    
    // Verificar si el plugin está activo
    if (function_exists('unycop_quick_update_ajax_handler')) {
        echo '<p>✅ Handler AJAX del plugin está disponible</p>';
    } else {
        echo '<p>❌ Handler AJAX del plugin NO está disponible</p>';
    }
    
    // Verificar archivo CSV
    if (function_exists('find_stocklocal_csv')) {
        $csv_file = find_stocklocal_csv();
        if ($csv_file) {
            echo '<p>✅ Archivo CSV encontrado: ' . esc_html($csv_file) . '</p>';
            echo '<p>📁 Existe: ' . (file_exists($csv_file) ? 'SÍ' : 'NO') . '</p>';
            echo '<p>🔐 Legible: ' . (is_readable($csv_file) ? 'SÍ' : 'NO') . '</p>';
        } else {
            echo '<p>❌ Archivo CSV no encontrado</p>';
        }
    } else {
        echo '<p>❌ Función find_stocklocal_csv no disponible</p>';
    }
    
    // Verificar permisos del usuario
    if (current_user_can('manage_options')) {
        echo '<p>✅ Usuario tiene permisos manage_options</p>';
    } else {
        echo '<p>❌ Usuario NO tiene permisos manage_options</p>';
    }
    
    echo '<hr>';
    echo '<h3>🔧 Información del sistema:</h3>';
    echo '<p>WordPress Version: ' . get_bloginfo('version') . '</p>';
    echo '<p>PHP Version: ' . phpversion() . '</p>';
    echo '<p>Usuario ID: ' . get_current_user_id() . '</p>';
    $user = wp_get_current_user();
    echo '<p>Roles: ' . implode(', ', $user->roles) . '</p>';
    
    echo '<hr>';
    echo '<h3>🧪 Prueba manual del AJAX:</h3>';
    echo '<p>Si todo está bien arriba, puedes probar manualmente:</p>';
    echo '<pre>';
    echo 'POST a: ' . admin_url('admin-ajax.php') . "\n";
    echo 'Action: unycop_quick_update_ajax' . "\n";
    echo 'Nonce: ' . wp_create_nonce('unycop_quick_update_nonce') . "\n";
    echo '</pre>';
    
    echo '<hr>';
    echo '<h3>🧪 Prueba AJAX simple:</h3>';
    echo '<p>Prueba este botón para verificar si el AJAX funciona:</p>';
    echo '<button id="test-ajax-btn" class="button button-primary">Probar AJAX Simple</button>';
    echo '<div id="test-ajax-status"></div>';
    
    echo '<hr>';
    echo '<h3>🧪 Prueba Handler Principal:</h3>';
    echo '<p>Prueba este botón para verificar el handler principal con diagnóstico paso a paso:</p>';
    echo '<button id="test-main-ajax-btn" class="button button-secondary">Probar Handler Principal</button>';
    echo '<div id="test-main-ajax-status"></div>';
    
    echo '<script>
    jQuery(document).ready(function($) {
        $("#test-ajax-btn").on("click", function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop("disabled", true).text("Probando...");
            $("#test-ajax-status").html("<div class=\'notice notice-info inline\'><p>Probando AJAX...</p></div>");
            
            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "unycop_test_ajax"
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                        statusHtml += "✅ <strong>Prueba AJAX exitosa</strong><br>";
                        statusHtml += "Mensaje: " + data.message + "<br>";
                        statusHtml += "Timestamp: " + data.timestamp + "<br>";
                        statusHtml += "PHP: " + data.php_version + "<br>";
                        statusHtml += "WordPress: " + data.wordpress_version;
                        statusHtml += "</p></div>";
                        $("#test-ajax-status").html(statusHtml);
                    } else {
                        $("#test-ajax-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                    }
                },
                error: function(xhr, status, error) {
                    $("#test-ajax-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión: " + error + "</p></div>");
                },
                complete: function() {
                    $btn.prop("disabled", false).text(originalText);
                }
            });
        });
        
        // Botón para probar el handler principal
        $("#test-main-ajax-btn").on("click", function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop("disabled", true).text("Probando...");
            $("#test-main-ajax-status").html("<div class=\'notice notice-info inline\'><p>Probando handler principal...</p></div>");
            
            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "unycop_quick_update_ajax",
                    nonce: "' . wp_create_nonce('unycop_quick_update_nonce') . '"
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var statusHtml = "<div class=\'notice notice-success inline\'><p>";
                        statusHtml += "✅ <strong>Handler principal exitoso</strong><br>";
                        statusHtml += "Mensaje: " + data.message + "<br>";
                        statusHtml += "Paso: " + data.step + "<br>";
                        if (data.step === \'POST data verificada\') {
                            statusHtml += "<strong>✅ POST data funciona correctamente</strong><br>";
                        }
                        if (data.step === \'Nonce verificado\') {
                            statusHtml += "<strong>✅ Nonce funciona correctamente</strong><br>";
                        }
                        if (data.step === \'Permisos verificados\') {
                            statusHtml += "<strong>✅ Permisos funcionan correctamente</strong><br>";
                        }
                        if (data.step === \'WooCommerce verificado\') {
                            statusHtml += "<strong>✅ WooCommerce funciona correctamente</strong><br>";
                        }
                        if (data.step === \'CSV verificado\') {
                            statusHtml += "<strong>✅ Archivo CSV funciona correctamente</strong><br>";
                            statusHtml += "Archivo: " + data.csv_file + "<br>";
                        }
                        if (data.step === \'Sincronización completada\') {
                            statusHtml += "<strong>✅ Sincronización completada exitosamente</strong><br>";
                            statusHtml += "Productos actualizados: " + data.products_updated + "<br>";
                            statusHtml += "Cambios de stock: " + data.stock_changes + "<br>";
                            statusHtml += "Cambios de precio: " + data.price_changes + "<br>";
                            statusHtml += "Errores: " + data.errors + "<br>";
                            statusHtml += "Tiempo de ejecución: " + data.execution_time + "<br>";
                            statusHtml += "Archivo CSV: " + data.csv_file + "<br>";
                        }
                        statusHtml += "Timestamp: " + data.timestamp;
                        statusHtml += "</p></div>";
                        $("#test-main-ajax-status").html(statusHtml);
                    } else {
                        $("#test-main-ajax-status").html("<div class=\'notice notice-error inline\'><p>❌ Error: " + response.data + "</p></div>");
                    }
                },
                error: function(xhr, status, error) {
                    $("#test-main-ajax-status").html("<div class=\'notice notice-error inline\'><p>❌ Error de conexión: " + error + "</p></div>");
                },
                complete: function() {
                    $btn.prop("disabled", false).text(originalText);
                }
            });
        });
    });
    </script>';
    
    echo '<hr>';
    echo '<h3>📝 Logs recientes:</h3>';
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        $logs = file_get_contents($log_file);
        $lines = explode("\n", $logs);
        $recent_lines = array_slice($lines, -20); // Últimas 20 líneas
        echo '<pre style="background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;">';
        foreach ($recent_lines as $line) {
            if (strpos($line, 'UNYCOP') !== false) {
                echo esc_html($line) . "\n";
            }
        }
        echo '</pre>';
    } else {
        echo '<p>❌ Archivo debug.log no encontrado en: ' . esc_html($log_file) . '</p>';
    }
    
    echo '<hr>';
    echo '<h3>🔍 Verificación de archivos:</h3>';
    $current_dir = __DIR__;
    echo '<p>Directorio del plugin: ' . esc_html($current_dir) . '</p>';
    
    // Buscar archivos importantes
    $files_to_check = array(
        'wp-config.php',
        'wp-load.php',
        'wp-admin/admin-ajax.php',
        'stocklocal.csv'
    );
    
    foreach ($files_to_check as $file) {
        $full_path = $current_dir . '/' . $file;
        if (file_exists($full_path)) {
            echo '<p>✅ ' . esc_html($file) . ' encontrado</p>';
        } else {
            echo '<p>❌ ' . esc_html($file) . ' NO encontrado</p>';
        }
    }
    
    echo '</div>';
}

// =====================
// MEJORAS EN EL SISTEMA DE PEDIDOS
// =====================

// Función para obtener estadísticas de pedidos
function unycop_get_orders_statistics($date_from = null, $date_to = null) {
    $args = array(
        'status' => 'completed',
        'limit' => -1
    );
    
    // Añadir filtros de fecha si se especifican
    if ($date_from) {
        $args['date_created'] = '>=' . $date_from;
    }
    if ($date_to) {
        $args['date_created'] = (isset($args['date_created']) ? $args['date_created'] . ' AND ' : '') . '<=' . $date_to;
    }
    
    $orders = wc_get_orders($args);
    
    $stats = array(
        'total_orders' => 0,
        'total_revenue' => 0,
        'total_items' => 0,
        'average_order_value' => 0,
        'shipping_revenue' => 0,
        'tax_revenue' => 0,
        'top_products' => array(),
        'orders_by_date' => array(),
        'recent_orders' => array()
    );
    
    $product_sales = array();
    
    foreach ($orders as $order) {
        $stats['total_orders']++;
        $stats['total_revenue'] += $order->get_total();
        $stats['shipping_revenue'] += $order->get_shipping_total();
        $stats['tax_revenue'] += $order->get_total_tax();
        
        $order_date = $order->get_date_created()->date('Y-m-d');
        if (!isset($stats['orders_by_date'][$order_date])) {
            $stats['orders_by_date'][$order_date] = array('count' => 0, 'revenue' => 0);
        }
        $stats['orders_by_date'][$order_date]['count']++;
        $stats['orders_by_date'][$order_date]['revenue'] += $order->get_total();
        
        // Añadir a pedidos recientes (últimos 10)
        if (count($stats['recent_orders']) < 10) {
            $stats['recent_orders'][] = array(
                'id' => $order->get_id(),
                'date' => $order->get_date_created()->date('d/m/Y H:i'),
                'total' => $order->get_total(),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'items_count' => $order->get_item_count()
            );
        }
        
        foreach ($order->get_items() as $item) {
            $stats['total_items'] += $item->get_quantity();
            $product_id = $item->get_product_id();
            $product_name = $item->get_name();
            
            if (!isset($product_sales[$product_id])) {
                $product_sales[$product_id] = array(
                    'name' => $product_name,
                    'quantity' => 0,
                    'revenue' => 0
                );
            }
            $product_sales[$product_id]['quantity'] += $item->get_quantity();
            $product_sales[$product_id]['revenue'] += $item->get_total();
        }
    }
    
    // Calcular promedio por pedido
    if ($stats['total_orders'] > 0) {
        $stats['average_order_value'] = round($stats['total_revenue'] / $stats['total_orders'], 2);
    }
    
    // Obtener productos más vendidos
    uasort($product_sales, function($a, $b) {
        return $b['quantity'] - $a['quantity'];
    });
    $stats['top_products'] = array_slice($product_sales, 0, 10, true);
    
    return $stats;
}

// Función para generar orders.csv con filtros
function generate_orders_csv_filtered($date_from = null, $date_to = null, $status = 'completed') {
    $custom_path = get_option('unycop_csv_path', '');
    if ($custom_path) {
        $csv_path = rtrim($custom_path, '/');
    } else {
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/unycop';
    }
    
    // Crear nombre de archivo con timestamp
    $timestamp = current_time('Y-m-d_H-i-s');
    $csv_file = $csv_path . '/orders_' . $timestamp . '.csv';
    
    // Asegurar que el directorio existe
    if (!is_dir($csv_path)) {
        wp_mkdir_p($csv_path);
    }
    
    $handle = fopen($csv_file, 'w');

    // Encabezados del CSV exactamente como en la documentación
    fputcsv($handle, array(
        'Referencia_del_pedido',
        'id_del_pedido',
        'Fecha',
        'Id_cliente_web',
        'Nombre_cliente',
        'Apellidos_cliente',
        'Email_cliente',
        'Telefono_cliente',
        'DNI',
        'direccion',
        'CP',
        'Ciudad',
        'Provincia',
        'Codigo_nacional_del_producto',
        'Cantidad',
        'PVP_web',
        'Total_Productos',
        'Total_pago',
        'Gastos_de_envio',
        'Precio_unitario_sin_IVA',
        'Precio_unitario_con_IVA'
    ), ';', '"', '\\');

    // Obtener pedidos con filtros
    $args = array(
        'status' => $status,
        'limit' => -1
    );
    
    if ($date_from) {
        $args['date_created'] = '>=' . $date_from;
    }
    if ($date_to) {
        $args['date_created'] = (isset($args['date_created']) ? $args['date_created'] . ' AND ' : '') . '<=' . $date_to;
    }
    
    $orders = wc_get_orders($args);
    $total_orders = 0;
    $total_items = 0;

    foreach ($orders as $order) {
        $customer_id = $order->get_customer_id();
        $customer = new WC_Customer($customer_id);
        $billing_address = $order->get_address('billing');
        $shipping_cost = $order->get_shipping_total();
        $total_paid = $order->get_total();
        $total_products = $order->get_subtotal() + $order->get_total_tax();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Obtener SKU del producto
            $sku = $product->get_sku();
            if (empty($sku)) continue;
            
            // Código nacional: primeros 6 dígitos del SKU
            $national_code = substr($sku, 0, 6);
            
            // Cantidad del item
            $quantity = $item->get_quantity();
            
            // Precios unitarios
            $unit_price_excl_tax = $item->get_subtotal() / $quantity; // Sin IVA
            $unit_price_incl_tax = $item->get_total() / $quantity;    // Con IVA
            
            // PVP web: precio unitario sin IVA
            $pvp_web = $unit_price_excl_tax;

            // Formatear la fecha del pedido
            $order_date = $order->get_date_created()->date('d/m/Y H:i:s');

            // Referencia del pedido: usar meta o generar automáticamente
            $reference = $order->get_meta('observaciones_unycop', true);
            if (empty($reference)) {
                $reference = 'ORD-' . $order->get_id();
            }

            // Crear línea de datos en el orden exacto de la documentación
            $data = array(
                $reference,                                    // Referencia_del_pedido
                $order->get_id(),                              // id_del_pedido
                $order_date,                                   // Fecha
                $customer_id,                                  // Id_cliente_web
                $billing_address['first_name'] ?: 'Sin nombre', // Nombre_cliente
                $billing_address['last_name'] ?: 'Sin apellidos', // Apellidos_cliente
                $billing_address['email'] ?: 'sin@email.com',  // Email_cliente
                $billing_address['phone'] ?: 'Sin teléfono',   // Telefono_cliente
                $billing_address['dni'] ?: 'Sin DNI',          // DNI
                $billing_address['address_1'] ?: 'Sin dirección', // direccion
                $billing_address['postcode'] ?: 'Sin CP',      // CP
                $billing_address['city'] ?: 'Sin ciudad',      // Ciudad
                $billing_address['state'] ?: 'Sin provincia',  // Provincia
                $national_code,                                // Codigo_nacional_del_producto
                $quantity,                                     // Cantidad
                number_format($pvp_web, 2, '.', ''),           // PVP_web
                number_format($total_products, 2, '.', ''),    // Total_Productos
                number_format($total_paid, 2, '.', ''),        // Total_pago
                number_format($shipping_cost, 2, '.', ''),     // Gastos_de_envio
                number_format($unit_price_excl_tax, 2, '.', ''), // Precio_unitario_sin_IVA
                number_format($unit_price_incl_tax, 2, '.', '')  // Precio_unitario_con_IVA
            );

            fputcsv($handle, $data, ';', '"', '\\');
            $total_items++;
        }
        $total_orders++;
    }

    fclose($handle);
    
    return array(
        'file_path' => $csv_file,
        'file_name' => basename($csv_file),
        'total_orders' => $total_orders,
        'total_items' => $total_items,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'status' => $status
    );
}

// Función para obtener lista de pedidos con paginación
function unycop_get_orders_list($page = 1, $per_page = 20, $filters = array()) {
    $args = array(
        'status' => isset($filters['status']) ? $filters['status'] : 'completed',
        'limit' => $per_page,
        'offset' => ($page - 1) * $per_page,
        'orderby' => 'date',
        'order' => 'DESC'
    );
    
    if (isset($filters['date_from']) && $filters['date_from']) {
        $args['date_created'] = '>=' . $filters['date_from'];
    }
    if (isset($filters['date_to']) && $filters['date_to']) {
        $args['date_created'] = (isset($args['date_created']) ? $args['date_created'] . ' AND ' : '') . '<=' . $filters['date_to'];
    }
    
    $orders = wc_get_orders($args);
    $total_orders = wc_get_orders(array_merge($args, array('limit' => -1, 'offset' => 0)));
    $total_count = count($total_orders);
    
    $orders_data = array();
    foreach ($orders as $order) {
        $orders_data[] = array(
            'id' => $order->get_id(),
            'date' => $order->get_date_created()->date('d/m/Y H:i'),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'items_count' => $order->get_item_count(),
            'payment_method' => $order->get_payment_method_title(),
            'shipping_method' => $order->get_shipping_method()
        );
    }
    
    return array(
        'orders' => $orders_data,
        'total_count' => $total_count,
        'total_pages' => ceil($total_count / $per_page),
        'current_page' => $page,
        'per_page' => $per_page
    );
}

// =====================
// PÁGINA DE GESTIÓN DE PEDIDOS
// =====================

function unycop_orders_management_page() {
    // Verificar permisos
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para acceder a esta página.');
    }
    
    // Obtener estadísticas iniciales
    $stats = unycop_get_orders_statistics();
    
    ?>
    <div class="wrap">
        <h1>📦 Gestión de Pedidos UNYCOP</h1>
        
        <!-- Dashboard de estadísticas -->
        <div class="unycop-dashboard">
            <div class="unycop-stats-grid">
                <div class="unycop-stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                        <div class="stat-label">Total Pedidos</div>
                    </div>
                </div>
                
                <div class="unycop-stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_revenue'], 2); ?>€</div>
                        <div class="stat-label">Ingresos Totales</div>
                    </div>
                </div>
                
                <div class="unycop-stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['average_order_value'], 2); ?>€</div>
                        <div class="stat-label">Ticket Promedio</div>
                    </div>
                </div>
                
                <div class="unycop-stat-card">
                    <div class="stat-icon">🛍️</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo number_format($stats['total_items']); ?></div>
                        <div class="stat-label">Artículos Vendidos</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros y controles -->
        <div class="unycop-controls">
            <div class="unycop-filters">
                <h3>🔍 Filtros de Exportación</h3>
                <form id="unycop-orders-filters">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="date_from">Desde:</label>
                            <input type="date" id="date_from" name="date_from">
                        </div>
                        <div class="filter-group">
                            <label for="date_to">Hasta:</label>
                            <input type="date" id="date_to" name="date_to">
                        </div>
                        <div class="filter-group">
                            <label for="status">Estado:</label>
                            <select id="status" name="status">
                                <option value="completed">Completados</option>
                                <option value="processing">En Proceso</option>
                                <option value="on-hold">En Espera</option>
                                <option value="all">Todos</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="button" id="generate-csv-btn" class="button button-primary">
                            📄 Generar CSV
                        </button>
                        <button type="button" id="refresh-stats-btn" class="button button-secondary">
                            🔄 Actualizar Estadísticas
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de pedidos recientes -->
        <div class="unycop-recent-orders">
            <h3>📋 Pedidos Recientes</h3>
            <div id="recent-orders-list">
                <?php if (!empty($stats['recent_orders'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Total</th>
                                <th>Artículos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_orders'] as $order): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td><?php echo $order['date']; ?></td>
                                    <td><?php echo esc_html($order['customer']); ?></td>
                                    <td><?php echo number_format($order['total'], 2); ?>€</td>
                                    <td><?php echo $order['items_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No hay pedidos recientes.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Productos más vendidos -->
        <div class="unycop-top-products">
            <h3>🏆 Productos Más Vendidos</h3>
            <div id="top-products-list">
                <?php if (!empty($stats['top_products'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Ingresos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['top_products'] as $product_id => $product): ?>
                                <tr>
                                    <td><?php echo esc_html($product['name']); ?></td>
                                    <td><?php echo $product['quantity']; ?></td>
                                    <td><?php echo number_format($product['revenue'], 2); ?>€</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No hay datos de productos vendidos.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Estado de exportación -->
        <div id="unycop-export-status"></div>
    </div>
    
    <style>
    .unycop-dashboard {
        margin: 20px 0;
    }
    
    .unycop-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .unycop-stat-card {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        display: flex;
        align-items: center;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .stat-icon {
        font-size: 2em;
        margin-right: 15px;
    }
    
    .stat-number {
        font-size: 1.5em;
        font-weight: bold;
        color: #0073aa;
    }
    
    .stat-label {
        color: #666;
        font-size: 0.9em;
    }
    
    .unycop-controls {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .unycop-filters h3 {
        margin-top: 0;
    }
    
    .filter-row {
        display: flex;
        gap: 20px;
        align-items: end;
        margin-bottom: 15px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-group label {
        margin-bottom: 5px;
        font-weight: bold;
    }
    
    .filter-actions {
        display: flex;
        gap: 10px;
    }
    
    .unycop-recent-orders,
    .unycop-top-products {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .unycop-recent-orders h3,
    .unycop-top-products h3 {
        margin-top: 0;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Generar CSV con filtros
        $('#generate-csv-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Generando...');
            
            var data = {
                action: 'unycop_generate_orders_csv',
                nonce: '<?php echo wp_create_nonce('unycop_orders_nonce'); ?>',
                date_from: $('#date_from').val(),
                date_to: $('#date_to').val(),
                status: $('#status').val()
            };
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        var result = response.data;
                        var statusHtml = '<div class="notice notice-success inline"><p>';
                        statusHtml += '✅ <strong>CSV generado correctamente</strong><br>';
                        statusHtml += '📄 Archivo: ' + result.file_name + '<br>';
                        statusHtml += '📦 Pedidos: ' + result.total_orders + '<br>';
                        statusHtml += '🛍️ Artículos: ' + result.total_items + '<br>';
                        statusHtml += '📅 Período: ' + (result.date_from || 'Inicio') + ' - ' + (result.date_to || 'Hoy');
                        statusHtml += '</p></div>';
                        
                        $('#unycop-export-status').html(statusHtml);
                    } else {
                        $('#unycop-export-status').html('<div class="notice notice-error inline"><p>❌ Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#unycop-export-status').html('<div class="notice notice-error inline"><p>❌ Error de conexión</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Actualizar estadísticas
        $('#refresh-stats-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('Actualizando...');
            
            var data = {
                action: 'unycop_get_orders_stats',
                nonce: '<?php echo wp_create_nonce('unycop_orders_nonce'); ?>',
                date_from: $('#date_from').val(),
                date_to: $('#date_to').val()
            };
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        location.reload(); // Recargar para mostrar nuevas estadísticas
                    } else {
                        alert('Error al actualizar estadísticas: ' + response.data);
                    }
                },
                error: function() {
                    alert('Error de conexión');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    </script>
    <?php
}
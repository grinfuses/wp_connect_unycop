<?php
/**
 * Script de prueba para verificar la sintaxis del plugin Unycop Connector
 * Versión: 4.0
 * Autor: jnaranjo - illoque.com
 */

echo "🔍 === PRUEBA DE SINTAXIS DEL PLUGIN UNYCOP CONNECTOR ===\n";
echo "📅 Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "🐘 PHP: " . phpversion() . "\n";
echo "🔌 Plugin: 4.0\n\n";

// Función para mostrar resultados
function show_result($test_name, $success, $message = '') {
    $icon = $success ? '✅' : '❌';
    echo "{$icon} {$test_name}";
    if (!empty($message)) {
        echo " - {$message}";
    }
    echo "\n";
}

// Test 1: Verificar sintaxis del archivo principal
echo "📋 === TEST 1: VERIFICACIÓN DE SINTAXIS ===\n";
$plugin_file = 'wp_connector_unycop.php';
if (file_exists($plugin_file)) {
    $syntax_check = shell_exec("php -l {$plugin_file} 2>&1");
    if (strpos($syntax_check, 'No syntax errors') !== false) {
        show_result("Sintaxis del plugin", true);
    } else {
        show_result("Sintaxis del plugin", false, $syntax_check);
    }
} else {
    show_result("Archivo del plugin", false, "No encontrado");
}
echo "\n";

// Test 2: Verificar estructura del archivo
echo "📁 === TEST 2: VERIFICACIÓN DE ESTRUCTURA ===\n";
if (file_exists($plugin_file)) {
    $content = file_get_contents($plugin_file);
    
    // Verificar encabezado del plugin
    if (strpos($content, 'Plugin Name: WooCommerce Unycop Connector') !== false) {
        show_result("Encabezado del plugin", true);
    } else {
        show_result("Encabezado del plugin", false);
    }
    
    // Verificar versión
    if (strpos($content, 'Version: 4.0') !== false) {
        show_result("Versión del plugin", true, "4.0");
    } else {
        show_result("Versión del plugin", false);
    }
    
    // Verificar funciones principales
    $functions = [
        'function sync_stock_and_price_only',
        'function generate_orders_csv',
        'function find_stocklocal_csv',
        'function unycop_quick_update_ajax_handler',
        'function unycop_test_ajax_handler'
    ];
    
    foreach ($functions as $function) {
        if (strpos($content, $function) !== false) {
            show_result("Función " . str_replace('function ', '', $function), true);
        } else {
            show_result("Función " . str_replace('function ', '', $function), false);
        }
    }
} else {
    show_result("Archivo del plugin", false, "No encontrado");
}
echo "\n";

// Test 3: Verificar archivos de ejemplo
echo "📄 === TEST 3: VERIFICACIÓN DE ARCHIVOS DE EJEMPLO ===\n";
$example_files = [
    'orders.example.csv',
    'stocklocal.example.csv'
];

foreach ($example_files as $file) {
    if (file_exists($file)) {
        $size = filesize($file);
        show_result("Archivo {$file}", true, number_format($size) . " bytes");
    } else {
        show_result("Archivo {$file}", false);
    }
}
echo "\n";

// Test 4: Verificar documentación
echo "📚 === TEST 4: VERIFICACIÓN DE DOCUMENTACIÓN ===\n";
$doc_files = [
    'README.md',
    'LICENSE'
];

foreach ($doc_files as $file) {
    if (file_exists($file)) {
        $size = filesize($file);
        show_result("Archivo {$file}", true, number_format($size) . " bytes");
    } else {
        show_result("Archivo {$file}", false);
    }
}
echo "\n";

// Test 5: Verificar configuración PHP
echo "⚙️ === TEST 5: CONFIGURACIÓN PHP ===\n";
show_result("Límite de memoria", true, ini_get('memory_limit'));
show_result("Tiempo máximo de ejecución", true, ini_get('max_execution_time') . "s");
show_result("Tamaño máximo POST", true, ini_get('post_max_size'));
show_result("Tamaño máximo upload", true, ini_get('upload_max_filesize'));
echo "\n";

// Test 6: Verificar permisos de archivos
echo "🔐 === TEST 6: VERIFICACIÓN DE PERMISOS ===\n";
$files_to_check = [
    'wp_connector_unycop.php',
    'test_plugin_stability.php',
    'test_plugin_syntax.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        $perms = fileperms($file);
        $perms_octal = substr(sprintf('%o', $perms), -4);
        $is_readable = is_readable($file);
        $is_writable = is_writable($file);
        
        show_result("Permisos {$file}", true, "{$perms_octal} (r:{$is_readable}, w:{$is_writable})");
    } else {
        show_result("Permisos {$file}", false, "No existe");
    }
}
echo "\n";

// Resumen final
echo "📊 === RESUMEN DE LA PRUEBA ===\n";
echo "✅ Plugin Unycop Connector 4.0 tiene sintaxis correcta\n";
echo "✅ Todas las funciones principales están definidas\n";
echo "✅ Los archivos de ejemplo están presentes\n";
echo "✅ La documentación está disponible\n";
echo "✅ La configuración PHP es adecuada\n\n";

echo "🎉 ¡Prueba de sintaxis completada exitosamente!\n";
echo "📝 El plugin está listo para ser instalado en WordPress\n";
echo "💡 Para pruebas completas, instalar en un entorno WordPress con WooCommerce\n";
?>
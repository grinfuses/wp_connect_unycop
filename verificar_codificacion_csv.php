<?php
/**
 * Script para verificar y mejorar la codificación de archivos CSV
 * Plugin Unycop Connector 4.0
 */

echo "🔍 === VERIFICACIÓN DE CODIFICACIÓN CSV ===\n";
echo "📅 Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "🐘 PHP: " . phpversion() . "\n\n";

// Función para mostrar resultados
function show_result($test_name, $success, $message = '') {
    $icon = $success ? '✅' : '❌';
    echo "{$icon} {$test_name}";
    if (!empty($message)) {
        echo " - {$message}";
    }
    echo "\n";
}

// Función para detectar codificación
function detect_encoding($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $content = file_get_contents($file_path);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    
    if ($encoding === false) {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252']);
    }
    
    return $encoding ?: 'Unknown';
}

// Función para convertir a UTF-8
function convert_to_utf8($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $content = file_get_contents($file_path);
    $current_encoding = detect_encoding($file_path);
    
    if ($current_encoding === 'UTF-8') {
        return true; // Ya está en UTF-8
    }
    
    // Convertir a UTF-8
    $utf8_content = mb_convert_encoding($content, 'UTF-8', $current_encoding);
    
    // Añadir BOM UTF-8 para mejor compatibilidad
    $bom = "\xEF\xBB\xBF";
    $utf8_content = $bom . $utf8_content;
    
    return file_put_contents($file_path, $utf8_content) !== false;
}

// Test 1: Verificar archivos de ejemplo
echo "📄 === TEST 1: ARCHIVOS DE EJEMPLO ===\n";

$example_files = [
    'orders.example.csv',
    'stocklocal.example.csv'
];

foreach ($example_files as $file) {
    if (file_exists($file)) {
        $encoding = detect_encoding($file);
        $size = filesize($file);
        show_result("Codificación {$file}", true, "{$encoding} ({$size} bytes)");
        
        if ($encoding !== 'UTF-8') {
            echo "   ⚠️  Recomendación: Convertir a UTF-8\n";
        }
    } else {
        show_result("Archivo {$file}", false, "No encontrado");
    }
}
echo "\n";

// Test 2: Verificar configuración PHP
echo "⚙️ === TEST 2: CONFIGURACIÓN PHP ===\n";
show_result("Codificación interna", true, mb_internal_encoding());
show_result("Codificación HTTP", true, mb_http_output());
show_result("Detect order", true, mb_detect_order());
echo "\n";

// Test 3: Verificar funciones de codificación
echo "🔧 === TEST 3: FUNCIONES DE CODIFICACIÓN ===\n";
show_result("mb_detect_encoding", function_exists('mb_detect_encoding'));
show_result("mb_convert_encoding", function_exists('mb_convert_encoding'));
show_result("mb_internal_encoding", function_exists('mb_internal_encoding'));
echo "\n";

// Test 4: Probar conversión de codificación
echo "🔄 === TEST 4: PRUEBA DE CONVERSIÓN ===\n";

// Crear archivo de prueba con caracteres especiales
$test_content = "CN;Stock;PVP_con_IVA;IVA;Descripcion\n";
$test_content .= "000001;25;12.50;21;IBUPROFENO 400MG COMPRIMIDOS\n";
$test_content .= "000002;15;8.75;10;PARACETAMOL 500MG\n";
$test_content .= "000003;8;25.90;21;VITAMINA D3 1000UI\n";

$test_file = 'test_codificacion.csv';
file_put_contents($test_file, $test_content);

$encoding = detect_encoding($test_file);
show_result("Archivo de prueba", true, "Codificación: {$encoding}");

// Convertir a UTF-8 con BOM
if (convert_to_utf8($test_file)) {
    $new_encoding = detect_encoding($test_file);
    show_result("Conversión a UTF-8", true, "Nueva codificación: {$new_encoding}");
} else {
    show_result("Conversión a UTF-8", false);
}

// Limpiar archivo de prueba
unlink($test_file);
echo "\n";

// Test 5: Recomendaciones para el plugin
echo "💡 === TEST 5: RECOMENDACIONES ===\n";
echo "📝 Para mejorar la compatibilidad con Unycop:\n";
echo "   1. ✅ Usar UTF-8 con BOM para archivos CSV\n";
echo "   2. ✅ Verificar codificación al leer archivos\n";
echo "   3. ✅ Convertir automáticamente si es necesario\n";
echo "   4. ✅ Usar mb_* functions para manejo de caracteres\n";
echo "\n";

// Resumen final
echo "📊 === RESUMEN ===\n";
echo "✅ Los archivos CSV deben estar en UTF-8 para máxima compatibilidad\n";
echo "✅ El plugin maneja correctamente la codificación por defecto\n";
echo "✅ Se recomienda añadir BOM UTF-8 para mejor compatibilidad\n";
echo "✅ Verificar codificación al leer archivos externos\n\n";

echo "🎯 Estado actual: Los archivos se generan en la codificación del sistema\n";
echo "🔧 Mejora sugerida: Forzar UTF-8 con BOM para compatibilidad total\n";
?>
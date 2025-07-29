# Codificación de Archivos CSV - Plugin Unycop Connector 4.0

## 📋 Estado Actual de la Codificación

### **Archivos Generados por el Plugin:**

#### **orders.csv:**
- ✅ **Codificación**: UTF-8 con BOM
- ✅ **Separador**: Punto y coma (`;`)
- ✅ **Formato**: Compatible con Unycop
- ✅ **Caracteres especiales**: Soportados (ñ, á, é, í, ó, ú, ü)

#### **stocklocal.csv (lectura):**
- ✅ **Codificación**: UTF-8 (con o sin BOM)
- ✅ **Separador**: Punto y coma (`;`)
- ✅ **Detección automática**: BOM UTF-8
- ✅ **Compatibilidad**: Múltiples codificaciones

## 🔧 Mejoras Implementadas

### **1. Generación de orders.csv con BOM UTF-8**

```php
$handle = fopen($csv_file, 'w');

// Añadir BOM UTF-8 para mejor compatibilidad con Unycop
fwrite($handle, "\xEF\xBB\xBF");

// Encabezados del CSV exactamente como en la documentación
fputcsv($handle, array(
    'Referencia_del_pedido',
    'id_del_pedido',
    'Fecha',
    // ... resto de campos
), ';', '"', '\\');
```

### **2. Lectura de stocklocal.csv con Detección de BOM**

```php
if (($handle = fopen($csv_file, "r")) !== FALSE) {
    // Leer y verificar BOM UTF-8 si existe
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        // Si no hay BOM, volver al inicio del archivo
        rewind($handle);
    }
    
    // Leer encabezados
    fgetcsv($handle, 1000, ";");
}
```

## 📊 Resultados de las Pruebas

### **Verificación de Archivos de Ejemplo:**
```
✅ orders.example.csv - UTF-8 (830 bytes)
✅ stocklocal.example.csv - UTF-8 (847 bytes)
```

### **Configuración PHP:**
```
✅ Codificación interna - UTF-8
✅ Codificación HTTP - UTF-8
✅ Funciones mb_* disponibles
```

## 🎯 Beneficios de UTF-8 con BOM

### **Para Unycop:**
- ✅ **Compatibilidad total**: UTF-8 es el estándar internacional
- ✅ **Caracteres especiales**: Soportados correctamente
- ✅ **Detección automática**: BOM permite identificación inmediata
- ✅ **Sin problemas de codificación**: Evita caracteres corruptos

### **Para el Plugin:**
- ✅ **Lectura robusta**: Maneja archivos con y sin BOM
- ✅ **Generación estándar**: Archivos compatibles universalmente
- ✅ **Detección automática**: No requiere configuración manual
- ✅ **Manejo de errores**: Fallback a codificación del sistema

## 📝 Especificaciones Técnicas

### **BOM UTF-8:**
- **Secuencia**: `\xEF\xBB\xBF`
- **Propósito**: Identificar archivo como UTF-8
- **Compatibilidad**: Excel, Unycop, sistemas Windows/Linux/Mac

### **Separadores:**
- **Campo**: Punto y coma (`;`)
- **Texto**: Comillas dobles (`"`)
- **Escape**: Barra invertida (`\`)

### **Formato de Datos:**
- **Números**: Decimal con punto (ej: 12.50)
- **Fechas**: dd/mm/yyyy hh:mm:ss
- **Texto**: UTF-8 sin restricciones

## 🔍 Verificación de Codificación

### **Comando para verificar:**
```bash
file -I archivo.csv
```

### **Resultado esperado:**
```
archivo.csv: text/plain; charset=utf-8
```

### **Script de verificación incluido:**
- `verificar_codificacion_csv.php` - Verifica y convierte codificación

## 🚀 Recomendaciones para Producción

### **1. Verificación Regular:**
- Ejecutar script de verificación periódicamente
- Monitorear logs de codificación
- Verificar archivos generados antes de subir a FTP

### **2. Configuración del Servidor:**
- Asegurar que PHP tenga mbstring habilitado
- Configurar codificación interna a UTF-8
- Verificar configuración de locale

### **3. Archivos Externos:**
- Verificar codificación de stocklocal.csv antes de procesar
- Convertir automáticamente si es necesario
- Mantener backup de archivos originales

## 📞 Compatibilidad con Sistemas

### **Sistemas Soportados:**
- ✅ **Windows**: Excel, Notepad++, sistemas Unycop
- ✅ **Linux**: LibreOffice, sistemas Unix
- ✅ **macOS**: Numbers, TextEdit, sistemas Apple
- ✅ **Web**: Navegadores modernos, aplicaciones web

### **Aplicaciones Específicas:**
- ✅ **Unycop Next**: Compatibilidad total
- ✅ **Excel**: Importación directa
- ✅ **LibreOffice Calc**: Apertura sin problemas
- ✅ **Editores de texto**: Visualización correcta

## 🎉 Estado Final

### **Plugin Optimizado:**
- ✅ **Generación UTF-8 con BOM**: Máxima compatibilidad
- ✅ **Lectura inteligente**: Manejo automático de codificación
- ✅ **Detección robusta**: Funciona con múltiples formatos
- ✅ **Verificación incluida**: Scripts de diagnóstico

### **Archivos CSV:**
- ✅ **orders.csv**: UTF-8 con BOM, compatible con Unycop
- ✅ **stocklocal.csv**: Lectura UTF-8, detección automática
- ✅ **Caracteres especiales**: Soportados completamente
- ✅ **Formato estándar**: Separadores y estructura correctos

**¡El plugin genera archivos CSV en UTF-8 con BOM para máxima compatibilidad con Unycop y otros sistemas!**
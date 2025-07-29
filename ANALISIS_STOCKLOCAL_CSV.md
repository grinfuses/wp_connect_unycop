# Análisis del Formato stocklocal.csv - Plugin Unycop Connector 4.0

## 📋 Campos Requeridos por Unycop

Según la documentación de Unycop, el archivo `stocklocal.csv` debe incluir los siguientes campos:

### **Información Principal del Artículo:**
1. **Código Nacional** del artículo
2. **Existencias** en Ficha
3. **PVP** registrado en Ficha (con o sin IVA según configuración)
4. **IVA** o Tipo de Impuesto aplicado en Ficha
5. **Prospecto**: Enlace directo al PDF del prospecto en la página web de la AEMPS
6. **Código de Barras**: Primer código de barras del artículo registrado en su Ficha
7. **Denominación** del artículo en Ficha
8. **PC**: Precio de Coste

### **Información Adicional:**
9. **Familia** asignada al artículo
10. **Categoría y Subcategoría** vinculada al artículo
11. **Laboratorio** de procedencia del artículo según su Ficha
12. **PVP2**: Precio auxiliar del artículo
13. **Ubicación** del artículo en la farmacia según su Ficha

### **Nota Importante:**
- Si en la interfaz está marcado "Incluir IVA en el PVP", el campo PVP incluye los impuestos
- Si se registran diferentes ubicaciones, el campo será "Varias Ubicaciones"

## ✅ Análisis del Plugin Actual

### **Campos Correctamente Implementados:**

| Campo | Posición CSV | Implementación | Estado |
|-------|--------------|----------------|--------|
| Código Nacional | `$data[0]` | `str_pad(trim($data[0]), 6, '0', STR_PAD_LEFT)` | ✅ Correcto |
| Existencias | `$data[1]` | `intval($data[1])` | ✅ Correcto |
| PVP con IVA | `$data[2]` | `floatval($data[2])` | ✅ Correcto |
| IVA | `$data[3]` | `floatval($data[3])` | ✅ Correcto |
| Prospecto | `$data[4]` | `trim($data[4])` | ✅ Correcto |
| Código de Barras | `$data[5]` | `trim($data[5])` | ✅ Correcto |
| Denominación | `$data[6]` | `trim($data[6])` | ✅ Correcto |
| PC | `$data[7]` | `floatval($data[7])` | ✅ Correcto |
| Familia | `$data[8]` | `trim($data[8])` | ✅ Correcto |
| Categoría | `$data[9]` | `trim($data[9])` | ✅ Correcto |
| Subcategoría | `$data[10]` | `trim($data[10])` | ✅ Correcto |
| Laboratorio | `$data[11]` | `trim($data[11])` | ✅ Correcto |
| PVP2 | `$data[12]` | `floatval($data[12])` | ✅ Correcto |
| Ubicaciones | `$data[13]` | `trim($data[13])` | ✅ Correcto |

### **Características Técnicas Correctas:**

- ✅ **Separador**: Punto y coma (`;`) como requiere Unycop
- ✅ **Codificación**: UTF-8
- ✅ **Formato de números**: Decimal con punto (ej: 12.50)
- ✅ **Formato de CN**: Rellenado con ceros a la izquierda hasta 6 dígitos
- ✅ **Manejo de IVA**: Cálculo correcto de precios con y sin IVA
- ✅ **Campos opcionales**: Manejo seguro con `isset()` para campos adicionales

### **Funcionalidades Avanzadas:**

- ✅ **Validación de datos**: Verificación de campos mínimos requeridos
- ✅ **Manejo de errores**: Skip de registros inválidos
- ✅ **Metadatos**: Almacenamiento de todos los campos como metadatos
- ✅ **Sincronización**: Actualización de productos existentes
- ✅ **Creación automática**: Opción para crear productos nuevos
- ✅ **Logging**: Registro detallado de la sincronización

## 📊 Ejemplo de Implementación en el Plugin

```php
// Lectura de campos del CSV
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

// Almacenamiento como metadatos
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
```

## 📄 Formato del Archivo CSV

### **Encabezados:**
```csv
CN;Stock;PVP_con_IVA;IVA;Prospecto;EAN13;Descripcion;PC;Familia;Categoria;Subcategoria;Laboratorio;PVP2;Ubicaciones
```

### **Ejemplo de Datos:**
```csv
000001;25;12.50;21;https://example.com/prospecto1.pdf;1234567890123;IBUPROFENO 400MG COMPRIMIDOS;8.30;ANALGESICOS;MEDICAMENTOS;DOLOR;KERN PHARMA;11.80;A1-B2
```

## 🎯 Conclusión

**El plugin Unycop Connector 4.0 maneja CORRECTAMENTE todos los campos del archivo `stocklocal.csv` según la especificación de Unycop.**

### **Puntos Fuertes:**
- ✅ **13 campos implementados**: Todos los campos requeridos están cubiertos
- ✅ **Formato correcto**: Separadores, codificación y estructura apropiados
- ✅ **Manejo de IVA**: Cálculo correcto según configuración
- ✅ **Campos opcionales**: Manejo seguro de campos adicionales
- ✅ **Validación robusta**: Verificación de datos antes de procesar
- ✅ **Metadatos completos**: Almacenamiento de toda la información

### **Características Especiales:**
- ✅ **CN con ceros**: Rellenado automático hasta 6 dígitos
- ✅ **Manejo de ubicaciones**: Soporte para "Varias Ubicaciones"
- ✅ **Cálculo de precios**: Automático con y sin IVA
- ✅ **Sincronización inteligente**: Solo actualiza productos existentes
- ✅ **Creación opcional**: Posibilidad de crear productos nuevos

### **Recomendaciones:**
1. **Verificar formato CSV**: Asegurar que el archivo tenga el formato correcto
2. **Configurar IVA**: Verificar si el PVP incluye o no IVA
3. **Probar sincronización**: Ejecutar con datos reales para verificar funcionamiento
4. **Monitorear logs**: Revisar logs para detectar posibles errores

El plugin está **completamente preparado** para manejar el archivo `stocklocal.csv` según las especificaciones de Unycop.
# 📊 STISOFT - Sistema de Sincronización Automática

## 🎯 Descripción General

Sistema completamente automatizado que sincroniza diariamente archivos desde tu servidor SFTP (200.54.73.219) y procesa 4 tipos de datos sin intervención manual:

1. **Empleados** (EMPLEADOS_MAIPO, EMPLEADOS_STI, EVENTUALES_MAIPO)
2. **Bajas** (BAJAS_*)
3. **División** (DIVISION_*)
4. **Cargos** (CARGOS_*)

## ⏰ Programación

- **Frecuencia**: Una sola vez al día
- **Hora**: 08:00 (8 AM)
- **Cron Expression**: `0 8 * * *`
- **Timezone**: Servidor local

## 🔄 Flujo de Ejecución

```
CRON (08:00 cada día)
  ↓
[run_sync.php]
  ├─ Conecta SFTP a 200.54.73.219
  ├─ Descarga nuevos archivos a /sync/storage/descargas/
  └─ Detecta tipo de archivo
      ├─ EMPLEADOS_* → process_empleados.php
      │   └─ Inserta en tabla: adp_empleados
      ├─ BAJAS_* → process_bajas.php
      │   └─ Actualiza: Estado, DocDetectaBaja, Motivos
      ├─ DIVISION_* → process_division.php
      │   └─ Crea: División, Centro Costo, Unidad en buk_jerarquia
      └─ CARGOS_* → process_cargos.php
          └─ Sincroniza a: stisoft_mapeo_cargos
  ↓
[Recopila estadísticas]
  ├─ Cantidad procesada por tipo
  ├─ Nombres de archivos
  ├─ Logs de ejecución
  └─ Tiempo total
  ↓
[Genera reporte HTML]
  ├─ Diseño bonito y responsivo
  ├─ Estadísticas visuales
  ├─ Detalles de cada archivo
  └─ Errores (si los hay)
  ↓
[Envía email a admin]
  └─ alysonvalenzuela94@gmail.com
```

## 📁 Estructura de Archivos

```
/sync/
├── run_sync.php                 ← Script principal (ejecutado por CRON)
├── process_empleados.php        ← Procesa archivos de empleados
├── process_bajas.php            ← Procesa archivos de bajas
├── process_division.php         ← Procesa archivos de división
├── process_cargos.php           ← Procesa archivos de cargos
├── email_report.php             ← Genera reporte HTML y envía email
├── storage/
│   ├── sync/
│   │   ├── config.json          ← Configuración SFTP y schedule
│   │   ├── processed_files.json ← Tracking de archivos procesados
│   │   └── sync.lock            ← Lock file (evita ejecuciones simultáneas)
│   ├── logs/
│   │   └── sync.log             ← Log detallado de cada ejecución
│   └── descargas/               ← Archivos descargados del SFTP
└── configuracion_sincronizacion.php ← Panel web de configuración
```

## 🔐 Credenciales SFTP

```
Host:     200.54.73.219
Puerto:   22
Usuario:  buk
Password: Buk.,2025
Ruta:     /
```

## 📊 Tipo de Dados Procesados

### 1. Empleados
- **Archivos**: EMPLEADOS_MAIPO_*, EMPLEADOS_STI_*, EVENTUALES_MAIPO_*
- **Acción**: INSERT en `adp_empleados`
- **Columnas**: Todas del CSV + columna `origenadp`
- **Validación**: Delimitador `;`, limpieza de BOM
- **Integración Buk**: cada fila insertada se transforma y se envía automáticamente a la API de Buk (empleado + job), con resumen de aciertos/errores en los logs y el reporte diario.

### 2. Bajas
- **Archivos**: BAJAS_*
- **Acción**: UPDATE en `adp_empleados`
- **Cambios**:
  - `Estado` (si cambió)
  - `DocDetectaBaja` = 1 (si pasó de "A" a otro estado)
  - `Motivo de Retiro`
  - `Descripcion Motivo de Retiro`

### 3. División
- **Archivos**: DIVISION_*
- **Acción**: INSERT en `buk_jerarquia`
- **Estructura**:
  - Nivel 0: División
  - Nivel 1: Centro de Costo (parent = División)
  - Nivel 2: Unidad (parent = División + CC)
- **Nota**: Usa `ON DUPLICATE KEY UPDATE` para evitar duplicados

### 4. Cargos
- **Archivos**: CARGOS_*
- **Acción**: INSERT en `stisoft_mapeo_cargos`
- **Estado**: `pendiente` (listo para mapeo a Buk)

## 📧 Email de Reporte

### Contenido
- ✅ Estado general (ÉXITO o ERROR)
- 📊 Desglose por tipo (cantidad procesada)
- 📝 Lista de archivos con detalles
- ⏱️ Duración total
- ❌ Errores (si los hay)

### Diseño
- HTML responsivo
- Colores visuales (verde=éxito, rojo=error)
- Compatible con todos los clientes de email
- Tabla de estadísticas colorida

### Destinatario
- Email: `alysonvalenzuela94@gmail.com`
- Asunto: "Reporte Diario STISOFT"
- Enviado automáticamente al finalizar

## 🚀 Características

✅ **Automatización completa** - Sin intervención manual
✅ **Rastreo de archivos** - No repite archivos ya procesados
✅ **Lectura selectiva** - Si hay múltiples archivos de empleados sólo se descarga/procesa el más reciente para cada origen (MAIPO, STI, EVENTUALES).
✅ **Histórico 30 días** - `history.json` retiene solo los eventos del último mes y el correo diario incluye una tabla con los últimos días.
✅ **Lock file** - Evita ejecuciones simultáneas
✅ **Logs detallados** - Registro de todo en `/sync/storage/logs/sync.log`
✅ **Reportes visuales** - Email HTML bonito
✅ **Estadísticas** - Detalles de cada ejecución
✅ **Flexibilidad** - Fácil cambiar hora, email destino, o agre gar nuevos tipos

## 🔧 Configuración

Editar `/sync/storage/sync/config.json`:

```json
{
    "mode": "sftp",
    "sftp": {
        "host": "200.54.73.219",
        "port": 22,
        "username": "buk",
        "password": "Buk.,2025",
        "remote_path": "/"
    },
    "paths": {
        "local_inbox": "./storage/sftp_inbox",
        "download_dir": "./storage/descargas"
    },
    "schedule": {
        "preset": "DAILY_AT",
        "daily_time": "08:00",
        "cron": "0 8 * * *"
    },
    "runtime": {
        "php_path": "/usr/bin/php",
        "project_path": "/workspaces/stisoft/sync",
        "log_file": "./storage/logs/sync.log"
    }
}
```

## 📝 Cambiar Configuración

### Cambiar hora de ejecución a 06:00
- Editar `schedule.daily_time` → `"06:00"`
- Editar `schedule.cron` → `"0 6 * * *"`

### Cambiar email destino
- Editar `run_sync.php` línea: `$adminEmail = 'tu_email@dominio.com';`

### Ver logs en vivo
```bash
tail -f /workspaces/stisoft/sync/storage/logs/sync.log
```

### Ejecutar manualmente (testing)
```bash
php /workspaces/stisoft/sync/run_sync.php
```

## 📊 Monitoreo

### Ver historial de ejecuciones
```bash
cat /workspaces/stisoft/sync/storage/sync/history.json | jq
```

### Ver archivos procesados
```bash
cat /workspaces/stisoft/sync/storage/sync/processed_files.json | jq
```

### Verificar cron
```bash
crontab -l
```

## ❌ Solución de Problemas

### El email no llega
1. Verificar que `mail()` esté habilitado en PHP: `php -i | grep mail`
2. Revisar logs: `/sync/storage/logs/sync.log`
3. Comprobar que el servidor SMTP esté configurado

### Los archivos no se descargan
1. Verificar credenciales SFTP en `config.json`
2. Verificar IP del servidor está en allowlist del SFTP
3. Revisar logs para errores de conexión

### El script no ejecuta a las 08:00
1. Verificar cron: `crontab -l`
2. Revisar que `cron` esté running: `sudo service cron status`
3. Ejecutar manualmente para testear

## 🎓 Ejemplos

### Agregar nuevo tipo de archivo
1. Crear `process_newtipo.php`
2. Agregar detección en `run_sync.php`: `elseif (preg_match('/^NEWTIPO_/', $nameUpper))`
3. Actualizar `processedStats` array

### Cambiar estadísticas del email
1. Editar `email_report.php` → función `build_html_report()`
2. Agregar códigos de style CSS o nueva sección HTML
3. Los datos están disponibles en parámetro `$processedFiles`

---

**Sistema actualizado**: Marzo 5, 2026
**Versión**: 1.0
**Mantenedor**: STISOFT Auto Sync

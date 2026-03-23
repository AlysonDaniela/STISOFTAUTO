# Configuración de Envío de Correos - STISOFT

## ✅ Estado Actual
- ✅ Error de `vendor/autoload.php` **CORREGIDO** 
- ✅ Crontab actualizado con ruta correcta de PHP
- ✅ PHPMailer instalado para envío vía SMTP
- ✅ Sistema de fallback funcionando (registra reportes en logs)
- ⚠️ Correos NO se están enviando realmente (faltan credenciales SMTP)

## 🔧 Soluciones de Envío Disponibles

### Opción 1: Configurar en config.json (⭐ Recomendado)
Edita `/workspaces/stisoft/sync/storage/sync/config.json` y completa la sección `smtp`:

```json
"smtp": {
    "enabled": true,
    "host": "smtp.gmail.com",
    "port": 587,
    "username": "alysonvalenzuela94@gmail.com",
    "password": "Av102953.",
    "from_address": "noreply@stisoft.local",
    "from_name": "STISOFT Auto Sync"
}
```

Luego sólo cambia `"enabled": false` a `"enabled": true`.

### Opción 2: Usar Gmail (variables de entorno)
Requiere una **[contraseña de aplicación](https://support.google.com/accounts/answer/185833)** de Google.

```bash
# 1. Generar contraseña de aplicación en: https://myaccount.google.com/apppasswords
# 2. Establecer variables de entorno:

export SMTP_HOST="smtp.gmail.com"
export SMTP_PORT="587"
export SMTP_USER="tu-email@gmail.com"
export SMTP_PASS="tu-contrasena-app"
```

Luego edita crontab:
```bash
crontab -e
# Cambia la línea de cron a:
0 8 * * * export SMTP_HOST="smtp.gmail.com" SMTP_PORT="587" SMTP_USER="..." SMTP_PASS="..." && /home/codespace/.php/current/bin/php /workspaces/stisoft/sync/run_sync.php >> /workspaces/stisoft/sync/storage/logs/sync.log 2>&1
```

### Opción 3: Usar Microsoft 365
```json
"smtp": {
    "enabled": true,
    "host": "smtp.office365.com",
    "port": 587,
    "username": "tu-email@empresa.com",
    "password": "tu-contrasena"
}
```

### Opción 4: Servidor SMTP Custom
```json
"smtp": {
    "enabled": true,
    "host": "mail.ensoin.com",
    "port": 587,
    "username": "usuario@tudominio.com",
    "password": "contraseña"
}
```

## 📝 Probar la Configuración

### Con config.json:
```bash
# 1. Editar config.json y habilitar SMTP
vi /workspaces/stisoft/sync/storage/sync/config.json
# Cambiar "enabled": true

# 2. Ejecutar el script manualmente
cd /workspaces/stisoft
/home/codespace/.php/current/bin/php sync/run_sync.php

# 3. Revisar logs
tail -10 sync/storage/logs/sync.log
```

### Con variables de entorno:
```bash
# 1. Establecer variables de entorno temporalmente
export SMTP_HOST="smtp.gmail.com"
export SMTP_PORT="587"
export SMTP_USER="tu-email@gmail.com"
export SMTP_PASS="tu-contraseña-app"

# 2. Ejecutar el script manualmente
cd /workspaces/stisoft
/home/codespace/.php/current/bin/php sync/run_sync.php

# 3. Revisar logs
tail -10 sync/storage/logs/sync.log

# 4. Si funcionó, verifica tu correo
```

## 🐛 Solución de Problemas

### "Email enviado" pero no llega nada
- Revisa la carpeta de SPAM/Junk en tu correo
- Verifica que usaste **contraseña de aplicación** de Google, no la contraseña normal
- En Microsoft 365: requiere autenticación moderna habilitada
- Revisa que el servidor SMTP sea accesible desde la red

### "Connection timed out" a SMTP
- El servidor SMTP no está disponible desde la red
- Verifica la IP y puerto en config.json
- Comprueba firewall/proxy que bloquee puerto 587

### "Connection timed out" al SFTP
- Es normal si el servidor SFTP (200.54.73.219) no está disponible
- El script continuará generando reportes por email
- Revisa la configuración del servidor SFTP en `sync/storage/sync/config.json`

### "No hay credenciales SMTP"
- config.json tiene `"enabled": false`
- Las variables de entorno no se configuraron
- Los reportes se están guardando en: `sync/storage/logs/emails/`
- Configura SMTP siguiendo una de las opciones arriba

## 📧 Verificar Reportes Guardados

```bash
# Ver todos los reportes registrados
ls -la /workspaces/stisoft/sync/storage/logs/emails/

# Ver contenido de un reporte
cat /workspaces/stisoft/sync/storage/logs/emails/ARCHIVO.log

# Ver últimos 5 reportes
ls -lat /workspaces/stisoft/sync/storage/logs/emails/ | head -5
```

## ✨ Cambios Realizados

1. **Ruta de vendor/autoload.php**: Corregida de `sync/vendor/` a `/vendor/`
2. **Crontab PHP**: Actualizado a `/home/codespace/.php/current/bin/php`
3. **PHPMailer**: Instalado en composer para SMTP
4. **Email fallback**: Sistema de respaldo que registra reportes en logs
5. **Config.json mejorado**: Sección SMTP para configurar credentials
6. **Funciones mejoradas**: 
   - `send_report_email()` - lee config.json, intenta SMTP, luego mail(), luego log
   - `log_report_to_file()` - registra reportes como alternativa
   - `mail_fallback()` - fallback a mail() tradicional


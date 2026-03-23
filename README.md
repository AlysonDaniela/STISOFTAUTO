# STISOFTAUTO

Sistema web PHP para automatizar procesos operativos y de RR.HH. integrados con Buk: empleados, cambios, bajas, documentos, vacaciones y liquidaciones.

## Resumen rapido

- Stack principal: PHP + MySQL/MariaDB + Composer
- Punto de entrada web: `index.php`
- Modulo mas sensible: `rliquid/`
- Configuracion privada: `config.json` y `sync/storage/sync/config.json`
- Dependencias separadas:
  - raiz del proyecto: PHPMailer, phpseclib
  - `rliquid/`: FPDF, FPDI, PhpSpreadsheet

## Requisitos

- Linux con Apache o Nginx
- PHP 8.1 o superior
- MySQL o MariaDB
- Composer 2
- Extensiones PHP:
  - `mysqli`
  - `curl`
  - `mbstring`
  - `json`
  - `zip`
  - `xml`
  - `gd`

## Estructura

- `index.php`, `empleados/`, `cambios/`, `bajas/`, `vacaciones/`, `documentos/`: modulos principales
- `rliquid/`: lotes de liquidaciones, generacion de PDFs y envio a Buk
- `conexion/`: acceso a base de datos
- `includes/` y `partials/`: autenticacion, layout y componentes compartidos
- `sync/`: automatizaciones, almacenamiento y reportes
- `docs/`: documentacion operativa para onboarding y mantenimiento

## Quick Start

```bash
git clone <URL_DEL_REPOSITORIO> /var/www/html
cd /var/www/html
composer install
cd /var/www/html/rliquid
composer install
```

Luego:

1. Copiar `config.example.json` a `config.json`.
2. Copiar `sync/storage/sync/config.example.json` a `sync/storage/sync/config.json`.
3. Completar `conexion/db.php`.
4. Asegurar permisos de escritura en carpetas runtime.
5. Abrir el sistema en el navegador y validar login, base de datos y Buk.

## Instalacion

### 1. Dependencias PHP

Proyecto base:

```bash
composer install
```

Modulo de liquidaciones:

```bash
cd /var/www/html/rliquid
composer install
```

### 2. Base de datos

Editar:

- `conexion/db.php`

Debes completar las credenciales del servidor MySQL/MariaDB y verificar que la clase `clsConexion` quede operativa.

### 3. Archivos sensibles

Este repositorio no versiona credenciales reales. Usa estos archivos como base:

- `config.example.json` -> `config.json`
- `sync/storage/sync/config.example.json` -> `sync/storage/sync/config.json`

Debes completar al menos:

- SMTP
- SFTP
- credenciales de base de datos
- token y URL base de Buk
- rutas locales
- retencion de PDFs de `rliquid`

### 4. Permisos de escritura

El usuario del servidor web debe poder escribir en:

- `storage/`
- `sync/storage/`
- `logs/`
- `logs_php/`
- `tmp_uploads/`
- `documentos/logs_buk_docs/`
- `bajas/logs_buk_terminate/`
- `empleados/logs_buk/`
- `sindicato/logs_buk_attr_file/`
- `vacaciones/logs_buk_vacaciones/`
- `vacaciones/logs_vac/`
- `rliquid/tmp_liq/`

Ejemplo:

```bash
sudo chown -R www-data:www-data /var/www/html
sudo find /var/www/html -type d -exec chmod 775 {} \;
```

Ajusta `www-data` si tu servidor usa otro usuario.

### 5. Servidor web

Apunta el `document root` a:

```text
/var/www/html
```

Si usas Apache, habilita `mod_rewrite` si aplica y permite leer `.htaccess`.

## Configuracion esperada

### `config.json`

Configuracion general del proyecto y del proceso de sincronizacion:

- modo de lectura
- acceso SFTP
- SMTP
- base de datos
- Buk
- scheduler
- rutas locales

### `sync/storage/sync/config.json`

Configuracion usada por runtime y reportes:

- SMTP
- base de datos
- Buk
- retencion de PDFs de liquidaciones

## Modulo `rliquid`

El modulo de liquidaciones:

- recibe archivos CSV o XLSX
- convierte XLSX a CSV si corresponde
- resume netos por trabajador
- cruza datos con `adp_empleados`
- genera PDFs por colaborador
- envia documentos a Buk
- registra corridas, estado y resumen por correo

Para operar necesita:

- `rliquid/vendor/autoload.php`
- escritura en `rliquid/tmp_liq/`
- SMTP activo en `sync/storage/sync/config.json`
- acceso a la tabla `adp_empleados`

Notas importantes:

- parte de sus tablas auxiliares se crean automaticamente
- depende de datos historicos y maestros que no se crean solos
- si falta `buk_emp_id`, el PDF puede generarse pero el envio no ocurrira

## Base de datos

Antes de usar el sistema en un servidor nuevo, valida:

- que exista la base operativa completa
- que el usuario MySQL tenga permisos de lectura y escritura
- que la tabla `adp_empleados` tenga datos vigentes

## Seguridad

- No subir credenciales reales al repositorio.
- No versionar logs, PDFs, cargas ni archivos runtime.
- Rotar secretos si alguna vez se expusieron fuera del servidor.
- Mantener tokens y claves solo en configuracion privada.

## Git y limpieza local

El repositorio ya ignora:

- logs
- temporales
- PDFs generados
- cargas de usuarios
- configuraciones con secretos
- varios archivos basura detectados en esta workspace

Documentacion extra:

- `docs/ONBOARDING.md`
- `docs/WORKSPACE_CLEANUP.md`

## Comandos utiles

Instalacion base:

```bash
composer install
```

Instalacion de `rliquid`:

```bash
cd /var/www/html/rliquid && composer install
```

Validar sintaxis:

```bash
php -l rliquid/index.php
php -l includes/runtime_config.php
```

Verificar estado Git:

```bash
git status
git log --oneline -n 5
```

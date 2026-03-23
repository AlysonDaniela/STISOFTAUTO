# STISOFTAUTO

Sistema web PHP para automatizar procesos operativos y de RR.HH. con Buk, incluyendo empleados, cambios, bajas, documentos, vacaciones y liquidaciones.

## Requisitos

- Linux con Apache o Nginx
- PHP 8.1 o superior
- MySQL o MariaDB
- Composer 2
- Extensiones PHP habituales para este proyecto:
  - `mysqli`
  - `curl`
  - `mbstring`
  - `json`
  - `zip`
  - `xml`
  - `gd`

## Estructura general

- `index.php`, `empleados/`, `cambios/`, `bajas/`, `vacaciones/`, `documentos/`: modulos principales
- `rliquid/`: modulo de liquidaciones y generacion de PDFs
- `conexion/`: conexion a base de datos
- `includes/` y `partials/`: autenticacion, layout y componentes compartidos
- `sync/`: procesos automaticos, almacenamiento y reportes

## Instalacion

### 1. Clonar el proyecto

```bash
git clone <URL_DEL_REPOSITORIO> /var/www/html
cd /var/www/html
```

### 2. Instalar dependencias PHP del proyecto base

```bash
composer install
```

### 3. Instalar dependencias del modulo `rliquid`

```bash
cd /var/www/html/rliquid
composer install
```

### 4. Configurar conexion a base de datos

Editar el archivo:

- `conexion/db.php`

Debes completar alli las credenciales del servidor MySQL/MariaDB y verificar que exista la clase `clsConexion`.

### 5. Configurar archivos sensibles

Este repositorio no versiona credenciales reales. Usa estos archivos como base:

- Copiar `config.example.json` a `config.json`
- Copiar `sync/storage/sync/config.example.json` a `sync/storage/sync/config.json`

Luego completar:

- credenciales SFTP
- credenciales SMTP
- credenciales de base de datos
- token y URL base de Buk
- rutas locales del servidor
- retencion de PDFs de liquidaciones

### 6. Permisos de escritura

El usuario del servidor web debe poder escribir en estas carpetas:

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

Ajusta el usuario `www-data` si tu servidor usa otro.

### 7. Configurar Apache / Nginx

Apuntar el document root a:

```text
/var/www/html
```

Si usas Apache, habilita `mod_rewrite` si el proyecto lo necesita y permite leer `.htaccess`.

### 8. Verificar Composer en produccion

Confirma que existan estas carpetas:

- `vendor/`
- `rliquid/vendor/`

Si faltan, el proyecto no podra cargar PHPMailer, phpseclib, FPDF o PhpSpreadsheet.

## Puesta en marcha

1. Abrir el sistema en el navegador.
2. Iniciar sesion.
3. Verificar acceso a base de datos.
4. Revisar que la configuracion SMTP y SFTP sea correcta.
5. Probar primero los modulos principales.
6. Probar `rliquid/` subiendo un archivo de ejemplo.

## Modulo de liquidaciones

El modulo `rliquid`:

- recibe archivos CSV o XLSX
- convierte el origen a CSV si corresponde
- genera PDFs por trabajador
- cruza datos con `adp_empleados`
- envia documentos a Buk
- guarda corridas, estados y resumen por correo

Para funcionar correctamente necesita:

- `rliquid/vendor/autoload.php`
- acceso de escritura en `rliquid/tmp_liq/`
- configuracion SMTP activa en `sync/storage/sync/config.json`
- acceso a la tabla `adp_empleados`

## Base de datos

Algunas tablas se crean automaticamente desde el codigo, por ejemplo en `rliquid/index.php`, pero el sistema tambien depende de tablas de negocio ya existentes, como:

- `adp_empleados`
- tablas historicas y operativas del resto de modulos

Antes de usar el sistema en un servidor nuevo, valida que la base este cargada y que el usuario MySQL tenga permisos de lectura y escritura.

## Seguridad

- No subir credenciales reales al repositorio.
- No versionar archivos generados, logs, PDFs ni cargas de usuarios.
- Cambiar contrasenas si alguna vez estuvieron expuestas fuera del servidor.
- Mover tokens y credenciales a configuracion privada siempre que sea posible.

## Git recomendado

Este repositorio esta preparado para versionar codigo y excluir:

- logs
- archivos temporales
- PDFs generados
- archivos cargados por usuarios
- configuraciones con secretos

## Comandos utiles

Instalacion base:

```bash
composer install
```

Instalacion de `rliquid`:

```bash
cd /var/www/html/rliquid && composer install
```

Revisar sintaxis de un archivo PHP:

```bash
php -l rliquid/index.php
```

## Siguiente paso sugerido

Cuando la configuracion este lista:

1. crear el commit inicial
2. vincular o confirmar `origin`
3. hacer push a `main`

Si quieres, en el siguiente paso tambien te dejo hecho el commit inicial y el `push --force` para que GitHub quede sobreescrito desde este estado limpio.

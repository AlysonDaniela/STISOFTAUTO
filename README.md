# STISOFTAUTO

Sistema web PHP para procesos operativos y de RR.HH. integrado con Buk.

## Instalacion

Esta instalacion considera que recibiras por fuera:

- el `.zip` del sistema
- el archivo de la base de datos
- las credenciales reales

## Pasos

### 1. Copiar archivos

Descomprime el sistema en el servidor web, por ejemplo en:

```text
/var/www/html
```

### 2. Restaurar la base de datos

Importa el archivo `.sql` entregado y confirma que el usuario MySQL tenga permisos de lectura y escritura sobre esa base.

La conexion principal se revisa en:

- `conexion/db.php`

### 3. Instalar dependencias

En la raiz del proyecto:

```bash
composer install
```

En `rliquid/`:

```bash
cd /var/www/html/rliquid
composer install
```

### 4. Crear configuraciones privadas

Crear desde los ejemplos:

```bash
cp /var/www/html/config.example.json /var/www/html/config.json
cp /var/www/html/sync/storage/sync/config.example.json /var/www/html/sync/storage/sync/config.json
```

Luego completar:

- base de datos
- SMTP
- SFTP
- Buk

Parte de esta configuracion puede quedar por codigo y parte por pantalla, segun el modulo.

### 5. Revisar permisos

El servidor web debe poder escribir en carpetas de trabajo como:

- `storage/`
- `sync/storage/`
- `logs/`
- `logs_php/`
- `tmp_uploads/`
- `rliquid/tmp_liq/`

### 6. Validar IP autorizada

El servidor donde instalen el sistema debe tener su IP autorizada. Si no esta autorizada, el proceso de `sync` y conexiones externas no funcionara correctamente.

### 7. Probar conexion SFTP

Con la configuracion cargada, valida que el sistema llegue al SFTP esperado. La prueba basica es confirmar que puede conectarse y ver el archivo o la ruta remota definida para ese cliente.

Si no conecta, revisar:

- IP autorizada
- host
- puerto
- usuario
- clave o llave
- ruta remota

## Pruebas minimas

Despues de instalar:

1. Abrir el sistema en navegador.
2. Confirmar conexion a base de datos.
3. Confirmar que carga el panel principal.
4. Validar configuracion SMTP, SFTP y Buk.
5. Probar `rliquid` con un archivo de ejemplo.

## Archivos importantes

- `conexion/db.php`
- `config.json`
- `sync/storage/sync/config.json`
- `rliquid/index.php`

## Nota de entrega

Este repositorio puede quedar sin credenciales reales. La entrega operativa final se completa con:

- `.zip` del sistema
- respaldo de base de datos
- credenciales reales
- IP a autorizar en servicios externos

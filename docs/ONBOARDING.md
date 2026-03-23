# Onboarding Tecnico

## Instalacion rapida

Este proyecto se entrega pensando en que recibiras:

- `.zip` del sistema
- archivo de base de datos
- credenciales reales por fuera

## Orden recomendado

1. Descomprimir el sistema en el servidor.
2. Restaurar la base de datos entregada.
3. Revisar `conexion/db.php`.
4. Crear `config.json` y `sync/storage/sync/config.json` desde los archivos example.
5. Completar SMTP, SFTP y Buk.
6. Ejecutar `composer install` en raiz y en `rliquid/`.
7. Revisar permisos de escritura.
8. Confirmar que la IP del servidor este autorizada.
9. Probar acceso SFTP y carga general del sistema.

## Validaciones minimas

- login operativo
- conexion a BD OK
- panel principal carga
- SFTP responde
- Buk responde
- `rliquid` puede procesar un archivo de prueba

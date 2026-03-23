# Onboarding Tecnico

## Objetivo

Esta guia resume el orden correcto para levantar el proyecto sin depender de contexto historico.

## Paso 1. Dependencias

Instalar dependencias en ambos niveles:

```bash
cd /var/www/html
composer install

cd /var/www/html/rliquid
composer install
```

## Paso 2. Configuracion

Crear archivos privados desde los ejemplos:

```bash
cp /var/www/html/config.example.json /var/www/html/config.json
cp /var/www/html/sync/storage/sync/config.example.json /var/www/html/sync/storage/sync/config.json
```

Despues completar:

- SMTP
- SFTP
- Buk
- base de datos

Ademas revisar:

- `conexion/db.php`

## Paso 3. Permisos

Las carpetas runtime deben poder escribir:

- `storage/`
- `sync/storage/`
- `logs/`
- `logs_php/`
- `tmp_uploads/`
- `rliquid/tmp_liq/`

## Paso 4. Validaciones minimas

Antes de probar funcionalidad:

```bash
php -l /var/www/html/index.php
php -l /var/www/html/rliquid/index.php
php -l /var/www/html/includes/runtime_config.php
```

## Paso 5. Checklist funcional

1. Abrir login.
2. Confirmar conexion a base de datos.
3. Verificar que el panel principal cargue.
4. Revisar modulos base.
5. Probar `rliquid` con un archivo de ejemplo.
6. Confirmar escritura en `rliquid/tmp_liq/`.
7. Confirmar que Buk responde con token valido.

## Riesgos conocidos

- `rliquid/index.php` concentra mucha logica en un solo archivo.
- El sistema depende de tablas de negocio existentes que no se crean automaticamente.
- Un servidor sin extensiones PHP completas puede fallar de forma parcial.

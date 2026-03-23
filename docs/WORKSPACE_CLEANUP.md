# Limpieza de Workspace

## Contexto

En esta carpeta se detectaron archivos basura generados fuera del flujo normal del proyecto, por ejemplo:

- `Body`
- `CharSet`
- `Host`
- `Password`
- `SMTPAuth`
- `SMTPSecure`
- `Subject`
- `Username`
- nombres raros como `[#dcfce7,` o `[message]`

Actualmente esos archivos:

- no estan versionados en Git
- ya estan cubiertos por `.gitignore`
- no forman parte de la aplicacion

## Recomendacion

Se pueden eliminar manualmente cuando confirmes que no los necesita ningun proceso local. La intencion es mantener la raiz del proyecto solo con codigo, configuracion y assets reales.

## Buenas practicas

- no ejecutar scripts de prueba escribiendo en la raiz del repo
- usar `tmp/`, `storage/` o un directorio de trabajo temporal
- revisar `git status` y `ls` antes de preparar despliegues

# Cómo subir el Panel Euskera a tu hosting

Estos pasos sirven para un hosting típico de WordPress con **cPanel**, que es lo más
común en Argentina (DonWeb, HostGator, etc.). Si tu hosting es distinto contame cuál
es y ajusto los pasos.

## 1. Crear el subdominio

1. Entrá al **cPanel** de tu hosting (te lo dio tu proveedor al contratar).
2. Buscá **"Subdominios"** (Subdomains).
3. Creá `panel` sobre tu dominio → va a quedar `panel.euskera.com.ar`.
4. Elegí que apunte a una carpeta nueva, por ejemplo `panel.euskera.com.ar` (cPanel
   la sugiere sola).

## 2. Crear la base de datos MySQL

1. En cPanel, buscá **"Bases de datos MySQL"** (MySQL Databases).
2. Creá una base nueva, por ejemplo `euskera_panel`.
3. Creá un usuario MySQL nuevo con una contraseña fuerte, y anotala.
4. Asociá ese usuario a esa base, dándole **todos los permisos**.
5. Anotate los tres datos: nombre de la base, usuario y contraseña (con el prefijo
   que cPanel les agrega automáticamente, tipo `tuusuario_euskera_panel`).

## 3. Completar config.php

Abrí el archivo `config.php` (en tu computadora, antes de subirlo) y reemplazá:

```php
define('DB_NAME', 'tuusuario_euskera');    // el nombre real de tu base
define('DB_USER', 'tuusuario_euskera');    // el usuario real que creaste
define('DB_PASS', 'CAMBIAR_ESTA_CLAVE');   // la contraseña real que creaste
```

`DB_HOST` casi siempre queda como `localhost`, no lo toques salvo que tu hosting
te diga lo contrario.

## 4. Subir los archivos

1. En cPanel, abrí el **Administrador de Archivos** (File Manager), o usá un
   cliente FTP (como FileZilla) con los datos que te dio tu hosting.
2. Entrá a la carpeta del subdominio que creaste en el paso 1
   (normalmente `public_html/panel.euskera.com.ar` o similar).
3. Subí estos 4 archivos ahí dentro:
   - `index.html`
   - `api.php`
   - `config.php` (ya editado con tus datos reales)
   - `install.php`

## 5. Instalar la base de datos

1. Desde tu navegador, entrá a: `https://panel.euskera.com.ar/install.php`
2. Debería decirte que creó las tablas y los usuarios `gabriel` y `mariano`
   (contraseña inicial para ambos: `euskera2026`).
3. Si te tira un error, seguramente son los datos de `config.php` — revisalos.

## 6. Borrar install.php

Muy importante: una vez que veas el mensaje de "Instalación completa", **borrá
`install.php` del servidor**. Si lo dejás, cualquiera que lo visite podría volver
a ejecutarlo.

## 7. Probar el panel

Entrá a `https://panel.euskera.com.ar/index.html` (o directo a
`https://panel.euskera.com.ar/`), iniciá sesión con `gabriel` o `mariano` y la
contraseña inicial, y probá cargar un barril o una venta.

## 8. Cambiar las contraseñas iniciales

Por ahora ambos usuarios comparten la contraseña `euskera2026`. Avisame cuando
quieras las definitivas (una distinta para cada uno) y te agrego una pantalla de
"cambiar contraseña", o te paso el comando SQL para hacerlo directo en phpMyAdmin.

## 9. Agregar el link desde WordPress

En el panel de WordPress, andá a **Apariencia > Menús**, agregá un enlace
personalizado a `https://panel.euskera.com.ar/` con el texto "Panel Interno", y
guardá. Como ese link no tiene indexación ni aparece en el sitemap, no lo va a
encontrar cualquiera navegando — solo quien tenga el link o lo vea en el menú.

---

**Nota sobre los códigos QR de los barriles:** ya están armados para apuntar a
`https://panel.euskera.com.ar/?barril=ID-DEL-BARRIL`. Con este backend en tu
propio dominio, ese link ya funciona de verdad en cuanto completes estos pasos.

# Panel Euskera (producción) — panel.euskera.com.ar

Sistema de Producción / Stock / Ventas de Euskera Cerveza Artesanal.
PHP + MySQL (PDO), SPA en vanilla JS (`index.html`), FPDF para remitos.

## Reglas
- La base de datos de producción (`c2061780_panel`) tiene datos de trabajo reales: **no se toca** sin avisar.
- `config.php` (credenciales) NO está en el repo. Copiar `config.example.php` a `config.php` y completar.
- Cambios de esquema: archivos `upgradeN.php` re-ejecutables; se corren una vez en el navegador y se borran del servidor.
- Los scripts de limpieza/corrección puntuales (`limpiar_*`, `revertir_*`, `reset_*`, `fix_*`) están fuera del repo a propósito.

## Deploy
Subir los archivos modificados a `public_html/panel/` (sin pisar `config.php`).
Último upgrade: `upgrade20.php`.

> Repo hermano: `Euskera-Panel2` es la beta 2.0 (otra base de código, DB `c2061780_beta`).

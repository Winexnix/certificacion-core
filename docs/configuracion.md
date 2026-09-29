# Configuración, empaquetado y versionado

## `composer.json`

- `name`: `winex/certificacion`, `type: library`, `license: MIT`, `version: 1.9.1`.
- `require`: `php ^8.3`, `ext-curl`, `ext-dom`, `ext-mbstring`, `ext-openssl`.
- `require-dev`: `vlucas/phpdotenv ^5.7` (solo lo usa `bin/bootstrap.php`).
- `suggest`: `ext-soap` "Solo lo necesita SiiSoapClient" (ver [pendientes.md](pendientes.md): hoy `SiiSoapClient` no usa `SoapClient`).
- `autoload`: PSR-4 `Winex\Certificacion\` → `src/`.
- `config.sort-packages: true`.

El campo `version` tiene que coincidir con el tag de git: los repos `path` de
Composer no leen tags y se guían por ese campo.

## `.gitattributes`

- `* -text`: git no convierte finales de línea en ningún archivo. Motivo documentado:
  los literales multilínea de `src/` arman XML que después se firma; un cambio
  CRLF↔LF alteraría bytes firmados. `src/` va en LF.
- `export-ignore` (no viajan en el dist): `/.claude`, `/.gitattributes`,
  `/.gitignore`, `/bin`, `/data`.

## `.gitignore`

Ignorados: `/vendor/`, `/.env`, `/composer.lock`, `/data/certs/`, `/data/caf/`,
`/data/output/`, `.claude/` (en cualquier nivel).

## Variables de entorno (`.env` en la raíz)

Las carga `bin/bootstrap.php` con `Dotenv::createImmutable(BASE_PATH)->load()`.
La librería (`src/`) no lee `.env`; solo lee `getenv('OPENSSL_MODULES_PATH')`.

| Variable | Dónde se usa | Qué es |
|---|---|---|
| `SII_CERT_PATH` | todos los scripts de `bin/` | ruta al .pfx/.pem |
| `SII_CERT_PASS` | todos los scripts de `bin/` | clave del certificado |
| `SII_OPENSSL_PATH` | scripts de `bin/` (en `estado-declaracion.php` con default `'openssl'`; en el resto sin default) | binario `openssl` para el fallback legacy |
| `OPENSSL_MODULES_PATH` | `Core\Certificate::extractLegacyViaCli()` | opcional; carpeta con `legacy.dll`/`legacy.so`. Si existe, se copia a `OPENSSL_MODULES` |
| `WINEX_PFX_PASS` | `Core\Certificate` (interno) | no se configura: es el nombre de la variable con que se le pasa la clave al proceso hijo `openssl` |

## `bin/bootstrap.php`

Arranque común de los scripts:

1. `BASE_PATH` = raíz del proyecto.
2. `require vendor/autoload.php`.
3. Carga `.env`.
4. `date_default_timezone_set('America/Santiago')`.
5. `chdir(BASE_PATH)` para que las rutas relativas `data/...` funcionen desde cualquier directorio.

## Carpeta `data/`

| Carpeta | Versionada | Contenido / uso |
|---|---|---|
| `data/schemas/` | sí | `EnvioBOLETA_v11.xsd`, `xmldsignature_v10.xsd`. Ningún código las lee (no hay validación XSD). |
| `data/set_pruebas/` | sí | `Set Prueba BE.txt`: set de 5 casos de boleta que lee `SetPruebasParser`. |
| `data/certs/` | no | certificado del SII (.pfx) |
| `data/caf/` | no | CAF por ambiente; los scripts referencian `data/caf/Boleta/…` y `data/caf/Boletapro/…` |
| `data/output/` | no | XML generados por los scripts y el caché de token de `SiiBoletaProduccion::getToken()` |

## Zona horaria

- Los scripts fijan `America/Santiago` globalmente.
- Dentro de `src/`, las fechas con zona explícita son: el campo `FECHA` al descargar el CAF (`SolicitudFolios`) y la fecha de la declaración (`DeclaracionBoletas::declarar`). El resto (`FchEmis`, `TmstFirma`, `TSTED`, `TmstFirmaEnv`, `getInfo()`) usa `date()`, es decir, la zona por defecto del proceso que llama.

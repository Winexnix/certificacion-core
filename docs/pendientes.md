# Pendientes: inconsistencias, código muerto y duplicado

Hallazgos de la lectura del código en la versión 1.9.1. **Nada de esto se corrigió.**
Van ordenados de más a menos relevante. "Sin uso en este repo" significa que ningún
archivo de `src/` ni `bin/` lo llama; como es una librería, antes de borrar algo hay
que revisar los proyectos que la consumen.

## 1. Comportamiento que puede fallar

### 1.1 Los scripts de `bin/` convierten dos veces a ISO-8859-1
`GeneradorBoleta` (DOM con encoding ISO-8859-1), `Timbre::generarTed()` y `EnvioBoleta`
ya entregan bytes ISO-8859-1. Aun así, `enviar-set-boletas.php:114`,
`enviar-consumo-folios.php:90` y `emitir-boleta.php:96` aplican
`mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8')` sobre ese resultado.
Comprobado: un ítem "Ñandú" sale de `GeneradorBoleta` como `d1 61 6e 64 fa` y, tras la
conversión del script, como `3f 61 6e 64 3f` (`?and?`). Si eso pasa después de firmar,
se invalida la firma. Hoy no se nota porque el set y los datos del emisor no tienen
tildes. Afecta solo a `bin/`, no a `src/`.

### 1.2 `SiiSoapClient::getToken()` recibe el certificado con encabezados PEM
El parámetro se llama `$publicKeyLimpia` y va directo a `<X509Certificate>`, pero los
scripts (`enviar-set-boletas.php:22`, `enviar-consumo-folios.php:20`) le pasan
`$cert->getPublicKey()`, que trae `-----BEGIN CERTIFICATE-----` y saltos de línea.
`FirmaElectronica` sí limpia el certificado en su constructor.

### 1.3 Caché de token de `SiiBoletaProduccion::getToken()`
Guarda el token en un solo archivo `data/output/token_cache_boleta_produccion.txt`,
sin distinguir empresa, certificado ni ambiente (el nombre dice "produccion" aunque se
construya con `$produccion = false`), y crea `data/output` con permisos `0777`.
`SiiSoapClient::getToken()` eliminó un caché equivalente justamente por mezclar tokens
entre empresas (ver su comentario). Hoy solo lo usan los scripts de `bin/produccion/`.

### 1.4 `estadoEnvio` no se puede usar con la API REST
`DeclaracionBoletas::solicitarRevisionSet()` y el README piden revisar el envío con
`SiiSoapClient::estadoEnvio()` (canal SOAP). No hay equivalente tipado para
`SiiBoletaProduccion::consultarEnvio()`, que devuelve el JSON crudo y no valida el
`trackId`, a diferencia de `estadoEnvio()` y `solicitarRevisionSet()`.

## 2. Código duplicado

### 2.1 Firma de la semilla implementada dos veces
`SiiSoapClient::getToken()` (líneas 84–116) firma la semilla con código propio
(digest sobre el string literal, `SignedInfo` firmado como string, XML con saltos de
línea) en vez de usar `FirmaElectronica::firmarSemilla()` (digest sobre C14N vía DOM,
XML en una línea). Son dos implementaciones distintas del mismo canje.

### 2.2 Navegación HTTP con sesión: `SolicitudFolios` vs `NavegadorSii`
Duplicados casi línea a línea: la constante `UA`, `MAX_REDIRECCIONES`,
`MARCADORES_LOGIN`, el bucle de redirecciones de `pedir()`, `resolverUrl()` y
`sesionVigente()`. Además hay dos `form()` con reglas distintas: el de
`SolicitudFolios` incluye `submit`, `image`, checkbox/radio sin marcar y campos
deshabilitados, y lee `<select>`; el de `NavegadorSii` los excluye y no lee `<select>`.

### 2.3 Validación de RUT en tres lugares
`Postulacion\Rut` valida el dígito verificador; `SolicitudFolios::rutDv()` solo separa
número y DV sin validarlo; `SiiSoapClient` y `SiiBoletaProduccion` hacen
`explode('-', …)` sin validar. `Rut` vive en `Postulacion` aunque es genérico.

### 2.4 Envíos multipart repetidos
`SiiSoapClient::enviarDte()`, `SiiBoletaProduccion::subirBoleta()` y
`enviarConsumoFolios()` arman el mismo multipart (`rutSender`, `dvSender`,
`rutCompany`, `dvCompany`, `archivo`). `enviarConsumoFolios()` repite además el manejo
de temporal de `enviarBoletaXml()` en vez de reutilizarlo.

### 2.5 Armado de `<DTE>` + TED + firma repetido en los scripts
`enviar-set-boletas.php` y `emitir-boleta.php` repiten: generar XML, releerlo con DOM
para sacar `MntTotal` y `NmbItem`, armar `$datosDte`, timbrar, insertar TED y
`<TmstFirma>` con `str_replace` y firmar. Ninguna clase del core lo encapsula.

## 3. Código muerto o sin uso en este repo

- `SiiSoapClient`: la propiedad `$firmaElectronica` se asigna en el constructor y nunca se usa. Los `use SoapClient;` y `use SimpleXMLElement;` no se usan.
- `FirmaElectronica::firmarDte()`: las ramas `Libro` → `EnvioLibro` y URI vacía → `getToken` no se usan en este repo (no hay generador de libros; la semilla se firma con `firmarSemilla()`). El valor por defecto `'#F1T33'` es de factura, tipo que este repo no genera.
- `GeneradorBoleta`: la clave `glosa` del descuento aparece en el docblock y no se usa.
- `data/schemas/*.xsd`: ningún código valida contra ellas. Además falta `ConsumoFolio_v10.xsd`, que `GeneradorConsumoFolios` referencia en `schemaLocation`.
- API pública sin llamadas en este repo (probablemente la usan las apps; verificar allá): `Certificate::toPem()`, `getInfo()`, `fromContents()`, `Timbre::fromXml()`, `SiiBoletaProduccion::solicitarToken()` (fuera de `getToken()`), `enviarBoletaXml()` y `enviarConsumoFolios()`.

## 4. Documentación y comentarios desactualizados

- `Certificate.php:49` y `:181` remiten a `DEPLOY.md`, que no existe en este repo.
- `SetPruebasParser.php:10` menciona `run_boletas.php`, que no existe.
- `EnvioBoleta.php:42`: el comentario dice "la Caratula a versión 2.0", pero el código emite `<Caratula version="1.0">`.
- `FirmaElectronica::firmarDte()` (líneas 163–165): el comentario habla de que el archivo termine en `</getToken>`, pero el método firma DTE, sobres y RCOF.
- `composer.json`: `suggest.ext-soap` dice que lo necesita `SiiSoapClient`, pero la clase no usa la extensión SOAP.
- `README.md`: la sección Estructura no lista `src/Certificacion/EstadoEnvio.php` ni los archivos de excepciones y transporte; `data/output/` se describe como "cache de tokens", pero solo `SiiBoletaProduccion::getToken()` lo usa así.
- Scripts `enviar-*.php`: imprimen "Obteniendo Token (Cache)..." aunque `SiiSoapClient::getToken()` ya no cachea. `enviar-set-boletas.php:109` dice "Empaquetando en un EnvioDTE" y el sobre es `EnvioBOLETA`.
- `GwtRpc::leer()`: el docblock describe los esquemas con `'S'` y `'O'`; el código también usa `'B'` (boolean).

## 5. Datos fijos e inconsistencias en `bin/`

- RUT de empresa, RUT de quien firma y datos del emisor fijos en cuatro scripts.
- `FchResol` distinta entre scripts del mismo set: `2026-09-05` en `enviar-set-boletas.php` y `2026-09-04` en `enviar-consumo-folios.php`.
- `enviar-consumo-folios.php` tiene los montos del set escritos a mano, mientras `enviar-set-boletas.php` los lee del .txt con `SetPruebasParser`: si cambia el set, el RCOF queda desalineado.
- `SII_OPENSSL_PATH`: `estado-declaracion.php` le da default `'openssl'`; los demás scripts la leen sin default.
- `estado-declaracion.php` no tiene `try/catch`, a diferencia del resto. Los demás scripts terminan con código 0 aunque fallen.
- `emitir-boleta.php`: CAF `PENDIENTE_CAF_PRODUCCION.xml` y folio `0` siguen como TODO.

## 6. Consistencia de estilo y configuración

- Timeouts y TLS: `SiiBoletaProduccion` no fija `CURLOPT_SSL_VERIFYPEER/VERIFYHOST` (queda en el default de cURL), a diferencia de `SiiSoapClient` y `TransporteCurl`, que los fijan explícitos. Timeouts de conexión: 10 s en `SiiSoapClient`/`SiiBoletaProduccion` y 15 s en `TransporteCurl`.
- Clases antiguas sin tipos (`EnvioBoleta` constructor, propiedades de `Certificate`, `FirmaElectronica`, `SiiSoapClient`, `SiiBoletaProduccion`) conviven con clases nuevas `final` y `readonly` (`Folios`, `Postulacion`).
- Excepciones: `Core`, `Certificacion` y `Produccion` lanzan `\Exception` genérica; `Folios` y `Postulacion` tienen jerarquías propias.
- Zona horaria: `FchEmis`, `TmstFirma`, `TSTED` y `TmstFirmaEnv` usan `date()` (zona del proceso), mientras la fecha del CAF y la de la declaración fijan `America/Santiago` explícito.
- No hay tests en este repo; `TransporteFalso` existe para los tests de las apps.

## 7. Archivos locales fuera de git

- `src/.claude/launch.json`: carpeta `.claude` **dentro de `src/`**. Está ignorada por git (`.claude/`), pero `.gitattributes` solo excluye `/.claude` de la raíz, y una instalación por repositorio `path` copia la carpeta tal cual.
- `CLAUDE-SECURITY-20260917-033248/`: resultados de un análisis de seguridad en la raíz, ignorados por una exclusión fuera del repo (no por `.gitignore`). No se revisó su contenido para esta documentación.

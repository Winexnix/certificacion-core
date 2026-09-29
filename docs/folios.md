# Folios: login AUT2000 y solicitud de CAF (`src/Folios`)

Pide folios (CAF) replicando el asistente web legacy `cvc_cgi/dte/of_*` del SII.
Además, varias piezas de esta carpeta (sesión, transporte, excepción de sesión)
las reutiliza [Postulación](postulacion.md).

## `Ambiente` (enum)

| Caso | `value` | `host()` | `idk()` |
|---|---|---|---|
| `Ambiente::Certificacion` | `certificacion` | `maullin.sii.cl` | `100` |
| `Ambiente::Produccion` | `produccion` | `palena.sii.cl` | `300` |

`portal()` = `https://{host}`. El ambiente es un parámetro obligatorio del
constructor, no configuración (cada aplicación fija el suyo en código).

## `SolicitudFolios`

`new SolicitudFolios(Ambiente $ambiente, ?TransporteHttp $transporte = null)` (por defecto `TransporteCurl`).

### `autenticar(Certificate $cert): SesionSii`

- `POST https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?{referencia}` con `referencia = {portal}/cvc_cgi/dte/of_solicita_folios`, `Origin`/`Referer` de `zeusr.sii.cl`.
- TLS mutuo con `getPublicKeyChain()` (cadena completa) y `getPrivateKey()`. Timeout 45 s.
- Las redirecciones solo pueden ir a `https://*.sii.cl`.
- Las cookies `NETSCAPE_LIVEWIRE.*` que el SII pone por JavaScript se extraen del HTML (`recibirDesdeHtml`).
- Si la sesión no queda con cookie `TOKEN`, lanza `SolicitudFoliosException`.

### `solicitar(SesionSii $sesion, string $rutEmpresa, int $tipoDte, int $cantidad): string`

Devuelve el XML del CAF. Validaciones previas: `cantidad >= 1` y RUT con número y DV
(no valida el dígito verificador). Timeout por paso 70 s.

| Paso | Petición | Qué pasa |
|---|---|---|
| 1 | `GET of_solicita_folios` | revisa que la sesión siga viva |
| 2 | `POST` al `action` del form (hasta 4 veces) | manda los inputs del SII + `RUT_EMP`, `DV_EMP`, `FOLIO_INICIAL=0`, `COD_DOCTO`, `CANT_DOCTOS`, `ACEPTAR=Continuar`; sale cuando el form apunta a `of_genera_folio` |
| 3 | (lectura) | la confirmación tiene que traer `FOLIO_INI`, `FOLIO_FIN`, `NOMUSU` |
| 4 | `POST of_genera_folio` (`ACEPTAR=Obtener Folios`) | **timbra los folios**. Antes se revisa que la URL sea del ambiente |
| 5 | `POST` al form de descarga | `FECHA` = hoy en `America/Santiago`, `ACEPTAR=AQUI`; cuerpo sin convertir de encoding |

Desde el paso 4, cualquier `SolicitudFoliosException` se convierte en
`FoliosSinCafException` (con `folioDesde`, `folioHasta`, `ambiente`). También si la
respuesta no trae `<AUTORIZACION` y `<CAF`.

**Candados**

- Cada URL y redirección del asistente: `https` y host exacto del ambiente (`soloAmbiente`).
- Detección de login: si la URL o el HTML contiene `autInicioDTE`, `IngresoCertificado`, `InicioAutenticacion`, `CAutInicio.cgi` o `CAutValida` → `SesionCaducaException`.
- Detección de error: textos como "El código de este mensaje es", "no se encuentra autorizado", "no es representante", "excede", "no puede solicitar" → `SolicitudFoliosException` con el texto del SII (máx. 300).
- Revisión del CAF descargado (`revisarCaf`): quita BOM, convierte ISO-8859-1→UTF-8 si hace falta, parsea, y exige `IDK` del ambiente, `RE` = RUT pedido y `TD` = tipo pedido. Si no, lanza sin devolver el CAF.

**Parseo HTML**: `form()` toma el primer `<form>`, todos los `input` con nombre salvo
`button`/`reset`, y los `select` (opción `selected` o la primera).

Las redirecciones se siguen a mano (máx. 10); 301/302/303 pasan a `GET` sin cuerpo,
307/308 conservan método. User-Agent de Chrome fijo.

## `SesionSii`

Cookies de la sesión AUT2000. El docblock la trata como **credencial** (con ella se
timbran folios sin el certificado): si se persiste, cifrada.

- `fromArray()` / `toArray()`: serialización `[{name, value, domain, host_only}]`.
- `tiene($nombre)`, `valor($nombre)` (primer valor no vacío).
- `cabeceraPara($url)`: arma `Cookie:` según dominio/host-only.
- `recibir($url, RespuestaHttp)`: aplica `Set-Cookie`.
- `recibirDesdeHtml($html)`: aplica `document.cookie = "…"` con dominio `sii.cl`.
- Reglas: una cookie solo se fija para su host o un dominio padre, y nunca fuera de `sii.cl`; `Max-Age <= 0`, `Expires` pasado o valor vacío la borran.

## Transporte HTTP

| Tipo | Rol |
|---|---|
| `TransporteHttp` (interface) | `enviar($metodo, $url, $cabeceras, ?$formulario, ?$tls, $segundos): RespuestaHttp`. Una petición, sin redirecciones ni cookies |
| `TransporteCuerpoCrudo` (interface, extiende la anterior) | agrega `enviarCrudo(…, string $cuerpo, …)` para cuerpos tal cual (GWT-RPC). Separada para no romper implementaciones existentes en las apps |
| `TransporteCurl` | implementación real: solo HTTPS, `FOLLOWLOCATION` apagado, TLS verificado, conexión 15 s, acepta gzip. Si hay `$tls`, escribe cert y llave en temporales 0600 que borra en `finally`. Falla de red → `SolicitudFoliosException("No se pudo conectar con el SII (host).")` con el detalle de cURL en `previous` |
| `TransporteFalso` | para tests: responde por patrón `host+ruta` con `*` comodín (string, `RespuestaHttp` o `Closure`); sin patrón → 404. Registra todo en `$enviadas`; `urls()` las lista |
| `RespuestaHttp` | `status`, `cabeceras` (nombres en minúscula, lista de valores), `cuerpo`; `cabecera()`, `esRedireccion()` (301/302/303/307/308 con `Location`) |

## Excepciones

| Clase | Hereda de | Cuándo |
|---|---|---|
| `SolicitudFoliosException` | `\RuntimeException` | falla general; mensaje apto para mostrar |
| `SesionCaducaException` | `SolicitudFoliosException` | la sesión no sirve; se lanza antes de timbrar, se puede reautenticar y reintentar |
| `FoliosSinCafException` | `SolicitudFoliosException` | la orden de timbrar salió pero no se obtuvo el CAF; **no reintentar**, bajarlo desde "Folios ya autorizados" |

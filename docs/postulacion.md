# Postulación y declaración de cumplimiento (`src/Postulacion`)

Cubre los trámites de certificación de boleta electrónica que no son envíos de
documentos: postular la empresa en `maullin`, pedir la revisión del set, descargar
el set y declarar cumplimiento en `www4`. Reutiliza la sesión y el transporte de
[Folios](folios.md).

## Secuencia completa (según el código y el README)

```
PostulacionSii::autenticar()          login AUT2000 (misma sesión para todo)
PostulacionSii::postular()            maullin, pe_*            → graba postulación
  … enviar set de boletas + RCOF (ver certificacion.md) …
SiiSoapClient::estadoEnvio()->limpio()
DeclaracionBoletas::solicitarRevisionSet()   www4, GWT         → si SetSinDescargarException:
DeclaracionBoletas::descargarSetPruebas()    www4, servlet        descargar y volver a pedir
  … el SII responde por correo; si aprueba, estado P90 …
DeclaracionBoletas::estado()->porDeclarar()
DeclaracionBoletas::declarar()               www4, GWT         → P91: autorizada en producción
```

## `PostulacionSii`

`new PostulacionSii(?TransporteHttp $transporte = null)`. Host fijo `maullin.sii.cl`, timeout 70 s.

- `autenticar(Certificate $cert): SesionSii` → delega en `SolicitudFolios(Ambiente::Certificacion)->autenticar()`, envolviendo la excepción en `PostulacionException`.
- `postular(SesionSii $sesion, DatosPostulacion $datos): ResultadoPostulacion`:

| Paso | Petición | Detalle |
|---|---|---|
| 1 | `POST pe_ingrut` (`ACEPTAR=Aceptar Condiciones`) | Referer `/cvc/dte/pe_condiciones.html`; el form siguiente tiene que ir a `pe_datos_empresa` |
| 2 | `POST pe_datos_empresa` | `RUT_EMP`, `DV_EMP`. Pasa hasta 3 páginas de aviso con `ACEPTAR=Continuar` y guarda su texto en `avisos` |
| 3 | `POST pe_confirma` (`GRABAR=Confirmar Datos`) | parte de los hidden del SII sin `ESFAC, FACT, NC, ND, SET03, SET06, SET11, SET84, SET72, BOLEXEN`; fija `RUT_USU`, `DV_USU`, `MAIL_SUP`, `MAIL_SII`, `MAIL_DTE`, `URL`, `NOM_SW`, `ESBOL=S`, `BOLELEC=S` y `BOLEXEN=S` si `boletaExenta` |
| 4 | `POST pe_graba_postulacion` (`CONF=Confirmar Postulación`) | **graba**. Antes compara los hidden de la confirmación contra lo enviado (incluye `ESFAC=N`); si algo no calza, se detiene. Después exige el texto "ha sido aceptada" |

Todos los campos se envían convertidos a ISO-8859-1. Si el texto de la respuesta
contiene "El código de este mensaje es", "no está autorizado", "no es representante",
"No ha sido posible completar su solicitud" o "Usted no tiene", lanza.

## `DeclaracionBoletas`

`new DeclaracionBoletas(?TransporteCuerpoCrudo $transporte = null)`. Habla GWT-RPC con
`https://www4.sii.cl/certBolElectDteInternet/facade`, host fijo `www4.sii.cl`, timeout 45 s.

Constantes capturadas del portal (versión 1.0.55, 2026-09-14): `POLICY`, `PERMUTACION`,
`SERVICIO` (`…certBolElectDte.web.client.service.Facade`), `PASO = 90` y las firmas
de tipo de 4 DTO con su esquema de campos (`ESQUEMAS`).

| Método | Llamadas al SII | Resultado |
|---|---|---|
| `estado($sesion, $rutEmpresa)` | `obtenerEstadoAutorizaEmp(rut, dv, 90, null)` | `EstadoDeclaracion` (código `null` si el SII no devuelve objeto) |
| `declarar($sesion, DatosDeclaracion)` | `estado` → `recuperarNombreContribuyente` → `listarDocAutorizados` → `autorizarEmpresaBolProd` | Solo si el estado es `P90`. Arma `PostulSegHistInsUpdTo` con estado nuevo `P91`, sistema `BVE`, un `TdtEmpresaAutorizadaTo` (DV/RUT de empresa, proveedor y usuario, fechas de hoy en `America/Santiago` como `d-m-Y`, correo y nombre del proveedor, link de consulta) y los documentos tal como los listó el SII. Si la respuesta no es un `PostulSegHistInsUpdTo` con `P91`, o falla después de enviar, lanza `DeclaracionInciertaException` |
| `solicitarRevisionSet($sesion, $rutEmpresa, $trackId)` | `recuperarNroActividadesVigAfecIva` → `ingresarTrackId` | Código `90` si hay actividades afectas a IVA, si no `91`. Respuesta `'1'` = OK; `'errorEstado'` → `SetSinDescargarException`; otra → `PostulacionException` |
| `descargarSetPruebas($sesion, $rutEmpresa, $correoProveedor)` | `obtenerPostulacionSeg(rut, dv, "90", "P90")` y `GET DownloadFileServlet?rutEmpresa…&rutRepre…&mailProvSw…` | Devuelve el texto del set (UTF-8). **Candado**: no descarga si la empresa ya está en P90. Exige HTTP 200 y que el texto traiga "SET DE PRUEBA" y "CASO-" |
| `verificarPortal()` | `GET {PERMUTACION}.cache.html` sin sesión | Lista de problemas (vacía = vigente). 404 = el SII publicó otra versión. Revisa que el archivo contenga la POLICY, el servicio, las 4 firmas de tipo, `DownloadFileServlet` y los 7 métodos usados |

El RUT del usuario autenticado sale de las cookies `RUT_NS`/`DV_NS`; si faltan,
`SesionCaducaException`.

Cabeceras GWT: `Content-Type: text/x-gwt-rpc; charset=UTF-8`, `X-GWT-Permutation`,
`X-GWT-Module-Base`, `Origin`, `Referer`.

## `NavegadorSii` (`@internal`)

HTTP con sesión contra **un** host: sigue hasta 10 redirecciones a mano, corta si
alguna sale del host o cae en el login (`SesionCaducaException`). Acepta cuerpo como
formulario (array) o crudo (string; exige `TransporteCuerpoCrudo`). Convierte las
`SolicitudFoliosException` del transporte en `PostulacionException`.

- `pagina()`: `pedir()` + conversión ISO-8859-1→UTF-8 + revisión de sesión.
- `form()` (estático): primer `<form>`, como lo enviaría un navegador (sin botones, `submit`, `image`, deshabilitados, ni checkbox/radio sin marcar); valores con `trim`.
- `texto()`, `resolverUrl()` (estáticos).

## GWT-RPC (`GwtRpc`, `LectorGwt`, ambos `@internal`)

- `GwtRpc::llamada(base, policy, servicio, método, params)`: arma una petición protocolo 7 (`7|0|n|tabla|…`). Escritores: `string`, `int`, `nulo`, `integer` (java.lang.Integer), `long` (base64 de GWT, con back-reference para −128..127), `tipo`, `valor` (reescribe tal cual un objeto leído).
- `GwtRpc::leer(respuesta, esquemas)`: acepta `//OK[…]`; `//EX` lanza (y si es `IncompatibleRemoteServiceException` avisa que hay que recapturar la versión). Parser propio del "JSON de GWT" (longs entre comillas simples, escapes `\x`/`\u`).
- `LectorGwt`: lee el payload invertido. `esNulo()`, `string()`, `objeto()` → `['@tipo', 'valor' | 'elementos' | 'campos']`, resuelve back-references. Un tipo sin esquema conocido lanza ("pudo haber cambiado el portal").

## Datos y resultados

| Clase | Contenido y validaciones |
|---|---|
| `Rut` | `Rut::de($rut, $campo)`: acepta con o sin puntos/guion, valida largo y dígito verificador (módulo 11). `numero`, `dv`, `__toString()` = `numero-dv` |
| `DatosPostulacion` | empresa y administrador (`Rut`), 3 correos, `nombreSoftware` (1–30), `boletaExenta` (default `true`), `url` opcional (tiene que empezar con `www.`, máx. 100). `correo()` estático replica `esValidoMail2` del SII (≤50, ASCII imprimible, sin `!"#$%&/()=?+*|<>[];`, un solo `@`, punto en el dominio) |
| `DatosDeclaracion` | empresa y proveedor (`Rut`), `linkConsulta` (1–100), `nombreProveedor` (1–30), `correoProveedor` (regla anterior) |
| `EstadoDeclaracion` | `empresa`, `codigo` (`?string`), `glosa`, `paso` (`?int`). `porDeclarar()` = `P90`, `autorizada()` = `P91` |
| `ResultadoPostulacion` | `empresa`, `avisos` (textos de las páginas de aviso), `mensaje` (texto final, máx. 600) |

## Excepciones

| Clase | Hereda de | Cuándo |
|---|---|---|
| `PostulacionException` | `\RuntimeException` | falla general; mensaje apto para mostrar |
| `DeclaracionInciertaException` | `PostulacionException` | la orden de declarar salió pero no se confirmó; consultar `estado()` antes de reintentar. Expone `rutEmpresa` |
| `SetSinDescargarException` | `PostulacionException` | el SII responde `errorEstado` al pedir la revisión del set |

`SesionCaducaException` (de `Folios`) también se lanza desde aquí.

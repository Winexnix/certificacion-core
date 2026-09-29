# Producción: API REST de Boleta Electrónica (`src/Produccion`)

## `SiiBoletaProduccion`

`new SiiBoletaProduccion(FirmaElectronica $firmador, bool $produccion = true)`.

| | Autenticación / consulta | Envío |
|---|---|---|
| `$produccion = true` | `https://api.sii.cl/recursos/v1` | `https://rahue.sii.cl/recursos/v1` |
| `$produccion = false` | `https://apicert.sii.cl/recursos/v1` | `https://pangal.sii.cl/recursos/v1` |

Timeouts: conexión 10 s, respuesta 45 s. No fija opciones TLS explícitas (quedan
los valores por defecto de cURL). User-Agent fijo `Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)`
en los envíos.

### Token

| Método | Qué hace |
|---|---|
| `getSemilla()` | `GET {auth}/boleta.electronica.semilla` → `<SEMILLA>`; si no está, lanza con la respuesta cruda |
| `solicitarToken()` | semilla → `FirmaElectronica::firmarSemilla()` → `POST {auth}/boleta.electronica.token` (`Content-Type: application/xml`) → `<TOKEN>`. Sin caché: pensado para que la app cachee por emisor |
| `getToken()` | igual que el anterior, pero con caché en archivo `data/output/token_cache_boleta_produccion.txt` (formato `timestamp|token`, vigencia 3000 s). Crea `data/output` con `0777` si no existe. Lo usan los scripts CLI |

### Envío

Todos los envíos son multipart con `rutSender`, `dvSender`, `rutCompany`,
`dvCompany`, `archivo`, y cabecera `Cookie: TOKEN=…`. Esperan HTTP 200 y JSON; si
no, lanzan con la respuesta cruda.

| Método | Endpoint | Notas |
|---|---|---|
| `enviarBoleta($token, $rutEmisor, $rutEnvia, $rutaArchivoXml)` | `POST {envio}/boleta.electronica.envio` | desde un archivo en disco; el SII lo recibe con su `basename` |
| `enviarBoletaXml($token, $rutEmisor, $rutEnvia, $xml)` | ídem | escribe el XML en un `tempnam` (0600), lo manda con nombre `{tmp}.xml` y lo borra en `finally` |
| `enviarConsumoFolios($token, $rutEmisor, $rutEnvia, $xmlRcof)` | `POST {envio}/boleta.electronica.consumo` | mismo manejo de temporal que el anterior |

Ninguno de estos métodos firma ni convierte el encoding: reciben el XML ya firmado.

### Consulta

`consultarEnvio($token, $rutEmisor, $trackId): array` → `GET {auth}/boleta.electronica.envio/{rut}-{dv}-{trackId}`
con `Cookie: TOKEN=…`. Devuelve el JSON decodificado o lanza si no es JSON.
No valida el formato de `$trackId`.

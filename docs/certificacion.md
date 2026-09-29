# Certificación (`src/Certificacion`)

Piezas del flujo de certificación de boletas en `maullin.sii.cl` (canal DTEWS/CGI).

## `SiiSoapClient`

`new SiiSoapClient(FirmaElectronica $firma, string $host = 'maullin.sii.cl')`.
Con `'palena.sii.cl'` apunta a producción. Pese al nombre, no usa `SoapClient`:
arma los sobres SOAP a mano y los manda con `file_get_contents` (stream context) o cURL.

Timeouts: conexión 10 s (solo en cURL; en `file_get_contents` rige `default_socket_timeout`),
respuesta 45 s. Verificación TLS encendida en las tres llamadas.

### `getToken(string $privateKey, string $publicKeyLimpia): string`

1. `POST https://{host}/DTEWS/CrSeed.jws` con `<getSeed/>` → extrae `<SEMILLA>` (numérica).
2. Firma la semilla **con código propio** (no usa `FirmaElectronica::firmarSemilla`): digest SHA1 del string literal `<getToken><item><Semilla>…</Semilla></item></getToken>`, `Reference URI=""` con transform enveloped-signature, firma del `SignedInfo` como string.
3. `POST https://{host}/DTEWS/GetTokenFromSeed.jws` con el XML firmado escapado en `<pszXml>` → extrae `<TOKEN>`.
4. Sin caché: el comentario explica que se quitó el caché en disco porque mezclaba tokens entre empresas; quien llama reusa el token en memoria.

Errores de conexión: mensaje con `error_get_last()` y la sugerencia de configurar `openssl.cafile`.

### `enviarDte(string $token, string $rutEmisor, string $rutEnvia, string $rutaArchivoXml): string`

`POST https://{host}/cgi_dte/UPL/DTEUpload` multipart con `rutSender`, `dvSender`,
`rutCompany`, `dvCompany` y `archivo` (`CURLFile`). Cabeceras: `Cookie: TOKEN=…`,
User-Agent fijo y `Expect:` vacío (evita `100-continue`). Devuelve el `<TRACKID>` o
lanza con la respuesta cruda. Los scripts lo usan tanto para el set de boletas como
para el RCOF.

### `estadoEnvio(string $token, string $rutEmpresa, string $trackId): EstadoEnvio`

Valida `trackId` (1–15 dígitos). `POST https://{host}/DTEWS/QueryEstUp.jws` con
`getEstUp` (`RutCompania`, `DvCompania`, `TrackId`, `Token`). Si la respuesta no
trae `getEstUpReturn`, lanza con los primeros 200 caracteres del texto. Solo lectura.

## `EstadoEnvio`

Valor inmutable con `trackId`, `estado`, `glosa`, `informados`, `aceptados`, `rechazados`, `reparos`.

- `desdeRespuesta($respuesta, $trackId)`: decodifica entidades, lee `ESTADO` (obligatorio; si falta lanza `RuntimeException`), `TRACKID`, `GLOSA` y **suma** `INFORMADOS/ACEPTADOS/RECHAZADOS/REPAROS` de todos los tipos de documento.
- `enProceso()`: estado en `REC, SOK, CRT, FOK, PRD`.
- `procesado()`: estado `EPR`.
- `limpio()`: `EPR`, al menos un informado, todos aceptados, cero rechazos y cero reparos.
- `resumen()`: texto para mostrar.

## `SetPruebasParser`

`parsear(string $rutaArchivo, string $tipoDte = '39'): array` lee el .txt del Set
de Pruebas que entrega el SII y devuelve los casos en el formato de `GeneradorBoleta`:

```php
[['tipo' => '39', 'det' => [['nombre', 'cantidad', 'precio', 'exento'?, 'unidad'?], …],
  'ref' => ['codigo' => 'SET', 'razon' => 'CASO-N']], …]
```

- Separa bloques por `CASO-N` seguido de una línea de `=`.
- Cada línea de ítem se parte por 2+ espacios/tabs y tiene que dar exactamente 3 columnas numéricas en cantidad y precio; se saltan encabezados y líneas `OBSERVACION`.
- Números: `1.000`/`1,000` como miles → entero; si no, decimal con `,` o `.`.
- `OBSERVACION: "…"`:
  - "item N … exento" marca ese ítem exento; si dice "exento" sin ítem y hay un solo ítem, lo marca;
  - "unidad de medida en X" pone `unidad` al ítem N mencionado (si hay más de uno) o a todos.
- Lanza si no existe el archivo, si no hay casos o si un caso queda sin ítems.

El set versionado (`data/set_pruebas/Set Prueba BE.txt`) tiene 5 casos; el 4 trae un
ítem exento y el 5 unidad `Kg`.

## `GeneradorConsumoFolios`

`generar(array $caratula, array $resumenes): string` arma el RCOF sin firmar:

- `<ConsumoFolios version="1.0">` con `schemaLocation … ConsumoFolio_v10.xsd`.
- `<DocumentoConsumoFolios ID="RCOF">` (el ID que busca `firmarDte(…, '#RCOF')`).
- `<Caratula version="1.0">`: `rut_emisor`, `rut_envia`, `fch_resol`, `nro_resol`, `fch_inicio`, `fch_final`, `sec_envio` y `TmstFirmaEnv` (`date()`).
- Un `<Resumen>` por elemento: `tipo`, `neto`/`iva`+`tasa_iva`/`exento` (solo si > 0), `total`, `folios_emitidos`, `folios_anulados`, `folios_utilizados`, `rangos_utilizados` y `rangos_anulados` (opcional) como `RangoUtilizados`/`RangoAnulados`.

El envío del RCOF en certificación se hace con `SiiSoapClient::enviarDte()`; en
producción existe `SiiBoletaProduccion::enviarConsumoFolios()` (ver [produccion.md](produccion.md)).

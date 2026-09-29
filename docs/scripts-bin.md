# Scripts de línea de comandos (`bin/`)

Herramientas de diagnóstico y del flujo de certificación de la propia empresa.
No viajan en el paquete (`export-ignore`). Todas cargan `bin/bootstrap.php` (ver
[configuracion.md](configuracion.md)) y leen el certificado desde `SII_CERT_PATH`,
`SII_CERT_PASS` y `SII_OPENSSL_PATH`.

Todas atrapan `\Exception` e imprimen `[ERROR] …` (salvo `estado-declaracion.php`,
que no tiene `try/catch`). Salvo en los casos indicados con `exit(1)`, terminan con
código 0 aun cuando fallan.

Datos del emisor: los scripts los leen del `.env` (`SII_RUT_EMPRESA`, `SII_RUT_FIRMA`, `EMISOR_*`; ver `.env.example`).
Receptor fijo `66666666-6` "Cliente General".

## `certificacion/enviar-set-boletas.php`

Envía el Set de Pruebas de boletas (tipo 39) a `maullin`.

1. Token por `SiiSoapClient::getToken()`.
2. Lee los casos con `SetPruebasParser` desde `data/set_pruebas/Set Prueba BE.txt` y los imprime para revisión.
3. CAF fijo `data/caf/Boleta/TU_CAF_BOLETA_39.xml` y folios fijos `56–60` (uno por caso; si no calza la cantidad, lanza).
4. Por caso: `GeneradorBoleta` (con referencia `SET`/`CASO-N`) → lee `MntTotal` y el primer `NmbItem` del XML → `Timbre::generarTed` → inserta TED + `TmstFirma` → `firmarDte('#F{folio}T{tipo}')`.
5. `EnvioBoleta` con `NroResol = 0` y `FchResol = 2026-09-05`.
6. Convierte con `mb_convert_encoding(…, 'ISO-8859-1', 'UTF-8')`, guarda en `data/output/envio_set_boletas.xml`.
7. Sube con `SiiSoapClient::enviarDte()` e imprime el TrackID.

## `certificacion/enviar-consumo-folios.php`

Genera y envía el RCOF del set a `maullin`.

- Fecha = hoy; folios `56–60`; `SecEnvio = 1`.
- Montos brutos por caso escritos a mano (`29800, 2040, 4100, 12720, 3500`) más exento `2000`; neto e IVA se calculan por caso con `round(bruto / 1.19)`.
- Carátula con `FchResol = 2026-09-04`, `NroResol = 0`.
- `GeneradorConsumoFolios` → `firmarDte('#RCOF')` → ISO-8859-1 → `data/output/consumo_folios.xml` → `SiiSoapClient::enviarDte()`.

El comentario del script exige que fecha, folios y montos coincidan exactamente con
el envío del set de la misma corrida.

## `certificacion/estado-declaracion.php <rut-empresa>`

Solo lectura. Autentica con `PostulacionSii`, consulta `DeclaracionBoletas::estado()`
e imprime empresa, código/glosa y si falta declarar cumplimiento. Sin argumento,
muestra el uso y sale.

## `produccion/emitir-boleta.php`

Emite una boleta real por la API REST de producción.

- Interruptor `$confirmarEnvioReal = false` en el código: con `false` solo obtiene el token (`SiiBoletaProduccion::getToken()`, con caché en archivo), imprime los primeros 6 caracteres y termina.
- Con `true`: CAF `data/caf/Boletapro/PENDIENTE_CAF_PRODUCCION.xml` y folio `0` (marcados TODO), un ítem "Producto de prueba" x1 a $1000, `NroResol = 80`, `FchResol = 2014-08-22`, guarda en `data/output/boleta_produccion_{folio}.xml` y sube con `enviarBoleta()`.

## `produccion/consultar-envio.php <TRACKID>`

Consulta el estado de un envío por la API REST de producción (`consultarEnvio`) para
la empresa fija e imprime el JSON. Sin argumento: uso y `exit(1)`.

## `util/firmar-semilla.php <SEMILLA>`

Firma una semilla obtenida a mano (Swagger/Postman) con `FirmaElectronica::firmarSemilla()`
e imprime el XML listo para `POST /boleta.electronica.token`. Sin argumento: `exit(1)`.

## `util/verificar-ted.php [ruta_xml] [ruta_caf]`

Verifica criptográficamente cada TED de un XML de envío. Deriva la llave pública
desde el `<RSASK>` del CAF, extrae cada `<DD>` y `<FRMT>` por regex del XML crudo y
aplica `openssl_verify` SHA1. Imprime `OK`/`FALLA` por tipo y folio.

Valores por defecto: `data/output/envio_set_boletas.xml` y
`data/caf/Boleta/TU_CAF_BOLETA_39.xml`.

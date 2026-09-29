# Core (`src/Core`)

Compartido por certificación y producción. Cinco clases, sin dependencias entre
ellas salvo que `EnvioBoleta` recibe un firmador (en la práctica `FirmaElectronica`).

## Flujo de una boleta

```
GeneradorBoleta::generarBoleta()  ->  <DTE><Documento ID="F{folio}T{tipo}">…</Documento></DTE>
Timbre::generarTed($datosDte)     ->  <TED>…</TED>   (se inserta antes de </Documento> junto a <TmstFirma>, lo hace quien llama)
FirmaElectronica::firmarDte($xml, '#F{folio}T{tipo}')
EnvioBoleta::empaquetar($dtesFirmados)   -> <EnvioBOLETA> firmado con '#SetDoc'
```

La inserción del TED y de `<TmstFirma>` no la hace ninguna clase del core: la hace
el llamador con `str_replace('</Documento>', …)` (así lo hacen los scripts de `bin/`).

---

## `Certificate`

Carga el certificado digital y expone llave privada, certificado y cadena.

**Construcción**

- `new Certificate(string $pfx, string $password, string $openSslPath = 'openssl', bool $esContenido = false)`: `$pfx` es una ruta, o los bytes si `$esContenido = true`. Si es ruta y no existe, lanza `\Exception`.
- `Certificate::fromContents($bytes, $password, $openSslPath)`: atajo de lo anterior con `$esContenido = true`.

**Orden de carga** (`cargarDesdeContenido`)

1. **PEM**: si el contenido trae `-----BEGIN `, va a `cargarDesdePem()`:
   - normaliza CRLF→LF;
   - abre la llave con la clave y, si falla, sin clave;
   - reexporta con `openssl_pkey_export`; si eso falla (sin `openssl.cnf` usable) usa el bloque PEM tal cual, salvo que sea `ENCRYPTED`, en cuyo caso lanza con instrucciones para reconvertir con `-nodes`;
   - el primer `CERTIFICATE` es el del titular; si hay más, se guarda la cadena.
2. **PKCS#12 nativo**: `openssl_pkcs12_read`. Si hay `extracerts`, arma la cadena hoja + intermedios.
3. **Fallback CLI legacy**: escribe el .pfx en un temporal (`tempnam`, `chmod 0600`, se borra en `finally`) y llama `extractLegacyViaCli()`:
   - si existe `OPENSSL_MODULES_PATH`, hace `putenv("OPENSSL_MODULES=…")`;
   - prueba primero con `-legacy` y luego sin él (para OpenSSL 1.x);
   - corre `openssl pkcs12` tres veces: `-nocerts -nodes` (llave), `-clcerts -nokeys` (hoja) y `-nokeys` (cadena completa);
   - si no logra extraer, lanza con la salida de OpenSSL saneada por `sinMaterialPem()` (máx. 500 caracteres) y, si detecta `unable to load provider legacy`, sugiere `OPENSSL_MODULES_PATH`.

**Cómo viaja la clave al `openssl` hijo** (`comandoPkcs12`, `openssl`)

- Nunca en la línea de comandos.
- Con `proc_open` disponible: por entorno, `-passin env:WINEX_PFX_PASS`.
- Sin `proc_open`: por archivo temporal 0600 (`-passin file:…`) y `shell_exec`, borrado en `finally`.
- `extractPemBlock()` normaliza CRLF→LF antes de sacar el bloque PEM.

**Métodos públicos**

| Método | Devuelve |
|---|---|
| `getPrivateKey()` | llave privada PEM |
| `getPublicKey()` | certificado hoja PEM (con encabezados `BEGIN/END`) |
| `getPublicKeyChain()` | hoja + intermedios PEM; si no hay cadena, igual que `getPublicKey()`. Se usa para el TLS mutuo (`CURLOPT_SSLCERT`) |
| `toPem()` | llave privada **sin cifrar** + hoja + intermedios sin duplicados, hoja siempre primero. Pensado para guardar el certificado ya convertido (cifrarlo en reposo es responsabilidad de quien lo guarda) |
| `getInfo()` | `['cn', 'not_before', 'not_after']` (fechas con `date()`) |
| `static sinMaterialPem($salida)` | la salida de OpenSSL sin bloques PEM, atributos de bolsa ni líneas base64 largas |
| `static comandoPkcs12(...)` | arreglo de argumentos del comando `openssl pkcs12` (placeholder `__PFX__` para la ruta) |

---

## `FirmaElectronica`

`new FirmaElectronica(string $privateKey, string $publicKey)`. El certificado se
guarda "limpio": sin `BEGIN/END CERTIFICATE` ni saltos de línea, que es lo que va
en `<X509Certificate>`.

Todas las firmas son RSA-SHA1, digest SHA1, C14N 20010315, y el `<KeyInfo>` lleva
`RSAKeyValue` (módulo/exponente sacados de la llave privada) + `X509Certificate`.

### `firmarSemilla(string $semilla): string`

Firma `<getToken><item><Semilla>…</Semilla></item></getToken>` para el canje
semilla→token. La `Reference` es `URI=""` con transform **enveloped-signature**
(el docblock explica que con el transform C14N el SII respondía ESTADO 11). Devuelve
el XML completo con `<?xml version="1.0"?>`. Lo usa `SiiBoletaProduccion::solicitarToken()`
y `bin/util/firmar-semilla.php`.

### `firmarDte(string $xmlString, string $referenciaUri = '#F1T33'): string`

Firma un nodo del documento y agrega `<Signature>` como último hijo del elemento raíz.
El nodo a firmar se elige por el texto de `$referenciaUri`:

| `$referenciaUri` contiene | Nodo firmado |
|---|---|
| `SetDoc` | `SetDTE` |
| `Libro` | `EnvioLibro` |
| `RCOF` | `DocumentoConsumoFolios` |
| (vacío) | `getToken` |
| cualquier otra cosa | `Documento` |

- Si el nodo tiene atributo `ID`, se marca como ID con `setIdAttribute`.
- Digest = SHA1 del C14N del nodo. Transform C14N.
- `preserveWhiteSpace = true`.
- Devuelve `saveXML()` sin el salto de línea final.

---

## `Timbre`

`new Timbre(string $caf)`: `$caf` es la ruta al XML del CAF o el XML crudo (si
empieza con `<`). `Timbre::fromXml($xml)` es un atajo del constructor.

Al construir:

1. Si el contenido no es UTF-8 válido, lo convierte desde ISO-8859-1 y ajusta el `encoding` del prólogo.
2. Toma el bloque `<CAF>…</CAF>` **tal cual** con regex (no reserializa, para no alterar el `<DA>` firmado por el SII). Si no está, lanza.
3. Lee `<RSASK>` (llave privada del timbre), la limpia y la reconstruye como PEM `RSA PRIVATE KEY` con líneas de 64.

### `generarTed(array $datosDte): string`

Claves esperadas en `$datosDte`: `rut_emisor`, `tipo_dte`, `folio`, `fecha`,
`rut_receptor`, `razon_social_receptor`, `monto_total`, `nombre_item_1`.

1. `IT1` = primeros 40 caracteres de `nombre_item_1` (con `mb_substr`), escapado XML. `RSR` también se escapa.
2. Arma `<DD>` con `RE, TD, F, FE, RR, RSR, MNT, IT1`, el `<CAF>` original y `<TSTED>` (`date()`).
3. Aplana blancos entre tags (`>\s+<` → `><`), igual que hace el SII antes de verificar.
4. Convierte el `<DD>` de UTF-8 a **ISO-8859-1** antes de firmar.
5. Firma SHA1withRSA con la llave del CAF; si falla, lanza con `openssl_error_string()`.
6. Devuelve `<TED version="1.0">{DD}<FRMT algoritmo="SHA1withRSA">…</FRMT></TED>` (el DD en bytes ISO-8859-1).

---

## `GeneradorBoleta`

`generarBoleta(array $emisor, array $receptor, string $folio, array $detalles, string $tipoDte = '39', ?array $referencia = null, ?array $descuento = null): string`

Devuelve un `<DTE version="1.0">` (DOM con encoding ISO-8859-1, `formatOutput = true`).

- **Documento**: `ID = "F{folio}T{tipoDte}"`.
- **IdDoc**: `TipoDTE`, `Folio`, `FchEmis` (= `date('Y-m-d')`), `IndServicio = 3` fijo.
- **Emisor**: `rut`, `razon_social`, `giro`, `direccion`, `comuna` → `RUTEmisor`, `RznSocEmisor`, `GiroEmisor`, `DirOrigen`, `CmnaOrigen`.
- **Receptor**: `rut` → `RUTRecep`; `razon_social` opcional → `RznSocRecep`.
- **Detalle** (cada ítem): `nombre`, `cantidad`, `precio` (bruto, IVA incluido), opcionales `exento` (`=== true`) y `unidad`.
  Genera `NroLinDet`, `IndExe=1` si exento, `NmbItem`, `QtyItem`, `UnmdItem`, `PrcItem`, `DescuentoMonto` (si le toca), `MontoItem`.
- **Referencia** (si viene): `NroLinRef=1`, `CodRef`, `RazonRef` (usado por el Set de Pruebas: `SET` / `CASO-N`).

**Totales** (tasa IVA fija 19%):

- `monto_final` de cada línea = `round(cantidad * precio)`; se acumula en exento o afecto bruto.
- Descuento global opcional `['tipo' => 'porcentaje'|'monto', 'valor' => …]`:
  - solo sobre el afecto; se acota a `[0, afecto]`;
  - no se emite `<DscRcgGlobal>`: se reparte entre líneas afectas como `<DescuentoMonto>` a prorrata (floor por línea y el resto de a un peso), y `MontoItem` va neto de descuento. El comentario explica que con `DscRcgGlobal` el SII daba reparo 260.
- `MntNeto = round(afecto / 1.19)`, `IVA = afecto − neto`, `MntTotal = neto + IVA + exento`.
- Orden en `<Totales>`: `MntNeto`, `MntExe`, `IVA`, `MntTotal`; los tres primeros solo si son > 0.

La clave `glosa` del descuento aparece en el docblock pero el código no la usa.

---

## `EnvioBoleta`

`new EnvioBoleta($firmador, $rutEmisor, $rutEnvia, $nroResol, $fchResol)` (sin tipos).

`empaquetar(string $dtesFirmados): string`:

1. Quita las declaraciones `<?xml …?>` de los DTE.
2. Cuenta `<TipoDTE>` por tipo y arma un `<SubTotDTE>` por tipo.
3. Arma `<EnvioBOLETA version="1.0">` con `schemaLocation … EnvioBOLETA_v11.xsd`, `<SetDTE ID="SetDoc">` y `<Caratula version="1.0">` con `RutEmisor`, `RutEnvia`, `RutReceptor = 60803000-K` (fijo), `FchResol`, `NroResol`, `TmstFirmaEnv` (`date()`), subtotales.
4. Firma con `firmarDte($xml, '#SetDoc')`.

La cabecera declara `encoding="ISO-8859-1"`.

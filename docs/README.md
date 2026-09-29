# Documentación del core `winex/certificacion`

Estado del código a la versión **1.9.1** (`composer.json`, tag `v1.9.1`).
Todo lo que se describe aquí está implementado hoy; lo que se ve raro, duplicado o
sin uso está anotado en [pendientes.md](pendientes.md), no corregido.

## Qué es

Librería PHP (Composer, `type: library`) que integra con el SII de Chile para
boleta electrónica (tipos 39 y 41):

- carga del certificado digital (.pfx o PEM) y firma XML-DSig;
- timbre electrónico (TED) con el CAF;
- armado del XML de boleta (`<DTE>`), del sobre `<EnvioBOLETA>` y del RCOF (`<ConsumoFolios>`);
- envío y consulta por dos canales: SOAP/CGI de `maullin`/`palena` (DTEWS) y API REST de boleta (`api.sii.cl`/`rahue.sii.cl`, `apicert`/`pangal`);
- solicitud de folios (CAF) replicando el asistente web `cvc_cgi/dte/of_*`;
- postulación a boleta electrónica en `maullin` y declaración de cumplimiento en `www4` (GWT-RPC).

Se consume como paquete Composer. Solo `src/`
viaja en el paquete: `bin/` y `data/` están marcados `export-ignore`.

## Índice por área

| Documento | Área | Código |
|---|---|---|
| [guia-de-uso.md](guia-de-uso.md) | **Empieza aquí:** de cero a emitir boletas, paso a paso | — |
| [configuracion.md](configuracion.md) | Composer, autoload, `.env`, carpeta `data/`, finales de línea, versionado | `composer.json`, `.gitattributes`, `.gitignore`, `bin/bootstrap.php` |
| [core.md](core.md) | Certificado, firma, timbre, XML de boleta y sobre | `src/Core/` |
| [certificacion.md](certificacion.md) | Canal SOAP DTEWS, estado de envío, Set de Pruebas, RCOF | `src/Certificacion/` |
| [produccion.md](produccion.md) | API REST de Boleta Electrónica | `src/Produccion/` |
| [folios.md](folios.md) | Login AUT2000 y solicitud de CAF | `src/Folios/` |
| [postulacion.md](postulacion.md) | Postulación, revisión del set, declaración de cumplimiento | `src/Postulacion/` |
| [scripts-bin.md](scripts-bin.md) | Scripts de línea de comandos | `bin/` |
| [pendientes.md](pendientes.md) | Inconsistencias, código muerto y duplicado detectados | — |

## Mapa de dependencias entre áreas

```
bin/*  ──► Core ──► (openssl, DOM)
   │        ▲
   │        ├── Certificacion\SiiSoapClient   (usa FirmaElectronica solo como tipo)
   │        └── Produccion\SiiBoletaProduccion (usa FirmaElectronica::firmarSemilla)
   │
   └──► Postulacion ──► Folios (SesionSii, TransporteCurl/TransporteCuerpoCrudo,
                                SolicitudFolios::autenticar, SesionCaducaException)
        Folios ──► Core\Certificate (para el TLS mutuo del login)
```

## Namespaces

PSR-4 `Winex\Certificacion\` → `src/`:

| Namespace | Carpeta | Archivos |
|---|---|---|
| `Winex\Certificacion\Core` | `src/Core` | 5 |
| `Winex\Certificacion\Certificacion` | `src/Certificacion` | 4 |
| `Winex\Certificacion\Produccion` | `src/Produccion` | 1 |
| `Winex\Certificacion\Folios` | `src/Folios` | 10 |
| `Winex\Certificacion\Postulacion` | `src/Postulacion` | 13 |

No hay carpeta de tests en este repo. `TransporteFalso` existe para que las apps
consumidoras prueben sin red.

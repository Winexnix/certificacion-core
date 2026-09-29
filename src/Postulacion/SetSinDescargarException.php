<?php

namespace Winex\Certificacion\Postulacion;

/**
 * El SII no acepta pedir la revisión del set porque la empresa no ha descargado
 * el set de pruebas de boletas en el portal (respuesta `errorEstado`). Se
 * resuelve con DeclaracionBoletas::descargarSetPruebas() y volviendo a pedir.
 */
class SetSinDescargarException extends PostulacionException {}

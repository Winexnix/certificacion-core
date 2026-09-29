<?php

namespace Winex\Certificacion\Postulacion;

/**
 * La postulacion o la declaracion de cumplimiento fallo. El mensaje esta pensado
 * para mostrarse: dice que paso y, cuando se puede, que revisar. No incluye
 * material sensible (el detalle de red va en `previous`).
 */
class PostulacionException extends \RuntimeException {}

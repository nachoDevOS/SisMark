<?php

namespace App\Http\Requests;

/**
 * Mismas reglas que el alta: el código del horario (idHorario) es la clave y no se
 * edita, así que no hay diferencias respecto a StoreHorarioRequest.
 */
class UpdateHorarioRequest extends StoreHorarioRequest {}

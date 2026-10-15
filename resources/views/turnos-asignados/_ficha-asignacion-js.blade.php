{{-- Estado y lógica de asignar un turno (`fichaAsignacion()`): la ficha del
     funcionario, el contrato elegido y las fechas. Lo comparten el modal de la
     ficha y la pantalla «Turnos asignados → Asignar». --}}
@once
    <script>
        window.fichaAsignacion = (inicial) => ({
            ficha: null,
            cargandoFicha: false,
            errorFicha: '',
            contratoId: inicial.contratoId || null,
            turnoNombre: inicial.turnoNombre || '',
            desde: inicial.desde,
            hasta: inicial.hasta,
            minDesde: null,
            maxHasta: null,
            async cargarFicha(ci) {
                this.ficha = null;
                this.errorFicha = '';
                if (! ci) { return; }
                this.cargandoFicha = true;
                try {
                    const resp = await fetch(`{{ route('turnos-asignados.ficha') }}?ci=${encodeURIComponent(ci)}`, { headers: { 'Accept': 'application/json' } });
                    if (! resp.ok) { throw new Error(); }
                    this.ficha = await resp.json();
                    // Si volvió de un error de validación se respeta lo elegido y
                    // sus fechas; si hay un solo contrato libre, se elige solo.
                    const libres = this.ficha.contratos.filter(c => ! c.turno);
                    const elegido = libres.find(c => String(c.id) === String(this.contratoId));
                    if (elegido) {
                        this.marcarContrato(elegido, false);
                    } else {
                        this.contratoId = null;
                        if (libres.length === 1) { this.marcarContrato(libres[0], true); }
                    }
                } catch (e) {
                    this.errorFicha = 'No se pudo cargar la información del funcionario. Reintentá.';
                } finally {
                    this.cargandoFicha = false;
                }
            },
            // Las fechas salen del contrato; se pueden acortar, no estirar. El
            // inicio propuesto es hoy si el contrato ya empezó: arrancar en su
            // inicio controlaría hacia atrás meses que nadie marcó con este
            // turno, y los reportes se llenarían de faltas.
            marcarContrato(contrato, conFechas) {
                this.contratoId = contrato.id;
                this.minDesde = contrato.desde;
                this.maxHasta = contrato.hasta;
                if (conFechas) {
                    this.desde = contrato.desde > this.hoy() ? contrato.desde : this.hoy();
                    this.hasta = contrato.hasta || '';
                }
            },
            contratoElegido() {
                return this.ficha?.contratos.find(c => String(c.id) === String(this.contratoId)) || null;
            },
            // El selector de turno vive en su propio componente: el nombre del
            // elegido se toma del `change` que sube hasta el formulario.
            notarTurno(evento) {
                if (evento.target.name === 'turno_id') { this.turnoNombre = evento.target.dataset.nombre || ''; }
            },
            fechaTexto(iso) {
                if (! iso) { return ''; }
                const [anio, mes, dia] = iso.split('-');
                return `${dia}/${mes}/${anio}`;
            },
            hoy() {
                const fecha = new Date();
                return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
            },
            puedeAsignar() {
                return this.ficha !== null && (! this.ficha.mamore || this.contratoId !== null);
            },
        });
    </script>
@endonce

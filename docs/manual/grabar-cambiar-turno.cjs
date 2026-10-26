// Tutorial «Cambiar de turno: concluir el actual y asignar otro», desde la
// ficha del funcionario. Sigue a grabar-crear-y-asignar-turno.cjs: CI_DEMO
// tiene que tener vigente el turno TURNO_DEMO.
// ESCRIBE EN LA BASE: concluye ese turno el HASTA_DEMO y asigna TURNO_NUEVO.
// Uso: ver docs/manual/README.md.
const { abrir, guion } = require('./grabador.cjs');

const CI = process.env.CI_DEMO || '10799999';
const TURNO = process.env.TURNO_DEMO || 'Horario Continuo 07:30 - 15:30';
const TURNO_NUEVO = process.env.TURNO_NUEVO || 'Horario Continuo 08:00 - 16:00';
const HASTA = process.env.HASTA_DEMO || '2026-10-31';

guion(async () => {
  const { page, narrar, foco, clic, teclear, cargado, espera, grabar, terminar } = await abrir();
  const solapaTurnos = async () => {
    const boton = page.locator('.tabs__boton[data-tab="horarios"]');
    await foco(boton);
    await clic(boton);
    await cargado('#t-results');
  };

  await grabar();
  await narrar('Presentación', 'Cómo <b>cambiarle el turno</b> a un funcionario: se <b>concluye</b> el que tiene y se le <b>asigna otro</b>.', 4600);

  const funcionarios = page.locator('.sidebar a[href$="/funcionarios"]').first();
  await foco(funcionarios);
  await narrar('Abrir su ficha', 'Entre a <b>Funcionarios</b> y búsquelo por su cédula.', 2800);
  await clic(funcionarios); await foco(null);
  await page.waitForURL('**/funcionarios');
  await cargado('#div-results');
  const buscar = page.locator('#f-buscar');
  await foco(buscar);
  await clic(buscar);
  await teclear(buscar, CI);
  await buscar.press('Enter');
  await cargado('#div-results');
  const fila = page.locator('#div-results tbody tr', { hasText: CI }).first();
  await fila.waitFor({ timeout: 120000 });
  const ver = fila.locator('a[title="Ver"]');
  await foco(fila);
  await narrar('Abrir su ficha', 'Abra su ficha con el <b>ojo</b>.', 2400);
  await clic(ver); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#m-results');

  await solapaTurnos();
  const periodo = page.locator('#t-results tr.fila--periodo', { hasText: TURNO }).first();
  await foco(periodo);
  await narrar('Concluir el turno', 'En la pestaña <b>Turnos/Horarios</b> está el turno que tiene hoy, con sus fechas.', 4000);

  // Concluir
  const concluir = periodo.locator('button[title="Concluir turno"]');
  await foco(concluir);
  await narrar('Concluir el turno', 'Para cambiarlo, primero hay que <b>concluirlo</b>: presione el <b>check</b>.', 3400);
  await clic(concluir); await foco(null);
  const modal = page.locator('.modal-caja', { has: page.locator('#titulo-concluir-global') });
  await modal.waitFor();
  await espera(600);
  await foco(modal);
  await narrar('Concluir el turno', 'Ponga el <b>último día</b> con este turno. <b>No se borra</b>: queda como historia.', 4000);
  const hasta = page.locator('#hasta-concluir-global');
  await clic(hasta); await hasta.fill(HASTA); await espera(1000);
  const confirmar = modal.locator('button[type=submit]');
  await foco(confirmar);
  await narrar('Concluir el turno', 'Presione <b>Concluir</b>.', 2000);
  await clic(confirmar); await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#t-results');
  await espera(600);

  const concluido = page.locator('#t-results tr.fila--periodo', { hasText: TURNO }).first();
  await foco(concluido);
  await narrar('Concluir el turno', 'El turno ahora <b>termina ese día</b>.', 3200);

  // Asignar el nuevo
  // El primero es el que abre la ventana; el de adentro es el que guarda.
  const asignar = page.locator('[data-panel="horarios"] button.btn', { hasText: 'Asignar turno' }).first();
  await foco(asignar);
  await narrar('Asignar el turno nuevo', 'Ahora presione <b>Asignar turno</b>.', 2400);
  await clic(asignar); await foco(null);
  const ventana = page.locator('.modal-caja.asignar').first();
  await ventana.waitFor({ state: 'visible' });
  await page.locator('.asignar-contrato--elegido').first().waitFor({ timeout: 120000 });
  await espera(800);

  const contrato = ventana.locator('.asignar-contrato--elegido').first();
  await foco(contrato);
  await narrar('Asignar el turno nuevo', 'El sistema ya sabe qué falta cubrir: <b>desde el día siguiente</b> hasta el <b>fin del contrato</b>.', 4800);

  const opcion = ventana.locator('.turno-opcion', { hasText: TURNO_NUEVO }).first();
  await foco(opcion);
  await narrar('Asignar el turno nuevo', 'Elija el <b>turno nuevo</b>.', 2400);
  await clic(opcion.locator('.turno-opcion__nombre'));
  await espera(600);

  await foco(ventana.locator('.asignar-fechas'));
  await narrar('Asignar el turno nuevo', 'Las fechas vienen <b>fijas</b>: el turno nuevo empieza justo donde terminó el otro, <b>sin días sueltos</b>.', 4800);

  const pie = ventana.locator('.asignar__pie');
  await foco(pie);
  await narrar('Asignar el turno nuevo', 'Lea el resumen y presione <b>Asignar turno</b>.', 3200);
  await clic(pie.locator('button[type=submit]'));
  await foco(null);
  await page.waitForURL('**/funcionarios/mamore/**');
  await page.waitForLoadState('networkidle');
  await cargado('#t-results');
  await espera(800);

  await foco(page.locator('#t-results'));
  await narrar('Listo', 'Listo: en su contrato quedan <b>los dos turnos, uno detrás del otro</b>. Los reportes usan cada uno en sus fechas.', 5400);

  await terminar();
});

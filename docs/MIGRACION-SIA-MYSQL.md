# Migración SIA (SQL Server) → MySQL

Guía para migrar la información del sistema legado **SIA** (SQL Server 2008 R2,
solo lectura) a la base **MySQL local** del sistema, tabla por tabla.

**Meta:** que SisMark viva 100% en MySQL y deje de depender del SQL Server.

---

## 1. Cómo funciona

Cada tabla del SIA se migra en dos piezas:

1. **Migración local** (`database/migrations/*_create_<tabla>_table.php`): crea la
   tabla en MySQL con los mismos campos que el SIA, más `id` autoincremental,
   `timestamps` y `deleted_at` (eliminación lógica).
2. **Comando de copia** (`app/Console/Commands/Migrar<X>Sia.php`): lee del SQL
   Server (conexión `sia`, solo lectura) y hace `upsert` en la tabla local. Es
   **idempotente**: reejecutarlo no duplica.

El SQL Server **nunca se toca** (sigue solo lectura). Los comandos usan query
builder (`DB::table`) a propósito: el `upsert` masivo es mucho más rápido que
instanciar un modelo por fila (Asistencia ~4.4M filas).

### Convención de nombres

| | SIA (origen) | Local (MySQL) |
|---|---|---|
| Tablas | PascalCase (`Personas`, `DiaTurnos`) | **minúscula plural** (`personas`, `turnos`) |
| Columnas | PascalCase (`CodigoProfesion`, `IdPersona`) | **camelCase** (`codigoProfesion`, `ci`) |

Renombres fijos: `IdPersona` → `ci`, `CorreoE` → `correo`. Cada comando lleva un
array `MAPA` (columna origen ⇒ columna local) que hace la traducción y recorta
el relleno de espacios de los `char()` del SIA.

---

## 2. Ejecutar la migración

### Prerequisitos (una vez)

```bash
sudo systemctl start mysql       # MySQL arriba (destino)
php -m | grep pdo_sqlsrv          # debe listar pdo_sqlsrv (para leer el SQL Server)
```

Las credenciales del SIA (`DB_*_SIA`) ya van en el `.env`.

Si `pdo_sqlsrv` **no aparece** (error `could not find driver`), instalarlo.
En **Ubuntu 22.04 + PHP 8.3**:

```bash
# Headers de PHP + compilador
sudo apt-get update
sudo apt-get install -y php8.3-dev php-pear gcc g++ make autoconf unixodbc-dev

# Driver ODBC de Microsoft (repo Ubuntu 22.04)
curl https://packages.microsoft.com/keys/microsoft.asc | sudo tee /etc/apt/trusted.gpg.d/microsoft.asc
curl https://packages.microsoft.com/config/ubuntu/22.04/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18

# Compilar las extensiones (sqlsrv primero, luego pdo_sqlsrv)
sudo pecl install sqlsrv pdo_sqlsrv

# Habilitarlas en PHP CLI
printf "extension=sqlsrv.so\n"     | sudo tee /etc/php/8.3/cli/conf.d/20-sqlsrv.ini
printf "extension=pdo_sqlsrv.so\n" | sudo tee /etc/php/8.3/cli/conf.d/30-pdo_sqlsrv.ini

php -m | grep sqlsrv        # debe imprimir: pdo_sqlsrv  y  sqlsrv
```

### Crear las tablas

```bash
php artisan migrate
```

### Copiar los datos — todo de una (recomendado)

```bash
php artisan db:seed --class=MigrarSiaSeeder
```

**Este seeder primero corre `migrate:fresh --seed`** (⚠️ BORRA todas las tablas y
recrea el esquema + usuario/roles base), y luego corre los comandos de copia en el
orden correcto. Es una migración limpia completa cada vez. En entorno de tests no
hace el `migrate:fresh` (la BD ya viene fresca).

### Copiar los datos — comando por comando

```bash
php artisan sia:migrar-profesiones        # catálogo (rápido)
php artisan sia:migrar-personas           # funcionarios
php artisan sia:migrar-horarios           # turnos (antes de asignacion-turnos)
php artisan sia:migrar-marcaciones        # ~4.4M filas — tarda
php artisan sia:migrar-licencias          # permisos/vacaciones
php artisan sia:migrar-asignacion-turnos  # asignaciones (resuelve turno_id)
php artisan sia:migrar-dias-excepcionales # feriados/tolerancias (Calendario)
```

Cada comando acepta `--chunk=N` (filas por lote). Si algo falla, se reejecuta
sin duplicar. El **orden importa**: `asignacion_turnos` resuelve su FK `turno_id`
cruzando `idTurno` contra `turnos`, así que los horarios van antes.

### Verificar

```bash
php artisan tinker --execute 'echo DB::table("personas")->count()." personas, ".DB::table("asistencias")->count()." marcaciones\n";'
```

---

## 3. Tablas ya migradas

| SIA (origen) | Tabla local | Comando | Clave de upsert |
|---|---|---|---|
| `Personas` | `personas` | `sia:migrar-personas` | `ci` |
| `Asistencia` | `asistencias` | `sia:migrar-marcaciones` | `ci + fecha + hora` |
| `Profesiones` | `profesiones` | `sia:migrar-profesiones` | `codigoProfesion` |
| `DiaTurnos` | `turnos` | `sia:migrar-horarios` | `idTurno` |
| `Licencias` | `licencias` | `sia:migrar-licencias` | `ci + fecha + idTurno` |
| `AsignacionTurnos` | `asignacion_turnos` | `sia:migrar-asignacion-turnos` | `ci + idTurno + desde` |

`licencias` y `asignacion_turnos` conservan `idTurno` **y** tienen la FK `turno_id`
→ `turnos.id`, que el comando resuelve cruzando `idTurno` contra `turnos` (por eso
los horarios se migran antes; si no cruza, `turno_id` queda null).
| `Calendario` | `dias_excepcionales` | `sia:migrar-dias-excepcionales` | `fecha` |

Modelos locales (conexión MySQL por defecto): `App\Models\Persona`,
`App\Models\Asistencia`, `App\Models\Profesion`, `App\Models\Turno`,
`App\Models\Licencia`, `App\Models\AsignacionTurno`, `App\Models\DiaExcepcional`.

> Algunas tablas locales cambian de nombre respecto al SIA: `DiaTurnos`→`turnos`,
> `Calendario`→`dias_excepcionales`.

> **Tests:** al agregar una tabla del SIA, replicarla también en
> `tests/Pest.php` → `fakeSiaDatabase()` (schema con nombres del SIA, PascalCase)
> para que el test del comando pueda insertar datos de origen.

---

## 4. Agregar una tabla nueva

Para migrar otra tabla del SIA, repetir el patrón:

1. **Modelo + migración:**

   ```bash
   php artisan make:model NombreModelo -m
   ```

2. **Migración** (`database/migrations/*`): definir la tabla en **minúscula
   plural**, columnas en **camelCase**, con `id()`, `timestamps()` y
   `softDeletes()`. La clave de negocio del SIA (la PK legada) va como
   `unique()`. Copiar los tipos/longitudes del SIA — la referencia offline es
   `tests/Pest.php` (`fakeSiaDatabase()`), que replica las tablas del SIA real.

3. **Modelo** (`app/Models/*`): `$table` explícito (minúscula), `use SoftDeletes`,
   `$fillable` y `casts` en camelCase.

4. **Comando de copia:**

   ```bash
   php artisan make:command MigrarNombreSia
   ```

   Copiar de un comando existente (p. ej. `MigrarProfesionesSia`) y ajustar:
   - `#[Signature('sia:migrar-nombre ...')]`
   - `MAPA`: columna del SIA (PascalCase) ⇒ columna local (camelCase).
   - Tabla origen (`DB::connection('sia')->table('TablaSia')`) y destino
     (`->table('tabla_local')`).
   - Clave del `upsert` y columnas actualizables.
   - Tablas grandes (millones de filas): leer con `cursor()` en vez de
     `chunk()`, para no pagar el `ROW_NUMBER()` O(n²) del grammar 2008 (ver
     `MigrarMarcacionesSia`).

5. **Test** (`tests/Feature/MigrarNombreSiaTest.php`): usar `fakeSiaDatabase()`;
   probar copia, mapeo de columnas, idempotencia y que el origen queda intacto.

6. **Correr:**

   ```bash
   vendor/bin/pint --dirty
   php artisan test --compact --filter=MigrarNombreSia
   ```

---

## 5. El «flip» a MySQL: hecho

Toda la aplicación —tablero, marcaciones, funcionarios, reportes, horarios—
trabaja sobre MySQL. Nada del flujo normal toca el SQL Server.

**El SIA es solo origen, nunca destino.** Los comandos `sia:migrar-*` (y el
`MigrarSiaSeeder` que los agrupa) leen del SQL Server y copian a MySQL. En la
otra dirección no se escribe nada: el servicio que lo hacía,
`RegistroAsistenciaSia`, se eliminó al confirmarse que ningún otro sistema de la
institución espera recibir las marcaciones nuevas por ahí.

Consecuencia práctica: si hay que reconstruir la base local, se corre
`migrate:fresh` y después `db:seed --class=MigrarSiaSeeder`, que vuelve a traer
personas, marcaciones, licencias, turnos, asignaciones y días excepcionales. El
SQL Server queda intacto, pase lo que pase de este lado.

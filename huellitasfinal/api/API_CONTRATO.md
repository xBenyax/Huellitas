# Contrato de API — Huellitas (Frontend ⇄ PHP)

Este documento describe **exactamente** qué llama el frontend (`script.js`) y qué
espera recibir de vuelta. Los nombres de tabla/columna usados son los de la BD
`huellitas` ya definida por el equipo de Sistemas — no se propone ninguna tabla
ni columna nueva, salvo un punto marcado como **⚠️ A CONFIRMAR** más abajo.

Todas las rutas cuelgan de una base configurable en `script.js`:
```js
const API_BASE_URL = "/api"; // ajustar aquí si el backend vive en otra ruta
```
Todas las respuestas deben ser JSON con al menos `{ ok: true | false }`.
Si `ok:false`, se espera además `{ mensaje: "texto para mostrar al usuario" }`.

---

## 1. Sesión / USUARIO

### `POST /api/login.php`
Body (JSON): `{ identificador, contrasena }` — `identificador` es `nombre_real` o `correo`.
Respuesta: `{ ok:true, usuario:{ id_usuario, nombre_real, correo, zona, rol, id_albergue } }`

### `POST /api/registro.php`
Body: `{ nombre_real, correo, zona, contrasena }`
Respuesta: `{ ok:true, usuario:{ id_usuario, nombre_real, correo, zona, rol, id_albergue } }`
El backend debe validar unicidad de `nombre_real` y `correo`.

> **Nota:** el frontend ya no guarda la sesión en `localStorage`. Mientras no
> haya PHP conectado, la sesión vive solo en memoria (se pierde al recargar).
> Cuando conecten el backend, lo ideal es manejar sesión con `session_start()`
> / cookie de PHP y que el frontend solo confirme "¿hay sesión activa?" contra
> un endpoint tipo `GET /api/sesion.php`.

---

## 2. Inicio

### `GET /api/estadisticas.php`
Respuesta: `{ ok:true, total_mascotas, porcentaje_perros, porcentaje_gatos, en_adopcion, total_albergues }`
(`en_adopcion` = COUNT de ADOPCION con `estado = 'Publicada'`)

### `GET /api/mascotas.php?estado=Disponible&limit=3`
Ver sección 3 — se reutiliza el mismo endpoint para "Adopciones destacadas".

---

## 3. MASCOTA + ADOPCION (vista Mascotas)

### `GET /api/mascotas.php?especie=perro&estado=Disponible`
Respuesta:
```json
{ "ok": true, "mascotas": [
  { "id_mascota":1, "nombre_mascota":"Lola", "foto":null, "especie":"perro",
    "edad":"2 años", "genero":"hembra", "estado":"Disponible", "id_usuario":null,
    "adopcion": { "id_adopcion":1, "descripcion":"...", "estado":"Publicada", "fecha_publicacion":"2026-03-15" } }
]}
```
Join implícito: `MASCOTA` ⟶ `Publica` ⟶ `ADOPCION`. Si una mascota no tiene
adopción publicada, `"adopcion": null`.

### `GET /api/mascota.php?id_mascota=1`
Ficha completa. Respuesta:
```json
{ "ok": true,
  "mascota": { "id_mascota":1, "nombre_mascota":"Lola", "foto":null, "especie":"perro", "edad":"2 años", "genero":"hembra", "estado":"Disponible", "id_usuario":null },
  "albergue": { "id_albergue":1, "nombre":"...", "zona":"..." } ,
  "espacio": { "id_espacio":1, "numero":"A1", "tipo":"canil" },
  "adopcion": { "id_adopcion":1, "descripcion":"...", "estado":"Publicada" }
}
```
Join: `MASCOTA` ⟶ `MASCOTA_RESCATADA` ⟶ `ESPACIO` ⟶ `ALBERGUE`, más `ADOPCION`.
`albergue`/`espacio` van en `null` si la mascota no está en `MASCOTA_RESCATADA`
(por ejemplo, mascotas propias registradas por un usuario).

---

## 4. SOLICITUD_ADOPCION

### `POST /api/solicitudes_adopcion.php`
Body: `{ id_usuario, id_adopcion }`
El backend crea la fila con `estado = 'Pendiente'` y `fecha_solicitud = NOW()`.
Respuesta: `{ ok:true, id_solicitud }`

### `GET /api/solicitudes_adopcion.php?id_usuario=1`
Respuesta: `{ ok:true, solicitudes: [ { id_solicitud, id_adopcion, estado, fecha_solicitud } ] }`
El frontend usa esto para saber si el usuario ya solicitó una adopción y
mostrar "✓ Solicitud enviada" en vez del botón.

---

## 5. ALBERGUE + ESPACIO + MASCOTA_RESCATADA (vista Espacios)

### `GET /api/albergues.php`
Respuesta:
```json
{ "ok": true, "albergues": [
  { "id_albergue":1, "nombre":"...", "zona":"...",
    "espacios": [ { "id_espacio":1, "numero":"A1", "tipo":"canil", "capacidad":4, "ocupacion":2 } ] }
]}
```
`ocupacion` = `COUNT(*)` de `MASCOTA_RESCATADA` con ese `id_espacio` (join `Aloja`).

---

## 6. SALUD (vista Salud — solo mascotas propias del usuario)

### `GET /api/salud.php?id_usuario=1&rol=usuario`
Si `rol = veterinario`, el backend debe devolver **todas** las mascotas con
`id_usuario` no nulo (cualquier mascota registrada por un dueño), no solo las
del usuario en sesión.

Respuesta:
```json
{ "ok": true, "mascotas": [
  { "id_mascota":9, "nombre_mascota":"Pancho", "foto":null, "especie":"perro", "edad":"4 años", "genero":"macho",
    "salud": { "id_salud":1, "tipo_documento":"Carnet de vacunación", "foto_respaldo":null,
               "tratamientos_vigentes":"[{...}]" } }
]}
```

> **⚠️ A CONFIRMAR CON BD:** en el ERD, `tratamientos_vigentes` es **un solo
> atributo de texto** de `SALUD` (no una tabla propia de vacunas/tratamientos).
> Para poder mostrar una lista con fecha de inicio, fecha de fin y próxima
> dosis por cada vacuna/tratamiento, el frontend **lee y escribe ese campo
> como un string JSON** con esta forma:
> ```json
> [{ "tipo":"Vacuna", "nombre":"Antirrábica", "fecha_inicio":"2026-03-20",
>    "fecha_fin":null, "fecha_proxima":"2027-03-20", "observaciones":"..." }]
> ```
> El backend **no necesita interpretar este JSON**, solo guardarlo y
> devolverlo tal cual en la columna `tratamientos_vigentes` (es texto). Si el
> equipo de BD prefiere en cambio una tabla normalizada (una fila por vacuna/
> tratamiento), avísennos y lo cambiamos — no lo implementamos así sin
> confirmar porque no estaba en el diagrama.

### `POST /api/mascotas.php` (registrar mascota propia)
Body: `{ nombre_mascota, especie, edad, genero, id_usuario, tipo_documento, foto_respaldo }`
El backend debe crear la fila en `MASCOTA` (`estado` inicial sugerido:
`"Adoptado"`, es decir, ya vive con un dueño) **y** la fila en `SALUD`
correspondiente (`tratamientos_vigentes` inicial: `"[]"`).
Respuesta: `{ ok:true, id_mascota, id_salud }`

### `POST /api/salud_registro.php` (agregar vacuna/tratamiento)
Body: `{ id_salud, tratamientos_vigentes }` — el frontend envía el **arreglo
completo ya actualizado**, serializado a JSON, para sobrescribir la columna.
Respuesta: `{ ok:true }`

---

## 7. BUSQUEDA (vista Extravíos)

### `GET /api/busquedas.php`
Respuesta:
```json
{ "ok": true, "busquedas": [
  { "id_busqueda":1, "id_mascota":10, "id_usuario":null, "contacto":"700-11122",
    "descripcion":"...", "zona_perdida":"...", "fecha_perdida":"2026-08-25", "estado":"Perdido",
    "mascota": { "nombre_mascota":"Firulais", "especie":"perro" } }
]}
```

### `POST /api/busquedas.php` (crear reporte)
Body: `{ nombre_mascota, especie, zona_perdida, fecha_perdida, descripcion, contacto, id_usuario }`
`BUSQUEDA` exige una `MASCOTA` asociada (relación `Tiene`), así que el
backend debe crear primero la fila en `MASCOTA` (`estado:"Perdido"`) y luego
la fila en `BUSQUEDA` con ese `id_mascota`.
Respuesta: `{ ok:true, id_busqueda, id_mascota }`

### `POST /api/busqueda_estado.php` (reportar avistamiento → cambia estado)
Body: `{ id_busqueda, estado }` (el frontend envía `"En custodia temporal"`)
Respuesta: `{ ok:true }`

---

## 8. Función pendiente de definir: "Apadrinar"

La vista **Espacios** tenía un botón "Apadrinar a {mascota}". **Esta función
no está en el diagrama entidad-relación entregado** (no hay tabla de
apadrinamiento/sponsorship), así que **no se conectó a ningún endpoint**. El
botón se dejó visible en la interfaz pero, al presionarlo, solo muestra un
aviso de "función pendiente de definir con el equipo de BD" — no persiste
nada. Si quieren mantener esta función, nos dicen qué tabla/columnas usar (o
la definimos juntos) y la conectamos.

# 🐾 Huellitas — Gestión de albergues de perros y gatos

Sistema web para que los albergues gestionen a sus animalitos (perritos y gatitos): espacios, adopciones, traspasos, salud y extravíos.

🔗 **Ver en vivo:** https://huellitasusb-1.onrender.com/

**👤 USUARIOS DE PRUEBA:**

_Usuario Normal_
Usuario: Alvaro
Contraseña: 12345

_Usuario Gestor de Albergue_

Para iniciar sesion como gestor de albergue se va a la parte inferior de la pagina de Inicio, en la seccion de **Albergues** haciendo click en _Acceso para Gestores_

Usuario: Alvaro Huanca
Contraseña: 12345


> ⏳ El servidor gratuito se duerme tras unos minutos sin visitas. La primera carga puede tardar cerca de un minuto.

---

## ⭐ Cambio principal

**La página ahora tiene un único objetivo: ayudar a los albergues en la gestión de sus animalitos, perritos y gatitos, eliminando la dispersión y ofreciendo un camino claro.**

---

## 🩺 Mejora: Panel de Salud exclusivo para albergues

El control de salud ya no es una herramienta suelta para un usuario particular. Ahora es una pestaña **Salud** dentro del panel del albergue, junto a *Espacios* y *Extravíos*, y solo la ven los usuarios con rol `GESTOR_ALBERGUE`.

### Qué incluye
- **Etiquetas por animal** con su foto (enlace) y su nombre, mostrando solo los animales del propio albergue.
- **Dos secciones:** 🐶 Perros y 🐱 Gatos.
- **Tres estados** visibles en cada etiqueta:

| Estado | Cuándo se asigna | ¿Cómo cambia? |
|---|---|---|
| 🔴 **En tratamiento** | Tiene una vacuna o tratamiento con fecha de fin vigente | **Automático**: al vencer la fecha deja de contar |
| 🟡 **En observación** | Operación, castración, esterilización o desparasitación; también el estado inicial al ingresar | **Manual**: termina cuando el gestor pulsa **Dar de alta** |
| 🟢 **Saludable** | Sin tratamiento vigente y sin observación activa | Resultado de las reglas anteriores |

- **Prioridad de estados:** En tratamiento > En observación > Saludable.
- **Alta automática de la etiqueta:** al agregar un animal al albergue se crea su ficha de salud y empieza como **En observación**. Los animales que ya existían también aparecen en el panel.
- **Ventana de ficha médica:** al tocar una etiqueta se abre un modal con el historial y las mismas funciones del Control de salud (+ Nuevo registro), más el botón **Dar de alta**.
- **Tipos de registro:** Vacuna, Tratamiento, Operación, Castración, Esterilización y Desparasitación.
- **Fechas límite obligatorias** en los tratamientos, para que el estado se calcule solo.
- **El estado se guarda en la base de datos** (tabla `salud`, campo `estado`) y se recalcula cada vez que se consulta o se guarda un registro.

El control de salud del usuario particular para sus propias mascotas se mantiene como estaba.

---

## ✅ Mejora: Panel de Confirmación

Ahora el usuario podrá saber si su solicitud de adopción fue **aceptada o rechazada** en la misma página (sección *Mis solicitudes de adopción*), y además recibirá un **correo electrónico de confirmación**.

> ⚠️ **Nota:** para que se envíe el correo se requiere activar otro servicio. Probar el envío solo en la versión en vivo.

---

## 🔐 Otras mejoras

- **Credenciales fuera del código:** `api/conexion.php` ya no contiene contraseñas. Lee los datos de variables de entorno (producción) o de `api/config.php` (local, ignorado por Git).
- **Despliegue en la nube:** el proyecto se publica con **Docker** (PHP 8.2 + Apache) en Render, conectado a una base MySQL en Aiven.
- **Ruta de la API relativa** (`./api`): el mismo código funciona en XAMPP y en producción.
- **Corrección en el alta de animales:** la ficha de salud inicial ahora se crea con la estructura actual de la tabla.

---

## 🧰 Tecnologías

HTML · CSS · JavaScript · PHP 8.2 (PDO) · MySQL (Aiven) · Docker · Render

> Por seguridad, las credenciales reales **no** están en este repositorio. Quien revise el código verá solo la estructura del proyecto.

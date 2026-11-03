const API_BASE_URL = "/huellitasfinal/api"; // PONGAN LA DIRECCION URL ACA
// ID de cliente de Google (Google Cloud Console > Credenciales > ID de cliente OAuth, tipo "Aplicación web")
const GOOGLE_CLIENT_ID = "TU_CLIENT_ID.apps.googleusercontent.com";
const GMAIL_REGEX = /^[A-Za-z0-9._%+\-]+@gmail\.com$/i;
// Las fotos se toman directamente de la URL guardada en la columna "foto" de la BD (ej. https://i.ibb.co/xxxx/Red.png).
// Ya no se usa la carpeta /uploads.
function fotoUrl(foto){
  let f = (foto || "").toString().trim();
  if(!f || f.toLowerCase() === "null") return null;
  if(f.startsWith("//")) f = "https:" + f;                       // //i.ibb.co/...
  else if(!/^https?:\/\//i.test(f)){
    if(/^[\w-]+(\.[\w-]+)+\//.test(f)) f = "https://" + f;      // i.ibb.co/... sin protocolo
    else return null;                                             // nombres de archivo sueltos ya no se soportan
  }
  return encodeURI(decodeURI(f));                                 // evita doble codificación
}
window.avatarImgError = function(img){
  console.warn("[fotos] No se pudo cargar la imagen:", img.src);
  const emoji = img.dataset.fallback || "🐾";
  const wrap = img.parentElement;
  img.remove();
  const span = document.createElement("span");
  span.className = "avatar-emoji";
  span.textContent = emoji;
  wrap.appendChild(span);
};

function avatarHTML(m, cssClass){
  // Acepta distintos nombres de campo por si el PHP lo devuelve diferente
  const crudo = m.foto ?? m.url_foto ?? m.imagen ?? m.foto_url ?? null;
  if(crudo === null && !("foto" in m)) console.warn("[fotos] El API no devolvió el campo 'foto' para", m.nombre_mascota, "-> revisa el SELECT del PHP");
  const url = fotoUrl(crudo);
  const emoji = iconFor(m.especie);
  const alt = (m.nombre_mascota || "Mascota").replace(/"/g, "&quot;");
  if(!url) return `<div class="${cssClass}"><span class="avatar-emoji">${emoji}</span></div>`;
  return `<div class="${cssClass}"><img class="pet-avatar-img" src="${url}" alt="${alt}" loading="lazy" data-fallback="${emoji}" onerror="window.avatarImgError(this)"></div>`;
}
// Avatar pequeño (menú de cuenta, listas): foto si existe, emoji si no
function miniAvatarInner(m){
  const url = fotoUrl(m && (m.foto ?? m.url_foto ?? m.imagen ?? m.foto_url));
  const emoji = iconFor(m && m.especie);
  if(!url) return emoji;
  return `<img src="${url}" alt="" data-fallback="${emoji}" onerror="window.avatarImgError(this)" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">`;
}
async function apiRequest(path, { method = "GET", body = null } = {}){
  const opts = { method, headers: {} };
  if(body !== null){
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }
  let res;
  try{
    res = await fetch(API_BASE_URL + path, opts);
  }catch(networkErr){
    throw new Error("No se pudo conectar con el servidor.");
  }
  let data = null;
  try{ data = await res.json(); }catch(parseErr){  }
  if(!res.ok || !data || data.ok === false){
    throw new Error((data && data.mensaje) || "No se pudo completar la operación.");
  }
  return data;
}

const apiGet  = (path) => apiRequest(path, { method: "GET" });
const apiPost = (path, body) => apiRequest(path, { method: "POST", body });
let currentUser = null;          
let currentAlbergue = null;      
let pendingAfterLogin = null;
let misPerfiles = [];           
let currentProfileId = null;   

function esGestor(){ return !!currentUser && currentUser.rol === "GESTOR_ALBERGUE"; }
function esParticular(){ return !!currentUser && currentUser.rol === "PARTICULAR"; }

function setSession(user, albergue){
  currentUser = user;
  currentAlbergue = albergue || null;
  currentProfileId = null;
  misPerfiles = [];
  applyRoleVisibility();
  renderAccountArea();
}

async function refrescarPerfiles(){
  if(!currentUser) { misPerfiles = []; return; }
  try{
    const res = await apiGet(`/mis_mascotas.php?id_usuario=${currentUser.id_usuario}`);
    misPerfiles = res.mascotas;
    if(!misPerfiles.some(m => m.id_mascota === currentProfileId)){
      currentProfileId = misPerfiles.length ? misPerfiles[0].id_mascota : null;
    }
  }catch(err){ misPerfiles = []; }
}
function applyRoleVisibility(){
  const visible = esGestor();
  // Pestañas exclusivas de albergues: Espacios y Salud
  ["espacios", "saludAlbergue"].forEach(v => {
    const tab = document.querySelector(`.nav-tab[data-view="${v}"]`);
    if(tab) tab.style.display = visible ? "" : "none";
    const sec = document.getElementById("view-" + v);
    if(!visible && sec && sec.classList.contains("active")) goToView("inicio");
  });
}

const viewLoaded = { inicio: false, mascotas: false, espacios: false, salud: false, extravios: false };

function goToView(view){
  document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
  document.getElementById("view-" + view).classList.add("active");
  document.querySelectorAll(".nav-tab").forEach(t => t.classList.toggle("active", t.dataset.view === view));
  window.scrollTo({ top: 0, behavior: "smooth" });
  document.querySelector(".nav-inner").classList.remove("menu-open");
  loadView(view);
}

function loadView(view){
  if((view === "espacios" || view === "saludAlbergue") && !esGestor()){ goToView("inicio"); return; }
  if(view === "inicio") renderInicio();
  if(view === "mascotas") renderMascotas();
  if(view === "espacios") renderGestorPanel();
  if(view === "saludAlbergue") renderSaludAlbergue();
  if(view === "salud"){ renderSaludList(); renderSaludDetail(); }
  if(view === "extravios") renderExtravios();
}

document.getElementById("navTabs").addEventListener("click", (e) => {
  const btn = e.target.closest(".nav-tab");
  if(!btn) return;
  goToView(btn.dataset.view);
});
document.getElementById("ctaConoce").addEventListener("click", () => goToView("mascotas"));
document.getElementById("navBurger").addEventListener("click", () => {
  document.querySelector(".nav-inner").classList.toggle("menu-open");
});

function toast(msg){
  const t = document.getElementById("toast");
  t.textContent = msg;
  t.classList.add("show");
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => t.classList.remove("show"), 2800);
}
function fmtDate(str){
  if(!str) return "—";
  const d = new Date(str + "T00:00:00");
  return d.toLocaleDateString("es-BO", { day:"2-digit", month:"short", year:"numeric" });
}
function daysUntil(str){
  if(!str) return null;
  const today = new Date(); today.setHours(0,0,0,0);
  const target = new Date(str + "T00:00:00");
  return Math.round((target - today) / 86400000);
}
function iconFor(especie){ return especie === "perro" ? "🐕" : "🐈"; }

function loadingBlock(text){
  return `<div class="empty-state"><span class="empty-icon">🐾</span><p>${text || "Cargando..."}</p></div>`;
}
function errorBlock(text, retryLabel, retryFn){
  const id = "retry_" + Math.random().toString(36).slice(2,9);
  setTimeout(() => {
    const btn = document.getElementById(id);
    if(btn) btn.addEventListener("click", retryFn);
  }, 0);
  return `<div class="empty-state">
    <span class="empty-icon">🔌</span>
    <p>${text}</p>
    <button class="btn-primary" id="${id}" style="margin-top:6px;">${retryLabel || "Reintentar"}</button>
  </div>`;
}
function emptyBlock(text){
  return `<div class="empty-state"><span class="empty-icon">🐾</span><p>${text}</p></div>`;
}

function renderAccountArea(){
  const box = document.getElementById("accountArea");

  if(!currentUser){
    box.innerHTML = `<button class="account-btn login-cta" id="btnOpenLogin">🐾 Iniciar sesión</button>`;
    document.getElementById("btnOpenLogin").addEventListener("click", () => openAuthModal());
    return;
  }

  if(esGestor()){
    box.innerHTML = `
      <button class="account-btn" id="btnAccountToggle">
        <span class="account-avatar">🏠</span> ${currentUser.nombre_real} <span class="chev">⌄</span>
      </button>
      <div class="account-dropdown" id="accountDropdown">
        <div class="ad-name">${currentUser.nombre_real}</div>
        <div class="ad-meta">🏠 Gestor · ${currentAlbergue ? currentAlbergue.nombre : ""}</div>
        <button class="ad-logout" id="btnLogout">Cerrar sesión</button>
      </div>`;
    wireAccountDropdown();
    return;
  }

  const perfilActivo = misPerfiles.find(m => m.id_mascota === currentProfileId);
  const nombreMostrado = perfilActivo ? perfilActivo.nombre_mascota : currentUser.nombre_real;
  const avatar = perfilActivo ? miniAvatarInner(perfilActivo) : "🧑‍🦱";

  const listaPerfiles = misPerfiles.map(m => `
    <div class="profile-item ${m.id_mascota === currentProfileId ? "active" : ""}" data-perfil="${m.id_mascota}">
      <span class="mini-avatar">${miniAvatarInner(m)}</span> ${m.nombre_mascota}
    </div>`).join("");

  box.innerHTML = `
    <button class="account-btn" id="btnAccountToggle">
      <span class="account-avatar">${avatar}</span> ${nombreMostrado} <span class="chev">⌄</span>
    </button>
    <div class="account-dropdown" id="accountDropdown">
      <div class="ad-name">${currentUser.nombre_real}</div>
      <div class="ad-meta">🏠 Adoptante · ${currentUser.zona}</div>
      ${misPerfiles.length ? `<div class="profile-list">${listaPerfiles}</div>` : `<p style="font-size:12px;margin:0 0 10px;">Aún no registraste ninguna mascota.</p>`}
      ${misPerfiles.length ? `<button class="ad-add-profile" id="btnIrSalud">🩺 Control de salud de ${nombreMostrado}</button>` : ""}
      ${currentUser.correo && !Number(currentUser.correo_verificado) ? `<button class="ad-add-profile" id="btnVerificarCorreo">✉️ Verificar mi correo</button>` : ""}
      <button class="ad-add-profile" id="btnMisSolicitudesDD">📋 Mis solicitudes de adopción</button>
      <button class="ad-add-profile" id="btnAddPerfil">${misPerfiles.length ? "+ Registrar otra mascota" : "+ Registrar mi mascota"}</button>
      <button class="ad-logout" id="btnLogout">Cerrar sesión</button>
    </div>`;
  wireAccountDropdown();

  const btnIrSalud = document.getElementById("btnIrSalud");
  if(btnIrSalud) btnIrSalud.addEventListener("click", (e) => {
    e.stopPropagation();
    document.getElementById("accountDropdown").classList.remove("open");
    currentSaludId = currentProfileId;
    goToView("salud");
  });

  document.getElementById("btnMisSolicitudesDD").addEventListener("click", (e) => {
    e.stopPropagation();
    document.getElementById("accountDropdown").classList.remove("open");
    abrirMisSolicitudes();
  });
  const btnVerCorreo = document.getElementById("btnVerificarCorreo");
  if(btnVerCorreo) btnVerCorreo.addEventListener("click", (e) => {
    e.stopPropagation();
    document.getElementById("accountDropdown").classList.remove("open");
    abrirModalVerificar(currentUser.correo, false, false);
  });

  document.getElementById("btnAddPerfil").addEventListener("click", (e) => {
    e.stopPropagation();
    if(misPerfiles.length >= 5){ toast("Ya registraste el máximo de 5 mascotas por usuario."); return; }
    document.getElementById("accountDropdown").classList.remove("open");
    abrirModalRegistrarMascota(false);
  });

  document.querySelectorAll(".profile-item").forEach(item => {
    item.addEventListener("click", (e) => {
      e.stopPropagation();
      currentProfileId = Number(item.dataset.perfil);
      currentSaludId = currentProfileId;
      renderAccountArea();
      if(document.getElementById("view-salud").classList.contains("active")){ renderSaludList(); renderSaludDetail(); }
    });
  });
}
function wireAccountDropdown(){
  document.getElementById("btnAccountToggle").addEventListener("click", (e) => {
    e.stopPropagation();
    document.getElementById("accountDropdown").classList.toggle("open");
  });
  document.getElementById("btnLogout").addEventListener("click", () => {
    setSession(null);
    toast("Cerraste sesión. ¡Vuelve pronto! 🐾");
    loadView(document.querySelector(".view.active").id.replace("view-", ""));
  });
}
document.addEventListener("click", () => {
  const dd = document.getElementById("accountDropdown");
  if(dd) dd.classList.remove("open");
});

function abrirModalRegistrarMascota(esPrimeraVez){
  document.getElementById("formRegistrarMascota").reset();
  document.getElementById("registrarMascotaTitle").textContent = esPrimeraVez ? "Registrar mascota" : "Registrar otra mascota";
  document.getElementById("registrarMascotaSub").textContent = esPrimeraVez
    ? "¡Ya casi! Cuéntanos sobre tu huellita para llevar su ficha médica y calendario de vacunas."
    : "Puedes registrar hasta 5 mascotas por cuenta.";
  document.getElementById("registrarMascotaHint").style.display = misPerfiles.length ? "block" : "none";
  document.getElementById("modalRegistrarMascota").classList.add("open");
}

function openAuthModal(message){
  document.getElementById("authSub").textContent = message || "Ingresa para adoptar, apadrinar y cuidar la salud de tus huellitas.";
  switchAuthTab("login");
  document.getElementById("modalAuth").classList.add("open");
}
function switchAuthTab(tab){
  document.querySelectorAll("#modalAuth .auth-tab").forEach(t => t.classList.toggle("active", t.dataset.auth === tab));
  document.getElementById("formLogin").style.display = tab === "login" ? "flex" : "none";
  document.getElementById("formRegister").style.display = tab === "register" ? "flex" : "none";
}
document.querySelectorAll("#modalAuth .auth-tab").forEach(btn => btn.addEventListener("click", () => switchAuthTab(btn.dataset.auth)));

document.getElementById("formLogin").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Entrando...";
  try{
    const res = await apiPost("/login.php", { nombre_real: data.nombre_real, contrasena: data.contrasena });
    setSession(res.usuario);
    await refrescarPerfiles();
    renderAccountArea();
    e.target.reset();
    closeModals();
    toast(`¡Bienvenido/a, ${res.usuario.nombre_real}! 🐾`);
    loadView(document.querySelector(".view.active").id.replace("view-", ""));
    if(pendingAfterLogin){ const fn = pendingAfterLogin; pendingAfterLogin = null; fn(); }
  }catch(err){
    toast(err.message || "No se pudo iniciar sesión.");
  }finally{
    btn.disabled = false; btn.textContent = "Entrar";
  }
});

document.getElementById("formRegister").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  const correo = (data.correo || "").trim().toLowerCase();
  if(!GMAIL_REGEX.test(correo)){ toast("Ingresa un correo de Gmail válido (@gmail.com)."); return; }
  btn.disabled = true; btn.textContent = "Creando...";
  try{
    const res = await apiPost("/registro.php", { nombre_real: data.nombre_real.trim(), zona: data.zona.trim(), contrasena: data.contrasena, correo });
    setSession(res.usuario);
    await refrescarPerfiles();
    renderAccountArea();
    e.target.reset();
    closeModals();
    // Envía un código de 6 dígitos al Gmail indicado; al confirmarlo se abre "Registrar Mascota".
    try{
      await apiPost("/enviar_verificacion.php", { id_usuario: res.usuario.id_usuario, correo });
      abrirModalVerificar(correo, true, true);
    }catch(mailErr){
      toast(`Cuenta creada, pero no se pudo enviar el código: ${mailErr.message}`);
      abrirModalRegistrarMascota(true);
    }
    if(pendingAfterLogin){ const fn = pendingAfterLogin; pendingAfterLogin = null; fn(); }
  }catch(err){
    toast(err.message || "No se pudo crear la cuenta.");
  }finally{
    btn.disabled = false; btn.textContent = "Crear cuenta";
  }
});

document.getElementById("formRegistrarMascota").addEventListener("submit", async (e) => {
  e.preventDefault();
  if(!currentUser) return;
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Registrando...";
  try{
    const res = await apiPost("/mascotas.php", {
      nombre_mascota: data.nombre_mascota, foto: data.foto || null, especie: data.especie, edad: data.edad,
      genero: data.genero, id_usuario: currentUser.id_usuario
    });
    e.target.reset();
    closeModals();
    await refrescarPerfiles();
    currentProfileId = res.id_mascota;
    currentSaludId = res.id_mascota;
    renderAccountArea();
    toast(`${data.nombre_mascota} fue registrada. ¡Ya puedes llevar su control de salud!`);
    if(document.getElementById("view-salud").classList.contains("active")){ renderSaludList(); renderSaludDetail(); }
  }catch(err){
    toast(err.message || "No se pudo registrar la mascota.");
  }finally{
    btn.disabled = false; btn.textContent = "Registrar mascota";
  }
});

document.getElementById("footerGestorLink").addEventListener("click", (e) => {
  e.preventDefault();
  switchAuthTabGestor("login");
  document.getElementById("modalAuthGestor").classList.add("open");
});
function switchAuthTabGestor(tab){
  document.querySelectorAll("#modalAuthGestor .auth-tab").forEach(t => t.classList.toggle("active", t.dataset.authGestor === tab));
  document.getElementById("formLoginGestor").style.display = tab === "login" ? "flex" : "none";
  document.getElementById("formRegisterGestor").style.display = tab === "register" ? "flex" : "none";
}
document.querySelectorAll("#modalAuthGestor .auth-tab").forEach(btn => btn.addEventListener("click", () => switchAuthTabGestor(btn.dataset.authGestor)));

document.getElementById("formLoginGestor").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Entrando...";
  try{
    const res = await apiPost("/gestor_login.php", { nombre_real: data.nombre_real, contrasena: data.contrasena });
    setSession(res.usuario, res.albergue);
    e.target.reset();
    closeModals();
    toast(`¡Bienvenido/a, ${res.usuario.nombre_real}! Gestionas ${res.albergue ? res.albergue.nombre : "tu albergue"}. 🏠`);
    goToView("espacios");
  }catch(err){
    toast(err.message || "No se pudo iniciar sesión.");
  }finally{
    btn.disabled = false; btn.textContent = "Entrar";
  }
});
document.getElementById("formRegisterGestor").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Creando...";
  try{
    const res = await apiPost("/gestor_registro.php", {
      nombre_real: data.nombre_real.trim(), nombre_albergue: data.nombre_albergue.trim(),
      codigo_acceso: data.codigo_acceso.trim(), contrasena: data.contrasena
    });
    setSession(res.usuario, res.albergue);
    e.target.reset();
    closeModals();
    toast(`Cuenta de gestor creada. ¡Bienvenido/a, ${res.usuario.nombre_real}! 🏠`);
    goToView("espacios");
  }catch(err){
    toast(err.message || "No se pudo crear la cuenta de gestor.");
  }finally{
    btn.disabled = false; btn.textContent = "Crear cuenta de gestor";
  }
});

function requireLogin(message, action){
  if(currentUser){ action(); return; }
  pendingAfterLogin = action;
  openAuthModal(message);
}

async function renderInicio(){
  const statsGrid = document.getElementById("statsGrid");
  const destacadosRow = document.getElementById("destacadosRow");
  statsGrid.innerHTML = loadingBlock("Cargando estadísticas...");
  destacadosRow.innerHTML = loadingBlock("Cargando adopciones destacadas...");

  try{
    const stats = await apiGet("/estadisticas.php");
    statsGrid.innerHTML = [
      { num: stats.total_mascotas, label: "Mascotas registradas" },
      { num: `${stats.porcentaje_perros}%`, label: "Perros en el sistema" },
      { num: `${stats.porcentaje_gatos}%`, label: "Gatos en el sistema" },
      { num: stats.en_adopcion, label: "Listas para adopción" }
    ].map(s => `<div class="stat-card"><div class="stat-num">${s.num}</div><div class="stat-label">${s.label}</div></div>`).join("");
  }catch(err){
    statsGrid.innerHTML = errorBlock("No se pudo conectar con el servidor de estadísticas.", "Reintentar", renderInicio);
  }

  try{
    const res = await apiGet("/mascotas.php?estado=Disponible&limit=3");
    destacadosRow.innerHTML = res.mascotas.length
      ? res.mascotas.map(m => petCardHTML(m)).join("")
      : emptyBlock("Todavía no hay adopciones publicadas.");
  }catch(err){
    destacadosRow.innerHTML = errorBlock("No se pudo conectar con el servidor para cargar las mascotas destacadas.", "Reintentar", renderInicio);
  }
}

function petCardHTML(m){
  const adopcion = m.adopcion;
  const yaSolicitada = adopcion && m.solicitud_enviada;
  return `
    <div class="pet-card" data-id="${m.id_mascota}">
      <div class="pet-card-top">
        ${avatarHTML(m, "pet-avatar")}
        <div class="pet-card-top-right"><span class="paw-mini">🐾</span><span class="heart-mini">❤</span></div>
      </div>
      <h3>${m.nombre_mascota}</h3>
      <div class="pet-meta">${m.especie === "perro" ? "Perro" : "Gato"} · ${m.edad || "—"}</div>
      ${adopcion ? `
        <p class="pet-desc-link" data-solicitar="${adopcion.id_adopcion}" title="Toca para solicitar la adopción">
          ${adopcion.descripcion} <span class="cta-adopt">· Solicitar adopción →</span>
        </p>` : `<p class="pet-desc-link" style="cursor:default;">Sin descripción publicada.</p>`}
      ${yaSolicitada ? `<span class="badge-sent">✓ Solicitud enviada</span>` : `<span class="badge-estado">${m.estado}</span>`}
    </div>`;
}

let currentSpecies = "perro";

async function renderMascotas(){
  const grid = document.getElementById("petsGrid");
  grid.innerHTML = loadingBlock("Cargando mascotas en adopción...");
  try{
    const res = await apiGet(`/mascotas.php?especie=${currentSpecies}&estado=Disponible`);
    grid.innerHTML = res.mascotas.length
      ? res.mascotas.map(m => petCardHTML(m)).join("")
      : emptyBlock(`No hay ${currentSpecies === "perro" ? "perros" : "gatos"} en adopción por ahora.`);
  }catch(err){
    grid.innerHTML = errorBlock("No se pudo conectar con el servidor para cargar las mascotas.", "Reintentar", renderMascotas);
  }
}

document.querySelectorAll(".species-toggle .toggle-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".species-toggle .toggle-btn").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    currentSpecies = btn.dataset.species;
    renderMascotas();
  });
});

async function openFichaMascota(id){
  const body = document.getElementById("modalMascotaBody");
  document.getElementById("modalMascota").classList.add("open");
  body.innerHTML = loadingBlock("Cargando ficha...");
  try{
    const res = await apiGet(`/mascota.php?id_mascota=${id}`);
    const m = res.mascota, adopcion = res.adopcion, espacio = res.espacio, albergue = res.albergue;
    body.innerHTML = `
      <div class="ficha-header">
        ${avatarHTML(m, "ficha-avatar")}
        <div><h2 style="margin-bottom:2px;">${m.nombre_mascota}</h2><p style="margin:0;">${m.especie === "perro" ? "Perro" : "Gato"}</p></div>
      </div>
      <div class="ficha-tags">
        <span class="tag">${m.genero === "hembra" ? "Hembra" : "Macho"}</span>
        <span class="tag">${m.edad || "—"}</span>
        ${espacio && albergue ? `<span class="tag">${albergue.nombre} · ${espacio.tipo === "GATOS" ? "🐱" : "🐶"} ${espacio.numero}</span>` : ""}
      </div>
      <span class="badge-estado">${m.estado}</span>
      <div class="ficha-obs"><strong>Descripción:</strong> ${adopcion ? adopcion.descripcion : "Sin descripción pública."}</div>
      ${adopcion ? `<div class="ficha-actions"><button class="btn-primary full" data-solicitar="${adopcion.id_adopcion}">Solicitar adopción</button></div>` : ""}
    `;
  }catch(err){
    body.innerHTML = errorBlock("No se pudo conectar con el servidor para cargar la ficha.", "Reintentar", () => openFichaMascota(id));
  }
}

function cardClickHandler(e){
  const descLink = e.target.closest("[data-solicitar]");
  if(descLink){ e.stopPropagation(); openSolicitudModal(Number(descLink.dataset.solicitar)); return; }
  const card = e.target.closest(".pet-card");
  if(card) openFichaMascota(Number(card.dataset.id));
}
document.getElementById("destacadosRow").addEventListener("click", cardClickHandler);
document.getElementById("petsGrid").addEventListener("click", cardClickHandler);
document.getElementById("modalMascotaBody").addEventListener("click", (e) => {
  const btn = e.target.closest("[data-solicitar]");
  if(btn) openSolicitudModal(Number(btn.dataset.solicitar));
});

function openSolicitudModal(idAdopcion){
  document.getElementById("modalMascota").classList.remove("open");
  requireLogin("Inicia sesión para solicitar la adopción de esta huellita.", async () => {
    const bodyEl = document.getElementById("solicitudBody");
    document.getElementById("modalSolicitud").classList.add("open");
    bodyEl.innerHTML = loadingBlock("Preparando solicitud...");
    bodyEl.innerHTML = `
      <p class="solicitud-note">Vas a enviar una solicitud de adopción para esta huellita. El albergue revisará tu petición y se pondrá en contacto contigo.</p>
      <button class="btn-primary full" id="btnConfirmarSolicitud">Confirmar solicitud de adopción</button>
    `;
    document.getElementById("btnConfirmarSolicitud").addEventListener("click", async () => {
      const btn = document.getElementById("btnConfirmarSolicitud");
      btn.disabled = true; btn.textContent = "Enviando...";
      try{
        await apiPost("/solicitudes_adopcion.php", { id_usuario: currentUser.id_usuario, id_adopcion: idAdopcion });
        toast("¡Solicitud enviada! El albergue revisará tu petición. 💚");
        closeModals();
        renderMascotas(); renderInicio();
      }catch(err){
        toast(err.message || "No se pudo enviar la solicitud.");
        btn.disabled = false; btn.textContent = "Confirmar solicitud de adopción";
      }
    });
  });
}

function gestorPetCardHTML(m){
  const yaPublicada = m.adopcion && m.adopcion.estado === "DISPONIBLE";
  const enProceso = m.adopcion && m.adopcion.estado === "EN_PROCESO";
  const finalizada = m.estado === "ADOPTADA" || m.estado === "TRASPASADA";
  return `
    <div class="pet-card gestor-card" data-id="${m.id_mascota}">
      <div class="pet-card-top">
        ${avatarHTML(m, "pet-avatar")}
        <div class="pet-card-top-right"><span class="paw-mini">🐾</span></div>
      </div>
      <h3>${m.nombre_mascota}</h3>
      <div class="pet-meta">${m.genero === "hembra" ? "Hembra" : "Macho"} · ${m.edad}</div>
      <span class="badge-estado">${m.estado_texto}</span>
      <div class="gestor-card-actions">
        ${(!yaPublicada && !enProceso && !finalizada) ? `<button data-publicar="${m.id_mascota}">Publicar en adopción</button>` : ""}
        ${enProceso ? `<button data-finalizar="${m.adopcion.id_adopcion}">Marcar adopción finalizada</button>` : ""}
        ${!finalizada ? `<button data-traspaso="${m.id_mascota}" class="btn-danger">Solicitar traspaso</button>` : ""}
      </div>
    </div>`;
}

async function renderGestorPanel(){
  if(!esGestor() || !currentAlbergue) return;
  document.getElementById("gestorAlbergueTitle").textContent = `Panel de ${currentAlbergue.nombre}`;

  const perrosGrid = document.getElementById("gestorPerrosGrid");
  const gatosGrid = document.getElementById("gestorGatosGrid");
  const solicitudesBox = document.getElementById("gestorSolicitudesList");
  const traspasosBox = document.getElementById("gestorTraspasosList");
  const groups = document.getElementById("albergueGroups");
  perrosGrid.innerHTML = loadingBlock("Cargando...");
  gatosGrid.innerHTML = loadingBlock("Cargando...");
  solicitudesBox.innerHTML = loadingBlock("Cargando solicitudes...");
  traspasosBox.innerHTML = loadingBlock("Cargando traspasos...");
  groups.innerHTML = loadingBlock("Cargando ocupación...");

  try{
    const res = await apiGet(`/gestor_panel.php?id_albergue=${currentAlbergue.id_albergue}`);

    perrosGrid.innerHTML = res.perros.length ? res.perros.map(gestorPetCardHTML).join("") : emptyBlock("Aún no hay perros registrados en tu albergue.");
    gatosGrid.innerHTML = res.gatos.length ? res.gatos.map(gestorPetCardHTML).join("") : emptyBlock("Aún no hay gatos registrados en tu albergue.");

    solicitudesBox.innerHTML = res.solicitudes.length ? `<div class="gestor-list">${res.solicitudes.map(s => `
      <div class="gestor-list-row">
        <div class="glr-main"><b>${s.mascota}</b> — solicitado por ${s.usuario}
          <div class="glr-meta">${fmtDate(s.fecha_solicitud)} · Estado: ${s.estado}</div>
        </div>
        <div class="glr-actions">
          ${s.estado === "PENDIENTE" ? `
            <button class="glr-approve" data-sol-aprobar="${s.id_solicitud}">Aprobar</button>
            <button class="glr-reject" data-sol-rechazar="${s.id_solicitud}">Rechazar</button>` : ""}
        </div>
      </div>`).join("")}</div>` : emptyBlock("No hay solicitudes de adopción todavía.");

    traspasosBox.innerHTML = res.traspasos.length ? `<div class="gestor-list">${res.traspasos.map(t => `
      <div class="gestor-list-row">
        <div class="glr-main"><b>${t.mascota}</b> → ${t.destino_nombre}
          <div class="glr-meta">${fmtDate(t.fecha_solicitud)} · Estado: ${t.estado}</div>
        </div>
        <div class="glr-actions">
          ${t.estado === "SOLICITADO" ? `<button class="glr-approve" data-tr-aceptar="${t.id_traspaso}">Aceptar</button><button class="glr-reject" data-tr-rechazar="${t.id_traspaso}">Rechazar</button>` : ""}
          ${t.estado === "ACEPTADO" ? `<button class="glr-neutral" data-tr-finalizar="${t.id_traspaso}">Finalizar</button>` : ""}
        </div>
      </div>`).join("")}</div>` : emptyBlock("No hay traspasos en curso.");

    groups.innerHTML = res.espacios.map(e => {
      const pct = Math.round((e.ocupacion / e.capacidad) * 100);
      return `
        <div class="espacio-card">
          <h4>Espacio ${e.numero} <span class="paw-mini">${e.tipo === "GATOS" ? "🐱" : "🐶"}</span></h4>
          <div class="esp-tipo">${e.tipo === "GATOS" ? "Gatera" : "Canil"}</div>
          <div class="bar-track"><div class="bar-fill ${pct>=100?'bar-full':''}" style="width:${pct}%"></div></div>
          <div class="esp-count">${e.ocupacion} / ${e.capacidad} ocupado${e.ocupacion===1?"":"s"}</div>
        </div>`;
    }).join("");

    const selEspacio = document.getElementById("gestorMascotaEspacio");
    selEspacio.innerHTML = res.espacios.map(e => `<option value="${e.id_espacio}">Espacio ${e.numero} (${e.tipo === "GATOS" ? "Gatera" : "Canil"}) — ${e.ocupacion}/${e.capacidad}</option>`).join("");
    const selDestino = document.getElementById("traspasoAlbergueDestino");
    try{
      const albRes = await apiGet("/albergues.php"); 
      selDestino.innerHTML = albRes.albergues
        .filter(a => a.id_albergue !== currentAlbergue.id_albergue)
        .map(a => `<option value="${a.id_albergue}">${a.nombre}</option>`).join("");
    }catch(err){
      selDestino.innerHTML = "";
    }
  }catch(err){
    perrosGrid.innerHTML = errorBlock("No se pudo conectar con el servidor.", "Reintentar", renderGestorPanel);
  }
}

document.getElementById("btnAgregarPerro").addEventListener("click", () => abrirModalGestorMascota("perro"));
document.getElementById("btnAgregarGato").addEventListener("click", () => abrirModalGestorMascota("gato"));
function abrirModalGestorMascota(especie){
  document.getElementById("formGestorMascota").reset();
  document.getElementById("gestorMascotaEspecie").value = especie;
  document.getElementById("gestorMascotaTitle").textContent = especie === "perro" ? "Agregar perro al albergue" : "Agregar gato al albergue";
  document.getElementById("modalGestorMascota").classList.add("open");
}
document.getElementById("formGestorMascota").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Agregando...";
  try{
    await apiPost("/gestor_agregar_animal.php", {
      id_albergue: currentAlbergue.id_albergue, nombre_mascota: data.nombre_mascota, foto: data.foto || null,
      especie: data.especie, edad: data.edad, genero: data.genero, id_espacio: data.id_espacio
    });
    e.target.reset();
    closeModals();
    toast(`${data.nombre_mascota} fue agregado a tu albergue.`);
    renderGestorPanel();
  }catch(err){
    toast(err.message || "No se pudo agregar el animal.");
  }finally{
    btn.disabled = false; btn.textContent = "Agregar al albergue";
  }
});

let idMascotaAPublicar = null;
let idMascotaATraspasar = null;

function gestorGridClickHandler(e){
  const publicar = e.target.closest("[data-publicar]");
  const finalizar = e.target.closest("[data-finalizar]");
  const traspaso = e.target.closest("[data-traspaso]");
  if(publicar){
    idMascotaAPublicar = Number(publicar.dataset.publicar);
    document.getElementById("formPublicarAdopcion").reset();
    document.getElementById("publicarAdopcionSub").textContent = "Escribe una descripción para que los adoptantes la conozcan.";
    document.getElementById("modalPublicarAdopcion").classList.add("open");
    return;
  }
  if(finalizar){
    const idAdopcion = Number(finalizar.dataset.finalizar);
    apiPost("/gestor_marcar_finalizada.php", { id_adopcion: idAdopcion })
      .then(() => { toast("Adopción marcada como finalizada. 💚"); renderGestorPanel(); })
      .catch(err => toast(err.message || "No se pudo marcar como finalizada."));
    return;
  }
  if(traspaso){
    idMascotaATraspasar = Number(traspaso.dataset.traspaso);
    document.getElementById("formTraspaso").reset();
    document.getElementById("traspasoSub").textContent = "Elige el albergue destino para esta mascota.";
    document.getElementById("modalTraspaso").classList.add("open");
  }
}
document.getElementById("gestorPerrosGrid").addEventListener("click", gestorGridClickHandler);
document.getElementById("gestorGatosGrid").addEventListener("click", gestorGridClickHandler);

document.getElementById("formPublicarAdopcion").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Publicando...";
  try{
    await apiPost("/gestor_publicar_adopcion.php", { id_mascota: idMascotaAPublicar, id_albergue: currentAlbergue.id_albergue, descripcion: data.descripcion });
    closeModals();
    toast("¡Publicado! Ya aparece en la sección Mascotas.");
    renderGestorPanel();
  }catch(err){
    toast(err.message || "No se pudo publicar la adopción.");
  }finally{
    btn.disabled = false; btn.textContent = "Publicar adopción";
  }
});

document.getElementById("formTraspaso").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Solicitando...";
  try{
    await apiPost("/gestor_traspaso.php", { id_mascota_origen: idMascotaATraspasar, id_albergue: currentAlbergue.id_albergue, id_albergue_destino: Number(data.id_albergue_destino) });
    closeModals();
    toast("Traspaso solicitado.");
    renderGestorPanel();
  }catch(err){
    toast(err.message || "No se pudo solicitar el traspaso.");
  }finally{
    btn.disabled = false; btn.textContent = "Solicitar traspaso";
  }
});

document.getElementById("gestorSolicitudesList").addEventListener("click", (e) => {
  const aprobar = e.target.closest("[data-sol-aprobar]");
  const rechazar = e.target.closest("[data-sol-rechazar]");
  if(aprobar) accionSolicitud(Number(aprobar.dataset.solAprobar), "APROBADA");
  if(rechazar) accionSolicitud(Number(rechazar.dataset.solRechazar), "RECHAZADA");
});
function accionSolicitud(id_solicitud, estado){
  apiPost("/gestor_solicitud_estado.php", { id_solicitud, estado })
    .then((res) => {
      const base = estado === "APROBADA" ? "Solicitud aprobada." : "Solicitud rechazada.";
      toast(res.correo_enviado ? `${base} Se avisó al adoptante por correo 📧` : base);
      renderGestorPanel();
    })
    .catch(err => toast(err.message || "No se pudo actualizar la solicitud."));
}

document.getElementById("gestorTraspasosList").addEventListener("click", (e) => {
  const aceptar = e.target.closest("[data-tr-aceptar]");
  const rechazar = e.target.closest("[data-tr-rechazar]");
  const finalizar = e.target.closest("[data-tr-finalizar]");
  if(aceptar) accionTraspaso(Number(aceptar.dataset.trAceptar), "ACEPTADO");
  if(rechazar) accionTraspaso(Number(rechazar.dataset.trRechazar), "RECHAZADO");
  if(finalizar) accionTraspaso(Number(finalizar.dataset.trFinalizar), "FINALIZADO");
});
function accionTraspaso(id_traspaso, estado){
  apiPost("/gestor_traspaso_estado.php", { id_traspaso, estado })
    .then(() => { toast("Traspaso actualizado."); renderGestorPanel(); })
    .catch(err => toast(err.message || "No se pudo actualizar el traspaso."));
}

let currentSaludId = null;
let saludMascotasCache = [];

function parseTratamientos(saludRow){
  if(!saludRow || !saludRow.tratamientos_vigentes) return [];
  try{ const arr = JSON.parse(saludRow.tratamientos_vigentes); return Array.isArray(arr) ? arr : []; }
  catch(e){ return []; }
}

function recordStatus(r){
  if(r.tipo === "Alta") return { cls: "chip-ok", label: "Alta", dot: "dot-ok" };
  if(TIPOS_OBSERVACION.includes(r.tipo)){
    return r.activa === false
      ? { cls: "chip-ok", label: "Resuelta", dot: "dot-ok" }
      : { cls: "chip-warn", label: "En observación", dot: "dot-warn" };
  }
  if(r.tipo === "Vacuna"){
    if(!r.fecha_proxima) return { cls: "chip-ok", label: "Aplicada", dot: "dot-ok" };
    const d = daysUntil(r.fecha_proxima);
    if(d < 0) return { cls: "chip-late", label: "Vencida", dot: "dot-late" };
    if(d <= 30) return { cls: "chip-warn", label: "Próxima", dot: "dot-warn" };
    return { cls: "chip-ok", label: "Al día", dot: "dot-ok" };
  }
  if(r.fecha_fin){
    const d = daysUntil(r.fecha_fin);
    return d < 0 ? { cls: "chip-ok", label: "Finalizado", dot: "dot-ok" } : { cls: "chip-warn", label: "En curso", dot: "dot-warn" };
  }
  return { cls: "chip-warn", label: "En curso", dot: "dot-warn" };
}

async function renderSaludList(){
  const list = document.getElementById("saludList");

  if(!currentUser){
    list.innerHTML = `
      <div class="salud-list-head">
        <div class="login-required">
          <div class="li-icon">🔒</div>
          <p style="font-size:13px;">Inicia sesión para ver el control de salud de tus mascotas.</p>
          <button class="btn-primary" id="btnLoginFromSalud" style="margin-top:8px;">Iniciar sesión</button>
        </div>
      </div>`;
    document.getElementById("btnLoginFromSalud").addEventListener("click", () => openAuthModal());
    return;
  }

  list.innerHTML = loadingBlock("Cargando tus mascotas...");
  try{
    const res = await apiGet(`/salud.php?id_usuario=${currentUser.id_usuario}&rol=${currentUser.rol}`);
    saludMascotasCache = res.mascotas;
    if(currentSaludId == null && saludMascotasCache.length) currentSaludId = saludMascotasCache[0].id_mascota;

    const header = `
      <div class="salud-list-head">
        <h4>Tus mascotas</h4>
        <button class="add-mini-btn" id="btnRegistrarMascota">+ Registrar mi mascota</button>
      </div>`;

    const items = saludMascotasCache.map(m => {
      const registros = parseTratamientos(m.salud);
      const worst = registros.reduce((acc, r) => {
        const s = recordStatus(r);
        if(s.dot === "dot-late") return "dot-late";
        if(s.dot === "dot-warn" && acc !== "dot-late") return "dot-warn";
        return acc;
      }, "dot-ok");
      return `
        <div class="salud-item ${currentSaludId === m.id_mascota ? "active" : ""}" data-id="${m.id_mascota}">
          <div class="mini-avatar">${miniAvatarInner(m)}</div>
          <div><div class="si-name">${m.nombre_mascota}</div><div class="si-sub">${m.especie === "perro" ? "Perro" : "Gato"}</div></div>
          <span class="salud-dot ${worst}"></span>
        </div>`;
    }).join("");

    list.innerHTML = header + (saludMascotasCache.length ? items : `<p style="font-size:13px; padding: 0 6px;">Aún no hay mascotas registradas.</p>`);

    document.getElementById("btnRegistrarMascota").addEventListener("click", () => {
      if(misPerfiles.length >= 5){ toast("Ya registraste el máximo de 5 mascotas por usuario."); return; }
      abrirModalRegistrarMascota(misPerfiles.length === 0);
    });
  }catch(err){
    list.innerHTML = errorBlock("No se pudo conectar con el servidor.", "Reintentar", renderSaludList);
  }
}

function renderSaludDetail(){
  const box = document.getElementById("saludDetail");
  if(!currentUser){
    box.innerHTML = emptyBlock("Inicia sesión para ver la ficha médica de tus mascotas.");
    return;
  }
  const m = saludMascotasCache.find(x => x.id_mascota === currentSaludId);
  if(!m){
    box.innerHTML = `<div class="empty-state"><span class="empty-icon">🩺</span><p>Selecciona una mascota de la lista para ver su ficha médica.</p></div>`;
    return;
  }
  const salud = m.salud;
  const registros = parseTratamientos(salud).slice().sort((a,b) => (b.fecha_inicio || "").localeCompare(a.fecha_inicio || ""));

  box.innerHTML = `
    <div class="salud-detail-head">
      <div class="ficha-avatar" style="width:64px;height:64px;font-size:32px;overflow:hidden;">${miniAvatarInner(m)}</div>
      <div><h2 style="margin-bottom:2px;">${m.nombre_mascota}</h2><p style="margin:0;">${m.especie === "perro" ? "Perro" : "Gato"} · ${m.edad || "—"}</p></div>
    </div>
    <div class="salud-doc-row">
      ${salud && salud.tipo_documento ? `<span class="tag">📄 ${salud.tipo_documento}</span>` : ""}
      ${salud && salud.foto_respaldo ? `<span class="tag">🖼️ ${salud.foto_respaldo}</span>` : ""}
    </div>
    <div class="salud-block">
      <div class="salud-block-head">
        <h3>Vacunas y tratamientos</h3>
        <button class="add-mini-btn" id="btnAddRegistroSalud">+ Nuevo registro</button>
      </div>
      <div>
        ${registros.length ? registros.map(r => {
          const s = recordStatus(r);
          const rango = r.tipo === "Vacuna"
            ? `Aplicada: ${fmtDate(r.fecha_inicio)}${r.fecha_proxima ? ` · Próxima dosis: ${fmtDate(r.fecha_proxima)}` : ""}`
            : `Inicio: ${fmtDate(r.fecha_inicio)}${r.fecha_fin ? ` · Hasta: ${fmtDate(r.fecha_fin)}` : ""}`;
          return `
          <div class="record-row">
            <div class="rec-date">${fmtDate(r.fecha_inicio)}</div>
            <div class="rec-main"><span class="rec-type">${r.tipo}</span><br><b>${r.nombre}</b><br>${rango}${r.observaciones ? `<br>${r.observaciones}` : ""}</div>
            <span class="status-chip ${s.cls}">${s.label}</span>
          </div>`;
        }).join("") : `<p style="font-size:13px;">Sin vacunas ni tratamientos registrados todavía.</p>`}
      </div>
    </div>`;
  document.getElementById("btnAddRegistroSalud").addEventListener("click", () => {
    saludCtx = "usuario";
    document.getElementById("formSalud").reset();
    configurarFormSalud();
    document.getElementById("saludModalTitle").textContent = `Nuevo registro — ${m.nombre_mascota}`;
    document.getElementById("modalSalud").classList.add("open");
  });
}

document.getElementById("saludList").addEventListener("click", (e) => {
  const item = e.target.closest(".salud-item");
  if(!item) return;
  currentSaludId = Number(item.dataset.id);
  renderSaludList();
  renderSaludDetail();
});

document.getElementById("formSalud").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const m = saludCtx === "albergue"
    ? saludAlbergueCache.find(x => x.id_mascota === saludAlbergueSelId)
    : saludMascotasCache.find(x => x.id_mascota === currentSaludId);
  if(!m) return;

  const esObs = TIPOS_OBSERVACION.includes(data.tipo);
  if(data.tipo === "Tratamiento" && !data.fecha_fin){ toast("Los tratamientos necesitan una fecha de fin."); return; }
  if(data.fecha_fin && data.fecha_fin < data.fecha_inicio){ toast("La fecha de fin no puede ser anterior a la de inicio."); return; }

  const registros = parseTratamientos(m.salud);
  registros.push({
    tipo: data.tipo, nombre: (data.nombre || "").trim() || data.tipo,
    fecha_inicio: data.fecha_inicio, fecha_fin: esObs ? null : (data.fecha_fin || null),
    fecha_proxima: data.tipo === "Vacuna" ? (data.fecha_proxima || null) : null,
    observaciones: data.observaciones || "",
    ...(esObs ? { activa: true } : {})
  });

  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Guardando...";
  try{
    await apiPost("/salud_registro.php", { id_salud: m.salud.id_salud, tratamientos_vigentes: JSON.stringify(registros) });
    e.target.reset();
    closeModals();
    toast(data.tipo === "Vacuna" ? "Vacuna registrada correctamente."
        : data.tipo === "Tratamiento" ? "Tratamiento registrado correctamente."
        : `${data.tipo} registrada. La mascota queda en observación.`);
    if(saludCtx === "albergue"){
      await renderSaludAlbergue();
      document.getElementById("modalSaludMascota").classList.add("open");
      renderSaludMascotaModal();
    }else{
      await renderSaludList();
      renderSaludDetail();
    }
  }catch(err){
    toast(err.message || "No se pudo guardar el registro.");
  }finally{
    btn.disabled = false; btn.textContent = "Guardar";
  }
});

// Adapta el formulario según el tipo de registro y quién lo usa
function configurarFormSalud(){
  const form = document.getElementById("formSalud");
  const opciones = (saludCtx === "albergue" && esGestor())
    ? ["Vacuna", "Tratamiento", ...TIPOS_OBSERVACION]
    : ["Vacuna", "Tratamiento"];
  form.elements["tipo"].innerHTML = opciones.map(o => `<option value="${o}">${o}</option>`).join("");
  aplicarTipoSalud();
}
function aplicarTipoSalud(){
  const form = document.getElementById("formSalud");
  const tipo = form.elements["tipo"].value;
  const esObs = TIPOS_OBSERVACION.includes(tipo);
  form.elements["fecha_fin"].closest("label").style.display = esObs ? "none" : "";
  form.elements["fecha_proxima"].closest("label").style.display = tipo === "Vacuna" ? "" : "none";
  form.elements["fecha_fin"].required = tipo === "Tratamiento";
  form.elements["nombre"].required = !esObs;
  form.elements["nombre"].placeholder = esObs ? "Opcional (ej. Castración con Dr. Pérez)" : "Ej. Antirrábica / Antibiótico";
}
document.getElementById("formSalud").elements["tipo"].addEventListener("change", aplicarTipoSalud);

// =========================================================
// Salud del albergue (pestaña exclusiva de gestores)
// =========================================================
const TIPOS_OBSERVACION = ["Operación", "Castración", "Esterilización", "Desparasitación"];
const SALUD_ESTADO_META = {
  EN_TRATAMIENTO: { cls: "estado-tratamiento", label: "En tratamiento" },
  EN_OBSERVACION: { cls: "estado-observacion", label: "En observación" },
  SALUDABLE:      { cls: "estado-saludable",   label: "Saludable" }
};
let saludCtx = "usuario";          // "usuario" | "albergue": de quién es el formulario abierto
let saludAlbergueCache = [];
let saludAlbergueSelId = null;

function saludEstadoMeta(m){ return SALUD_ESTADO_META[m.salud && m.salud.estado] || SALUD_ESTADO_META.EN_OBSERVACION; }

function saludAlbergueCardHTML(m){
  const meta = saludEstadoMeta(m);
  return `
    <div class="pet-card salud-card" data-id="${m.id_mascota}" tabindex="0" role="button" aria-label="Ver salud de ${escHTML(m.nombre_mascota)}">
      <div class="pet-card-top">${avatarHTML(m, "pet-avatar")}</div>
      <h3>${escHTML(m.nombre_mascota)}</h3>
      <span class="estado-salud ${meta.cls}">${meta.label}</span>
    </div>`;
}

async function renderSaludAlbergue(){
  if(!esGestor() || !currentAlbergue) return;
  document.getElementById("saludAlbergueTitle").textContent = `Salud de ${currentAlbergue.nombre}`;
  const perrosGrid = document.getElementById("saludPerrosGrid");
  const gatosGrid = document.getElementById("saludGatosGrid");
  perrosGrid.innerHTML = loadingBlock("Cargando...");
  gatosGrid.innerHTML = loadingBlock("Cargando...");
  try{
    const res = await apiGet(`/salud_albergue.php?id_albergue=${currentAlbergue.id_albergue}`);
    saludAlbergueCache = [...res.perros, ...res.gatos];
    perrosGrid.innerHTML = res.perros.length ? res.perros.map(saludAlbergueCardHTML).join("") : emptyBlock("Aún no hay perros registrados en tu albergue.");
    gatosGrid.innerHTML = res.gatos.length ? res.gatos.map(saludAlbergueCardHTML).join("") : emptyBlock("Aún no hay gatos registrados en tu albergue.");
  }catch(err){
    perrosGrid.innerHTML = errorBlock("No se pudo cargar la salud del albergue. " + escHTML(err.message || ""), "Reintentar", renderSaludAlbergue);
    gatosGrid.innerHTML = "";
  }
}

function saludGridClickHandler(e){
  const card = e.target.closest(".salud-card");
  if(card) abrirSaludMascota(Number(card.dataset.id));
}
function saludGridKeyHandler(e){
  if(e.key !== "Enter" && e.key !== " ") return;
  const card = e.target.closest(".salud-card");
  if(card){ e.preventDefault(); abrirSaludMascota(Number(card.dataset.id)); }
}
["saludPerrosGrid", "saludGatosGrid"].forEach(id => {
  const el = document.getElementById(id);
  el.addEventListener("click", saludGridClickHandler);
  el.addEventListener("keydown", saludGridKeyHandler);
});

function abrirSaludMascota(id){
  saludAlbergueSelId = id;
  document.getElementById("modalSaludMascota").classList.add("open");
  renderSaludMascotaModal();
}

function registroRowHTML(r){
  const s = recordStatus(r);
  let rango;
  if(r.tipo === "Vacuna"){
    rango = `Aplicada: ${fmtDate(r.fecha_inicio)}${r.fecha_fin ? ` · Vigente hasta: ${fmtDate(r.fecha_fin)}` : ""}${r.fecha_proxima ? ` · Próxima dosis: ${fmtDate(r.fecha_proxima)}` : ""}`;
  }else if(r.tipo === "Alta" || TIPOS_OBSERVACION.includes(r.tipo)){
    rango = `Fecha: ${fmtDate(r.fecha_inicio)}`;
  }else{
    rango = `Inicio: ${fmtDate(r.fecha_inicio)}${r.fecha_fin ? ` · Hasta: ${fmtDate(r.fecha_fin)}` : ""}`;
  }
  return `
    <div class="record-row">
      <div class="rec-date">${fmtDate(r.fecha_inicio)}</div>
      <div class="rec-main"><span class="rec-type">${escHTML(r.tipo)}</span><br><b>${escHTML(r.nombre)}</b><br>${rango}${r.observaciones ? `<br>${escHTML(r.observaciones)}` : ""}</div>
      <span class="status-chip ${s.cls}">${s.label}</span>
    </div>`;
}

function renderSaludMascotaModal(){
  const body = document.getElementById("saludMascotaBody");
  const m = saludAlbergueCache.find(x => x.id_mascota === saludAlbergueSelId);
  if(!m){ body.innerHTML = emptyBlock("No se encontró la mascota."); return; }
  const meta = saludEstadoMeta(m);
  const registros = parseTratamientos(m.salud).slice().sort((a, b) => (b.fecha_inicio || "").localeCompare(a.fecha_inicio || ""));

  body.innerHTML = `
    <div class="ficha-header">
      ${avatarHTML(m, "ficha-avatar")}
      <div>
        <h2 style="margin-bottom:2px;">${escHTML(m.nombre_mascota)}</h2>
        <p style="margin:0;">${m.especie === "perro" ? "Perro" : "Gato"} · ${escHTML(m.edad || "—")}</p>
      </div>
    </div>
    <div class="ficha-tags"><span class="estado-salud ${meta.cls}">${meta.label}</span></div>
    <div class="salud-block">
      <div class="salud-block-head">
        <h3>Control de salud</h3>
        <div class="salud-modal-actions">
          ${m.salud.observacion_activa ? `<button class="add-mini-btn btn-alta" id="btnDarAlta">✅ Dar de alta</button>` : ""}
          <button class="add-mini-btn" id="btnAddRegistroSaludAlb">+ Nuevo registro</button>
        </div>
      </div>
      <div>
        ${registros.length ? registros.map(registroRowHTML).join("") : `<p style="font-size:13px;">Sin registros todavía. Se encuentra en observación inicial hasta que la des de alta.</p>`}
      </div>
    </div>`;

  document.getElementById("btnAddRegistroSaludAlb").addEventListener("click", () => {
    saludCtx = "albergue";
    document.getElementById("formSalud").reset();
    configurarFormSalud();
    document.getElementById("saludModalTitle").textContent = `Nuevo registro — ${m.nombre_mascota}`;
    document.getElementById("modalSalud").classList.add("open");
  });
  const btnAlta = document.getElementById("btnDarAlta");
  if(btnAlta) btnAlta.addEventListener("click", () => darDeAlta(m, btnAlta));
}

// La observación termina SOLO cuando el gestor da de alta (cada animal se recupera distinto)
async function darDeAlta(m, btn){
  if(!confirm(`¿Dar de alta a ${m.nombre_mascota}? Dejará de estar en observación.`)) return;
  const hoy = new Date().toLocaleDateString("en-CA");
  const registros = parseTratamientos(m.salud).map(r => TIPOS_OBSERVACION.includes(r.tipo) ? { ...r, activa: false } : r);
  registros.push({ tipo: "Alta", nombre: "Alta médica", fecha_inicio: hoy, fecha_fin: null, fecha_proxima: null, observaciones: "" });
  btn.disabled = true;
  try{
    await apiPost("/salud_registro.php", { id_salud: m.salud.id_salud, tratamientos_vigentes: JSON.stringify(registros) });
    toast(`${m.nombre_mascota} fue dado/a de alta. 💚`);
    await renderSaludAlbergue();
    renderSaludMascotaModal();
  }catch(err){
    toast(err.message || "No se pudo dar de alta.");
    btn.disabled = false;
  }
}

const ESTADO_CLASS = { "Perdido": "status-perdido", "Reunificado": "status-reunificado" };

// Foto del reporte de extravío: imagen desde la URL de la BD, emoji solo si no hay foto
function reportFotoHTML(m){
  const emoji = (m && m.especie === "perro") ? "🐕" : "🐈";
  const url = fotoUrl(m && (m.foto ?? m.url_foto ?? m.imagen ?? m.foto_url));
  if(!url) return emoji;
  const alt = ((m && m.nombre_mascota) || "Mascota").replace(/"/g, "&quot;");
  return `<img src="${url}" alt="${alt}" loading="lazy" data-fallback="${emoji}" onerror="window.avatarImgError(this)" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">`;
}

async function renderExtravios(){
  const grid = document.getElementById("boardGrid");
  grid.innerHTML = loadingBlock("Cargando el tablón de extravíos...");
  try{
    const res = await apiGet("/busquedas.php");
    grid.innerHTML = res.busquedas.length ? res.busquedas.map(b => `
      <div class="report-card">
        <div class="report-photo">
          ${reportFotoHTML(b.mascota)}
          <span class="report-status ${ESTADO_CLASS[b.estado] || "status-perdido"}" style="z-index:1;">${b.estado}</span>
        </div>
        <div class="report-body">
          <h4>${b.mascota ? b.mascota.nombre_mascota : "Mascota"}</h4>
          <div class="report-meta">📍 ${b.zona_perdida} · Perdido el ${fmtDate(b.fecha_perdida)}</div>
          <p>${b.descripcion}</p>
          <div class="report-actions">
            <button class="btn-avistado" data-avistado="${b.id_busqueda}" ${b.estado === "Reunificado" ? "disabled" : ""}>Reporté un avistamiento</button>
            <button class="btn-contacto" data-contacto="${b.contacto}">📞 Contacto</button>
          </div>
        </div>
      </div>`).join("") : emptyBlock("No hay reportes de mascotas perdidas por ahora.");
  }catch(err){
    grid.innerHTML = errorBlock("No se pudo conectar con el servidor.", "Reintentar", renderExtravios);
  }
}

document.getElementById("boardGrid").addEventListener("click", (e) => {
  const avistado = e.target.closest("[data-avistado]");
  const contacto = e.target.closest("[data-contacto]");
  if(avistado){
    requireLogin("Inicia sesión para reportar un avistamiento.", () => {
      const id = Number(avistado.dataset.avistado);
      document.getElementById("formEncuentro").dataset.busquedaId = id;
      document.getElementById("encuentroSub").textContent = "Estás reportando un posible avistamiento de esta mascota.";
      document.getElementById("modalEncuentro").classList.add("open");
    });
  }
  if(contacto) toast(`Contacto del reportante: ${contacto.dataset.contacto}`);
});

document.getElementById("btnNuevoReporte").addEventListener("click", () => {
  requireLogin("Inicia sesión para reportar una mascota perdida.", () => {
    document.getElementById("modalReporte").classList.add("open");
  });
});

document.getElementById("formReporte").addEventListener("submit", async (e) => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target).entries());
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Publicando...";
  try{
    await apiPost("/busquedas.php", {
      nombre_mascota: data.nombre_mascota, especie: data.especie,
      zona_perdida: data.zona_perdida, fecha_perdida: data.fecha_perdida,
      descripcion: data.descripcion, contacto: data.contacto, foto: data.foto || null,
      id_usuario: currentUser ? currentUser.id_usuario : null
    });
    e.target.reset();
    closeModals();
    toast("Reporte publicado en el tablón. ¡Ojalá vuelva pronto a casa! 🐾");
    renderExtravios();
  }catch(err){
    toast(err.message || "No se pudo publicar el reporte.");
  }finally{
    btn.disabled = false; btn.textContent = "Publicar en el tablón";
  }
});

document.getElementById("formEncuentro").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const id = Number(form.dataset.busquedaId);
  const btn = form.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Enviando...";
  try{
    await apiPost("/busqueda_estado.php", { id_busqueda: id, estado: "DESAPARECIDA" });
    form.reset();
    closeModals();
    toast("¡Gracias! El aviso fue enviado al reportante.");
  }catch(err){
    toast(err.message || "No se pudo enviar el aviso.");
  }finally{
    btn.disabled = false; btn.textContent = "Enviar aviso";
  }
});

function closeModals(){ document.querySelectorAll(".modal-overlay").forEach(m => m.classList.remove("open")); }
document.querySelectorAll("[data-close]").forEach(btn => btn.addEventListener("click", closeModals));
document.querySelectorAll(".modal-overlay").forEach(overlay => {
  overlay.addEventListener("click", (e) => { if(e.target === overlay) closeModals(); });
});
document.addEventListener("keydown", (e) => { if(e.key === "Escape") closeModals(); });

document.getElementById("globalSearch").addEventListener("keydown", async (e) => {
  if(e.key !== "Enter") return;
  const q = e.target.value.trim();
  if(!q) return;
  try{
    const res = await apiGet(`/mascotas.php?nombre=${encodeURIComponent(q)}`);
    const match = res.mascotas[0];
    if(!match){ toast("No encontramos ninguna mascota con ese nombre."); return; }
    currentSpecies = match.especie;
    document.querySelectorAll(".species-toggle .toggle-btn").forEach(b => b.classList.toggle("active", b.dataset.species === match.especie));
    goToView("mascotas");
    setTimeout(() => openFichaMascota(match.id_mascota), 250);
  }catch(err){
    toast(err.message || "No se pudo buscar en este momento.");
  }
});


// ---- Verificación de correo con código de 6 dígitos ----
function escHTML(t){ return String(t ?? "").replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c])); }

let verificarCtx = { correo: "", abrirMascotaDespues: false };

async function abrirModalVerificar(correo, yaEnviado, abrirMascotaDespues){
  verificarCtx = { correo, abrirMascotaDespues: !!abrirMascotaDespues };
  document.getElementById("formVerificar").reset();
  document.getElementById("verificarSub").textContent = `Enviamos un código de 6 dígitos a ${correo}. Vence en 15 minutos.`;
  document.getElementById("modalVerificar").classList.add("open");
  if(!yaEnviado){
    try{ await apiPost("/enviar_verificacion.php", { id_usuario: currentUser.id_usuario, correo }); toast("Te enviamos un nuevo código 📧"); }
    catch(err){ toast(err.message || "No se pudo enviar el código."); }
  }
}
function cerrarVerificar(){
  closeModals();
  if(verificarCtx.abrirMascotaDespues){ verificarCtx.abrirMascotaDespues = false; abrirModalRegistrarMascota(true); }
}
document.getElementById("verificarCerrar").addEventListener("click", cerrarVerificar);
document.getElementById("btnVerificarDespues").addEventListener("click", cerrarVerificar);

document.getElementById("btnReenviarCodigo").addEventListener("click", async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try{
    await apiPost("/enviar_verificacion.php", { id_usuario: currentUser.id_usuario, correo: verificarCtx.correo });
    toast("Código reenviado 📧");
  }catch(err){ toast(err.message || "No se pudo reenviar el código."); }
  setTimeout(() => { btn.disabled = false; }, 30000);
});

document.getElementById("formVerificar").addEventListener("submit", async (e) => {
  e.preventDefault();
  if(!currentUser) return;
  const codigo = new FormData(e.target).get("codigo").trim();
  const btn = e.target.querySelector("button[type=submit]");
  btn.disabled = true; btn.textContent = "Verificando...";
  try{
    await apiPost("/verificar_codigo.php", { id_usuario: currentUser.id_usuario, codigo });
    currentUser.correo = verificarCtx.correo;
    currentUser.correo_verificado = 1;
    toast("¡Correo confirmado! ✅");
    cerrarVerificar();
    renderAccountArea();
  }catch(err){
    toast(err.message || "No se pudo verificar el código.");
  }finally{
    btn.disabled = false; btn.textContent = "Confirmar correo";
  }
});

// ---- Mis solicitudes de adopción ----
const SOL_ESTADO = {
  PENDIENTE: { cls: "chip-warn", label: "⏳ En proceso", msg: "El albergue aún está revisando tu solicitud." },
  APROBADA:  { cls: "chip-ok",   label: "✅ Aprobada",   msg: "¡El albergue aceptó tu solicitud! Revisa tu correo." },
  RECHAZADA: { cls: "chip-late", label: "❌ Denegada",   msg: "El albergue no pudo aceptar esta solicitud en esta ocasión." }
};

function abrirMisSolicitudes(){
  if(esGestor()){ toast("Esta sección es para adoptantes. Revisa las solicitudes en tu panel de albergue."); return; }
  requireLogin("Inicia sesión para ver tus solicitudes de adopción.", async () => {
    const body = document.getElementById("misSolicitudesBody");
    document.getElementById("modalMisSolicitudes").classList.add("open");
    body.innerHTML = loadingBlock("Cargando tus solicitudes...");
    try{
      const res = await apiGet(`/mis_solicitudes.php?id_usuario=${currentUser.id_usuario}`);
      body.innerHTML = res.solicitudes.length ? `<div class="sol-list">${res.solicitudes.map(s => {
        const st = SOL_ESTADO[s.estado] || SOL_ESTADO.PENDIENTE;
        return `
        <div class="sol-row">
          <span class="mini-avatar">${miniAvatarInner(s)}</span>
          <div class="sol-main">
            <b>${escHTML(s.nombre_mascota)}</b>
            <div class="sol-meta">${escHTML(s.albergue)} · ${fmtDate(s.fecha_solicitud)}</div>
            <div class="sol-msg">${st.msg}</div>
          </div>
          <span class="status-chip ${st.cls}">${st.label}</span>
        </div>`;
      }).join("")}</div>` : emptyBlock("Aún no enviaste ninguna solicitud de adopción.");
    }catch(err){
      body.innerHTML = errorBlock("No se pudieron cargar tus solicitudes.", "Reintentar", abrirMisSolicitudes);
    }
  });
}
document.getElementById("btnMisSolicitudes").addEventListener("click", abrirMisSolicitudes);

// ---- Inicio de sesión / registro con Gmail (Google Identity Services) ----
async function onGoogleCredential(response){
  try{
    // El servidor valida el token de Google, crea/busca al usuario y envía el correo de verificación si es nuevo
    const res = await apiPost("/google_login.php", { credential: response.credential });
    setSession(res.usuario);
    await refrescarPerfiles();
    renderAccountArea();
    closeModals();
    if(res.verificacion_enviada){
      toast(`Cuenta vinculada con Gmail. Enviamos un correo de verificación a ${res.usuario.correo} 📧`);
    }else{
      toast(`¡Bienvenido/a, ${res.usuario.nombre_real}! 🐾`);
    }
    if(res.nuevo) abrirModalRegistrarMascota(true);
    loadView(document.querySelector(".view.active").id.replace("view-", ""));
    if(pendingAfterLogin){ const fn = pendingAfterLogin; pendingAfterLogin = null; fn(); }
  }catch(err){
    toast(err.message || "No se pudo iniciar sesión con Google.");
  }
}
function initGoogleSignIn(intentos = 0){
  if(!(window.google && google.accounts && google.accounts.id)){
    if(intentos < 20) setTimeout(() => initGoogleSignIn(intentos + 1), 300);
    return;
  }
  google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: onGoogleCredential });
  ["googleBtnLogin", "googleBtnRegister"].forEach(id => {
    const el = document.getElementById(id);
    if(el) google.accounts.id.renderButton(el, { theme: "outline", size: "large", text: "continue_with", locale: "es" });
  });
}
initGoogleSignIn();

applyRoleVisibility();
renderAccountArea();
loadView("inicio");
viewLoaded.inicio = true;
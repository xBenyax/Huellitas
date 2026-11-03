// Punto de entrada: conecta todas las clases y arranca la app.
import { state } from "./core/AppState.js";
import { modals } from "./core/ModalManager.js";
import { navigation } from "./core/Navigation.js";
import { Avatar } from "./components/Avatar.js";
import { accountArea } from "./features/AccountArea.js";
import { authModal } from "./features/AuthModal.js";
import { gestorAuthModal } from "./features/GestorAuthModal.js";
import { registrarMascotaModal } from "./features/RegistrarMascotaModal.js";
import { globalSearch } from "./features/GlobalSearch.js";
import { inicioView } from "./views/InicioView.js";
import { mascotasView } from "./views/MascotasView.js";
import { gestorView } from "./views/GestorView.js";
import { saludView } from "./views/SaludView.js";
import { extraviosView } from "./views/ExtraviosView.js";

class App {
  start(){
    Avatar.install();

    // Listeners
    navigation.init();
    accountArea.init();
    authModal.init();
    gestorAuthModal.init();
    registrarMascotaModal.init();
    inicioView.init();
    mascotasView.init();
    gestorView.init();
    saludView.init();
    extraviosView.init();
    modals.init();
    globalSearch.init();

    // Render inicial
    navigation.applyRoleVisibility();
    accountArea.render();
    navigation.loadView("inicio");
    state.viewLoaded.inicio = true;
  }
}

new App().start();

import {createRouter, createWebHistory} from "vue-router"
import {useAuth} from "@/composables/useAuth.js";

import HomeView from "@/views/HomeView.vue"

const routes = [
    {
        path: "/",
        name: "Home",
        component: HomeView,
    }
];

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes
});

// Navigation Guard: Wird vor jedem Seitenwechsel ausgeführt.
// Leitet zum Login weiter, wenn die Zielseite eine Anmeldung (meta.requiresAuth)
// oder Admin-Rechte (meta.requiresAdmin) verlangt und der Benutzer diese nicht erfüllt.

router.beforeEach((to) => {
    const {isLoggedIn, isAdmin} = useAuth();

    if (to.meta.requiresAuth && !isLoggedIn.value) {
        return {name: "Login"};
    }

    if (to.meta.requiresAdmin && !isAdmin.value) {
        return {name: "Login"};
    }
});

export default router;

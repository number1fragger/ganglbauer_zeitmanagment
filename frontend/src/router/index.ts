import { createRouter, createWebHistory } from 'vue-router'
import type { Role } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Ohne Anmeldung erreichbar. */
    public?: boolean
    /** Mindestrolle; ohne Angabe reicht jede Anmeldung. */
    role?: Role
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      // Chef und Vorarbeiter starten im Kalender, Arbeiter bei ihren Arbeiten.
      path: '/',
      name: 'home',
      redirect: () => useAuthStore().homeRoute,
    },
    {
      path: '/kalender',
      name: 'calendar',
      component: () => import('@/views/CalendarView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      path: '/kapazitaet',
      name: 'overview',
      component: () => import('@/views/OverviewView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      // Figma 03: eigene Arbeiten, Restzeit und "Ich brauche neue Arbeit"
      path: '/meine-arbeit',
      name: 'my-work',
      component: () => import('@/views/MyWorkView.vue'),
    },
    {
      path: '/arbeiten',
      name: 'jobs',
      component: () => import('@/views/JobsView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    // Alte Adresse aus der ersten Version
    { path: '/brauche-arbeit', redirect: '/meine-arbeit' },
    {
      path: '/auswertung',
      name: 'report',
      component: () => import('@/views/ReportView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      path: '/benutzer',
      name: 'users',
      component: () => import('@/views/UsersView.vue'),
      meta: { role: 'ROLE_ADMIN' },
    },
    {
      path: '/einstellungen',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { public: true },
    },
  ],
})

// Navigation Guard: Wird vor jedem Seitenwechsel ausgefuehrt.
// Leitet zum Login weiter, wenn die Zielseite eine Anmeldung verlangt, und zur
// eigenen Startseite, wenn die Rolle nicht reicht (meta.role).
// Achtung: Das blendet nur Seiten aus – die eigentliche Rechtepruefung macht das Backend.
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) {
    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (!auth.user) {
    try {
      await auth.loadUser()
    } catch {
      auth.logout()

      return { name: 'login' }
    }
  }

  if (to.meta.role && !auth.hasRole(to.meta.role)) {
    return auth.homeRoute
  }

  return true
})

export default router

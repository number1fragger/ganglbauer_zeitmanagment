import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * meta.public: ohne Anmeldung erreichbar
 * meta.role:   Mindestrolle (ROLE_FOREMAN = Chef und Vorarbeiter, ROLE_ADMIN = nur Chef)
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/', redirect: () => useAuthStore().homeRoute },
    {
      path: '/kalender',
      component: () => import('@/views/CalendarView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      path: '/arbeiten',
      component: () => import('@/views/JobsView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      path: '/auswertung',
      component: () => import('@/views/ReportView.vue'),
      meta: { role: 'ROLE_FOREMAN' },
    },
    {
      path: '/benutzer',
      component: () => import('@/views/UsersView.vue'),
      meta: { role: 'ROLE_ADMIN' },
    },
    { path: '/meine-arbeit', component: () => import('@/views/MyWorkView.vue') },
    { path: '/einstellungen', component: () => import('@/views/SettingsView.vue') },
    {
      path: '/:pathMatch(.*)*',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { public: true },
    },
  ],
})

// Navigation Guard: Wird vor jedem Seitenwechsel ausgefuehrt.
// Ohne Anmeldung geht es zum Login, reicht die Rolle nicht, zur eigenen Startseite.
// Das blendet Seiten nur aus – die eigentliche Rechtepruefung macht das Backend.
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) return true
  if (!auth.isAuthenticated) return { path: '/login', query: { redirect: to.fullPath } }

  if (!auth.user) {
    try {
      await auth.loadUser()
    } catch {
      auth.logout()
      return '/login'
    }
  }

  if (to.meta.role && !auth.hasRole(to.meta.role)) return auth.homeRoute

  return true
})

export default router

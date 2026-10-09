import { createRouter, createWebHistory } from 'vue-router'
import { ApiError } from '@/api/http'
import type { Role } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const planners: Role[] = ['chef', 'vorarbeiter']

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    /** Rollen, die die Ansicht sehen duerfen (Figma-Screen 07). Ohne Angabe: alle. */
    roles?: Role[]
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('@/layouts/AppShell.vue'),
      children: [
        // Startseite je Rolle: Planung -> Dashboard, Arbeiter -> eigene Arbeiten.
        { path: '', name: 'home', redirect: () => ({ name: useAuthStore().isPlanner ? 'dashboard' : 'my-work' }) },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { roles: planners },
        },
        {
          path: 'kalender',
          alias: '/planung',
          name: 'planning',
          component: () => import('@/views/PlanningView.vue'),
        },
        { path: 'aufgaben', name: 'tasks', component: () => import('@/views/TasksView.vue') },
        { path: 'meine-arbeit', name: 'my-work', component: () => import('@/views/MyWorkView.vue') },
        {
          path: 'auswertung',
          name: 'report',
          component: () => import('@/views/ReportView.vue'),
          meta: { roles: planners },
        },
        { path: 'benutzer', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { roles: ['chef'] } },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { public: true },
    },
  ],
})

// Blendet Ansichten nur aus – geprueft wird jede Anfrage zusaetzlich im Backend.
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) return true
  if (!auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }

  if (!auth.user) {
    try {
      await auth.loadUser()
    } catch (e) {
      // Nur bei ungueltiger Anmeldung abmelden – ein kurzer Netzwerkfehler soll niemanden rauswerfen.
      if (e instanceof ApiError && e.status !== 401) {
        await new Promise((resolve) => setTimeout(resolve, 1000))
        try {
          await auth.loadUser()
        } catch {
          /* Ansicht zeigt den Fehler beim Laden ihrer Daten */
        }
        return true
      }
      auth.logout()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  if (to.meta.roles && auth.role && !to.meta.roles.includes(auth.role)) return { name: 'home' }

  return true
})

export default router

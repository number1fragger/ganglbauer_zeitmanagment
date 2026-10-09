import { createRouter, createWebHistory } from 'vue-router'
import type { Role } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    /** Rollen, die die Ansicht sehen duerfen (Figma-Screen 07). Ohne Angabe: alle. */
    roles?: Role[]
  }
}

const planners: Role[] = ['chef', 'vorarbeiter']

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/', name: 'home', redirect: () => ({ name: useAuthStore().isPlanner ? 'planning' : 'my-work' }) },
    { path: '/planung', name: 'planning', component: () => import('@/views/PlanningView.vue'), meta: { roles: planners } },
    { path: '/meine-arbeit', name: 'my-work', component: () => import('@/views/MyWorkView.vue') },
    { path: '/auswertung', name: 'report', component: () => import('@/views/ReportView.vue'), meta: { roles: planners } },
    { path: '/benutzer', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { roles: ['chef'] } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { public: true } },
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
    } catch {
      auth.logout()
      return { name: 'login' }
    }
  }

  if (to.meta.roles && auth.role && !to.meta.roles.includes(auth.role)) return { name: 'home' }

  return true
})

export default router

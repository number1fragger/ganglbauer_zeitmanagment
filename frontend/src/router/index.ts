import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

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
      path: '/',
      name: 'overview',
      component: () => import('@/views/OverviewView.vue'),
    },
    {
      path: '/arbeiten',
      name: 'jobs',
      component: () => import('@/views/JobsView.vue'),
    },
    {
      path: '/brauche-arbeit',
      name: 'work-request',
      component: () => import('@/views/WorkRequestView.vue'),
    },
    {
      path: '/auswertung',
      name: 'report',
      component: () => import('@/views/ReportView.vue'),
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

  return true
})

export default router

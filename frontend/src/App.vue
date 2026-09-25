<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppNav from '@/components/AppNav.vue'
import TimerBar from '@/components/TimerBar.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()

const contentClass = computed(() => {
  if (!auth.isAuthenticated) return 'content content--bare'

  return route.meta.wide ? 'content content--wide' : 'content'
})
</script>

<template>
  <div class="shell">
    <AppNav v-if="auth.isAuthenticated" />

    <main :class="contentClass">
      <TimerBar v-if="auth.isAuthenticated && !route.meta.wide" />
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
}

.content {
  max-width: 1080px;
  margin: 0 auto;
  padding: 1.5rem 1rem 4rem;
}

.content--wide {
  max-width: none;
  padding: 0;
}

.content--bare {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 1rem;
}
</style>

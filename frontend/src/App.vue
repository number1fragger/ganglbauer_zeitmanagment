<script setup lang="ts">
import { RouterView } from 'vue-router'
import AppNav from '@/components/AppNav.vue'
import TimerBar from '@/components/TimerBar.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="shell">
    <AppNav v-if="auth.isAuthenticated" />

    <main :class="auth.isAuthenticated ? 'content' : 'content content--bare'">
      <TimerBar v-if="auth.isAuthenticated" />
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

.content--bare {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 1rem;
}
</style>

<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router'
import AppHeader from '@/components/AppHeader.vue'
import JobDialog from '@/components/JobDialog.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Chef und Vorarbeiter bekommen den Figma-Header. Arbeiter haben eine
 * eigene, schlanke Ansicht mit eigenem Kopf (Figma-Screen 03).
 */
const auth = useAuthStore()
const route = useRoute()
</script>

<template>
  <AppHeader v-if="auth.isAuthenticated && auth.isForeman && !route.meta.public" />
  <RouterView />
  <JobDialog v-if="auth.isForeman" />
</template>

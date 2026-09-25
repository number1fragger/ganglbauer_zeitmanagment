<script setup>
import { useRoute } from 'vue-router'
import AppHeader from '@/components/AppHeader.vue'
import JobDialog from '@/components/JobDialog.vue'
import { useAuthStore } from '@/stores/auth'

// Chef und Vorarbeiter bekommen den Planungs-Header, Arbeiter ihre eigene schlanke Ansicht.
const auth = useAuthStore()
const route = useRoute()
</script>

<template>
  <AppHeader v-if="auth.isForeman && !route.meta.public" />
  <RouterView />
  <JobDialog v-if="auth.isForeman" />
</template>

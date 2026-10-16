import '@fontsource-variable/inter/index.css'
import './assets/main.css'
import logoUrl from '../images/Logo_ohne_Rand.png'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'

const favicon = document.createElement('link')
favicon.rel = 'icon'
favicon.type = 'image/png'
favicon.href = logoUrl
document.head.appendChild(favicon)

createApp(App).use(createPinia()).use(router).mount('#app')

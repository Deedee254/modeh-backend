import { createApp } from 'vue'
import AdminChat from './AdminChat.vue'

const app = createApp(AdminChat)

// Read admin id injected in window scope by Blade
if (window.__ADMIN_ID__) {
  app.config.globalProperties.adminId = window.__ADMIN_ID__
}

app.mount('#admin-chat-app')

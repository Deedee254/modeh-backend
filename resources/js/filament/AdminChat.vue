<template>
  <div class="h-full flex bg-white shadow rounded-lg overflow-hidden">
    <div class="w-80 border-r p-3 overflow-auto">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold">Threads</h3>
        <input v-model="q" @input="loadThreads" placeholder="Search" class="px-2 py-1 border rounded text-sm" />
      </div>

      <ul>
        <li v-for="t in threads" :key="t.id" @click="openThread(t)" :class="['p-2 rounded cursor-pointer flex items-center gap-3', activeThread && activeThread.id === t.id ? 'bg-indigo-50' : 'hover:bg-gray-50']">
          <img v-if="t.avatar" :src="t.avatar" class="w-10 h-10 rounded-full object-cover" />
          <div v-else class="w-10 h-10 rounded-full bg-indigo-500 text-white flex items-center justify-center">{{ (t.name || t.other_name || 'U').slice(0,1).toUpperCase() }}</div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between">
              <div class="font-medium truncate">{{ t.name || t.other_name || ('User '+t.id) }}</div>
              <div class="text-xs text-gray-400 ml-2">{{ t.last_at ? new Date(t.last_at).toLocaleTimeString() : '' }}</div>
            </div>
            <div class="text-xs text-gray-500 truncate">{{ t.last_preview || '' }}</div>
          </div>
          <div v-if="t.unread_count > 0" class="ml-2 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xs font-bold">{{ t.unread_count }}</div>
        </li>
      </ul>
    </div>

    <div class="flex-1 p-3 flex flex-col">
      <div v-if="!activeThread" class="flex-1 flex items-center justify-center text-gray-500">Select a thread to view messages</div>
      <div v-else class="flex-1 flex flex-col">
        <div class="flex items-center gap-3 mb-3">
          <div v-if="activeThread.avatar" class="w-10 h-10 rounded-full object-cover"><img :src="activeThread.avatar" class="w-10 h-10 rounded-full object-cover" /></div>
          <div v-else class="w-10 h-10 rounded-full bg-indigo-500 text-white flex items-center justify-center">A</div>
          <div>
            <div class="font-semibold">{{ activeThread.name || activeThread.other_name || 'Thread' }}</div>
            <div class="text-xs text-gray-500">{{ activeThread.type || '' }}</div>
          </div>
        </div>
        <div class="flex-1 overflow-auto border p-3 rounded mb-3" ref="messagesPane" @scroll="handleScroll">
          <!-- Infinite scroll loader -->
          <div v-if="loadingMore" class="text-center text-gray-500 text-sm py-2">Loading more messages...</div>
          
          <!-- Message groups -->
          <template v-for="(group, index) in messageGroups" :key="index">
            <div class="mb-4">
              <!-- Date separator -->
              <div class="text-xs text-center text-gray-400 mb-2">{{ formatDate(group.date) }}</div>
              
              <!-- Messages in group -->
              <template v-for="m in group.messages" :key="m.id">
                <div :class="['mb-1 flex', m.sender_id === adminId ? 'justify-end' : 'justify-start']">
                  <!-- Avatar for other user, only show on first message in sequence -->
                  <template v-if="m.sender_id !== adminId && (!group.messages[m.index - 1] || group.messages[m.index - 1].sender_id !== m.sender_id)">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full overflow-hidden mr-2">
                      <img v-if="activeThread.avatar" :src="activeThread.avatar" class="w-full h-full object-cover" />
                      <div v-else class="w-full h-full bg-indigo-500 text-white flex items-center justify-center text-sm">
                        {{ (activeThread.name || 'U').slice(0,1).toUpperCase() }}
                      </div>
                    </div>
                  </template>
                  <div class="flex-shrink-0 w-8 mr-2" v-else-if="m.sender_id !== adminId"></div>
                  
                  <!-- Message content -->
                  <div class="group relative" :class="{'max-w-[60%]': !m.isEditing}">
                    <div v-if="!m.isEditing" class="p-2 break-words" 
                         :class="m.sender_id === adminId ? 'bg-indigo-600 text-white rounded' : 'bg-gray-100 rounded'">
                      {{ m.content || m.body || m.message || '' }}
                    </div>
                    <!-- Edit mode -->
                    <div v-else class="flex items-center gap-2">
                      <input v-model="m.editText" 
                             class="flex-1 p-2 border rounded" 
                             @keyup.enter="updateMessage(m)"
                             @keyup.esc="cancelEdit(m)" />
                      <button @click="updateMessage(m)" class="p-1 text-green-600 hover:text-green-800">
                        Save
                      </button>
                      <button @click="cancelEdit(m)" class="p-1 text-red-600 hover:text-red-800">
                        Cancel
                      </button>
                    </div>
                    
                    <!-- Actions menu (only for own messages) -->
                    <div v-if="m.sender_id === adminId && !m.isEditing" 
                         class="absolute right-0 top-0 hidden group-hover:flex gap-1 -mt-6">
                      <button @click="startEdit(m)" class="p-1 bg-white rounded shadow hover:bg-gray-100">
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                      </button>
                      <button @click="confirmDelete(m)" class="p-1 bg-white rounded shadow hover:bg-gray-100">
                        <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                      </button>
                    </div>
                    
                    <!-- Timestamp -->
                    <div class="text-[10px] text-gray-400 mt-1" :class="m.sender_id === adminId ? 'text-right' : 'text-left'">
                      {{ formatTime(m.created_at) }}
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </template>
          
          <!-- Typing indicator -->
          <div v-if="typing" class="flex items-center gap-2 text-gray-500 text-sm">
            <div class="flex-shrink-0 w-8 h-8 rounded-full overflow-hidden">
              <img v-if="activeThread.avatar" :src="activeThread.avatar" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full bg-indigo-500 text-white flex items-center justify-center">
                {{ (activeThread.name || 'U').slice(0,1).toUpperCase() }}
              </div>
            </div>
            <div class="typing-indicator">
              <span></span>
              <span></span>
              <span></span>
            </div>
          </div>
        </div>
        <div class="flex gap-2">
          <input v-model="composer" 
                 @keyup.enter="send" 
                 @input="notifyTyping"
                 @blur="notifyTypingStopped"
                 class="flex-1 px-3 py-2 border rounded" 
                 placeholder="Write a reply..." />
          <button @click="send" class="px-4 py-2 bg-indigo-600 text-white rounded">Send</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'AdminChat',
  data() {
    return {
      threads: [],
      messages: [],
      activeThread: null,
      composer: '',
      q: '',
      adminId: null,
      typing: false,
      typingTimeout: null,
      page: 1,
      loadingMore: false,
      hasMoreMessages: true,
      messageGroups: [],
    }
  },
  methods: {
    async loadThreads() {
      try {
        const res = await fetch('/api/chat/threads', { credentials: 'include' })
        if (res.ok) {
          const j = await res.json()
          // normalize threads to include unread_count and avatar
          this.threads = (j.conversations || j.threads || []).map(t => ({
            id: t.id || t.other_id || t.other_user_id || null,
            ...t,
            unread_count: t.unread_count || t.unread || 0,
            avatar: t.avatar || t.other_avatar || null,
            last_preview: t.last_message || t.last_preview || '',
            name: t.name || t.other_name || t.title || null,
          }))
        }
      } catch (e) { console.error(e) }
    },
    async openThread(t) {
      this.activeThread = t
      this.page = 1
      this.hasMoreMessages = true
      this.loadingMore = false
      try {
        const params = new URLSearchParams()
        if (t.id) params.set('user_id', t.id)
        params.set('page', this.page)
        const res = await fetch('/api/chat/messages?' + params.toString(), { credentials: 'include' })
        if (res.ok) {
          const j = await res.json()
          this.messages = j.messages || []
          this.groupMessages()
        }
      } catch (e) { console.error(e) }
    },
    async send() {
      if (!this.composer.trim() || !this.activeThread) return
  const payload = { content: this.composer }
  if (this.activeThread.id) payload.recipient_id = this.activeThread.id
      try {
        const res = await fetch('/api/chat/send', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        if (res.ok) {
          const j = await res.json()
          // append message to messages
          this.messages.push(j.message || j)
          this.composer = ''
          this.groupMessages()
          
          // Notify typing stopped
          this.notifyTypingStopped()
        }
      } catch (e) { console.error(e) }
    },
    startEdit(message) {
      message.isEditing = true
      message.editText = message.content || message.body || message.message || ''
    },
    async updateMessage(message) {
      if (!message.editText.trim()) return
      try {
        const res = await fetch(`/api/chat/messages/${message.id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ body: message.editText })
        })
        if (res.ok) {
          message.content = message.editText
          message.isEditing = false
          this.groupMessages()
        }
      } catch (e) { console.error(e) }
    },
    cancelEdit(message) {
      message.isEditing = false
      message.editText = ''
    },
    async confirmDelete(message) {
      if (!confirm('Are you sure you want to delete this message?')) return
      try {
        const res = await fetch(`/api/chat/messages/${message.id}`, {
          method: 'DELETE',
          credentials: 'include'
        })
        if (res.ok) {
          const index = this.messages.findIndex(m => m.id === message.id)
          if (index > -1) {
            this.messages.splice(index, 1)
            this.groupMessages()
          }
        }
      } catch (e) { console.error(e) }
    },
    notifyTyping() {
      if (!this.activeThread) return
      try {
        fetch('/api/chat/typing', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ thread_id: this.activeThread.id })
        })
      } catch (e) { console.error(e) }
    },
    notifyTypingStopped() {
      if (!this.activeThread) return
      try {
        fetch('/api/chat/typing-stopped', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ thread_id: this.activeThread.id })
        })
      } catch (e) { console.error(e) }
    },
    handleScroll(e) {
      if (!this.hasMoreMessages || this.loadingMore) return
      const el = e.target
      if (el.scrollTop <= 100) {
        this.loadMoreMessages()
      }
    },
    async loadMoreMessages() {
      if (!this.activeThread || !this.hasMoreMessages || this.loadingMore) return
      this.loadingMore = true
      try {
        const params = new URLSearchParams()
        if (this.activeThread.id) params.set('user_id', this.activeThread.id)
        params.set('page', this.page + 1)
        const res = await fetch('/api/chat/messages?' + params.toString(), { credentials: 'include' })
        if (res.ok) {
          const j = await res.json()
          const newMessages = j.messages || []
          if (newMessages.length === 0) {
            this.hasMoreMessages = false
          } else {
            this.page++
            this.messages = [...newMessages, ...this.messages]
            this.groupMessages()
          }
        }
      } catch (e) { console.error(e) }
      this.loadingMore = false
    },
    attachEcho() {
      if (!window.Echo) return
      try {
    const channel = window.Echo.private('App.Models.User.' + this.adminId)
        
        channel.listen('.MessageSent', (payload) => {
          const msg = payload.message ?? payload
          const other = msg.sender_id === this.adminId ? msg.recipient_id : msg.sender_id
          if (this.activeThread && (this.activeThread.id == other)) {
            this.messages.push(msg)
            this.groupMessages()
          }
          this.loadThreads()
        })

        channel.listenForWhisper('typing', (data) => {
          if (this.activeThread && data.thread_id === this.activeThread.id) {
            this.typing = true
            if (this.typingTimeout) clearTimeout(this.typingTimeout)
            this.typingTimeout = setTimeout(() => {
              this.typing = false
            }, 3000)
          }
        })

        channel.listenForWhisper('typing-stopped', (data) => {
          if (this.activeThread && data.thread_id === this.activeThread.id) {
            this.typing = false
            if (this.typingTimeout) clearTimeout(this.typingTimeout)
          }
        })
      } catch (e) { console.error('Echo attach failed', e) }
    }
  },
  async mounted() {
    // set admin id
    this.adminId = window.__ADMIN_ID__ || null
    await this.loadThreads()
    // Wait for Echo to be available, then attach
    const tryAttachEcho = () => {
      if (window.Echo) {
        this.attachEcho()
      } else {
        setTimeout(tryAttachEcho, 100)
      }
    }
    tryAttachEcho()
  }
}
</script>

<style scoped>
.typing-indicator {
  display: flex;
  gap: 2px;
  padding: 8px;
  background: #f3f4f6;
  border-radius: 12px;
}

.typing-indicator span {
  width: 6px;
  height: 6px;
  background-color: #9ca3af;
  border-radius: 50%;
  animation: typing 1s infinite ease-in-out;
}

.typing-indicator span:nth-child(1) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.3s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}
</style>

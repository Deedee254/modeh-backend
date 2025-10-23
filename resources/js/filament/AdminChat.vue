<template>
  <div class="h-screen flex bg-white shadow rounded-lg overflow-hidden">
    <!-- Conversation list (desktop or mobile when not viewing a thread) -->
  <div v-if="!isMobile || !showChatWindowOnMobile" :class="isMobile ? 'absolute inset-0 z-40 w-full p-3 bg-white overflow-auto' : 'w-80 border-r p-3 overflow-auto'">
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

    <!-- Main chat area (desktop or mobile single-chat view) -->
  <div v-if="!isMobile || showChatWindowOnMobile" :class="isMobile ? 'absolute inset-0 z-40 w-full flex flex-col min-h-0 bg-white' : 'flex-1 flex flex-col min-h-0'">
          <div v-if="!activeThread" class="flex-1 flex items-center justify-center text-gray-500">Select a thread to view messages</div>
          <div v-else class="flex-1 flex flex-col min-h-0">
            <div class="flex items-center gap-3 mb-3 sticky top-0 bg-white z-20 p-3">
              <!-- back button for mobile placed in the same slot/spacing as frontend -->
              <button
                v-if="isMobile && showChatWindowOnMobile"
                @click="showChatWindowOnMobile = false"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-accent hover:text-accent-foreground md:hidden h-9 w-9"
                aria-label="Back to conversations"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left h-5 w-5">
                  <path d="m12 19-7-7 7-7"></path>
                  <path d="M19 12H5"></path>
                </svg>
              </button>
              <div v-if="activeThread.avatar" class="w-10 h-10 rounded-full object-cover"><img :src="activeThread.avatar" class="w-10 h-10 rounded-full object-cover" /></div>
              <div v-else class="w-10 h-10 rounded-full bg-indigo-500 text-white flex items-center justify-center">A</div>
  <!-- messages pane: only this area scrolls. ensure it fills available height and leaves space for sticky composer -->
  <div class="flex-1 overflow-auto border p-3 rounded mb-3 min-h-0" ref="messagesPane" @scroll="handleScroll" style="padding-bottom:96px;">
          <!-- Infinite scroll loader -->
          <div v-if="loadingMore" class="text-center text-gray-500 text-sm py-2">Loading more messages...</div>
          
          <!-- Message groups -->
          <template v-for="(group, index) in messageGroups" :key="index">
            <div class="mb-4">
              <!-- Date separator -->
              <div class="text-xs text-center text-gray-400 mb-2">{{ formatDate(group.date) }}</div>

              <!-- Messages in group -->
              <template v-for="m in group.messages" :key="m.id">
                <div :class="['flex w-full mb-2', m.sender_id === adminId ? 'justify-end' : 'justify-start']">
                  <div :class="m.sender_id === adminId ? 'chat-bubble sent' : 'chat-bubble received'" class="rounded-lg px-4 py-2">
                    <!-- attachments placeholder -->
                    <template v-if="m.attachments && m.attachments.length">
                      <div class="mb-2 space-y-2">
                        <div v-for="(a, i) in m.attachments" :key="i" class="rounded overflow-hidden border bg-white">
                          <a :href="a.url || a.path" target="_blank" class="block p-2 text-sm underline text-indigo-600">{{ a.name || a.filename || a.url }}</a>
                        </div>
                      </div>
                    </template>

                    <div v-if="!m.isEditing">
                      <p class="text-sm whitespace-pre-wrap">{{ m.content || m.body || m.message || '' }}</p>
                    </div>

                    <!-- edit UI -->
                    <div v-else class="flex items-center gap-2">
                      <input v-model="m.editText" class="flex-1 p-2 border rounded" @keyup.enter="updateMessage(m)" @keyup.esc="cancelEdit(m)" />
                      <button @click="updateMessage(m)" class="p-1 text-green-600">Save</button>
                      <button @click="cancelEdit(m)" class="p-1 text-red-600">Cancel</button>
                    </div>

                    <div class="flex items-center justify-end gap-2 mt-1">
                      <p class="text-xs text-gray-400">{{ formatTime(m.created_at) }}</p>
                      <template v-if="String(m.sender_id) === String(adminId)">
                        <span class="ml-1">
                          <template v-if="m.sending">
                            <svg class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                          </template>
                          <template v-else-if="m.failed">
                            <svg class="tick text-rose-500" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v6"></path><path d="M12 15v.01"></path></svg>
                          </template>
                          <template v-else>
                            <svg v-if="getTickState(m) === 'single'" class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                            <svg v-else-if="getTickState(m) === 'double'" class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path><path d="M22 6 11 17l-5-5"></path></svg>
                            <svg v-else-if="getTickState(m) === 'read'" class="tick tick-read" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#39B3FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path><path d="M22 6 11 17l-5-5"></path></svg>
                          </template>
                        </span>
                      </template>
                      <div v-if="m.failed && String(m.sender_id) === String(adminId)" class="flex items-center gap-2">
                        <button @click="resendFailedMessage(m)" class="text-xs text-rose-500 underline">Retry</button>
                      </div>
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
        <!-- composer: sticky footer so only messages scroll -->
        <div class="sticky bottom-0 z-30 bg-white border-t p-3">
            <div class="flex gap-2">
              <div class="relative flex-1">
                <input v-model="composer" 
                       @keyup.enter="send" 
                       @input="notifyTyping"
                       @blur="notifyTypingStopped"
                       ref="messageInput"
                       class="w-full px-3 py-2 border rounded" 
                       placeholder="Write a reply..." />
                <div class="absolute right-2 top-1/2 transform -translate-y-1/2 flex items-center gap-1">
                  <button @click.prevent="toggleEmojiPicker" type="button" class="p-1 text-gray-600 hover:text-gray-800" title="Emoji">
                    🙂
                  </button>
                  <button @click.prevent="triggerFileInput" type="button" class="p-1 text-gray-600 hover:text-gray-800" title="Attach file">
                    📎
                  </button>
                </div>
                <!-- emoji picker simple grid -->
                <div v-if="showEmojiPicker" class="mt-2 p-2 bg-white border rounded shadow absolute left-0 bottom-full mb-2 z-50">
                  <div class="grid grid-cols-8 gap-1 max-w-xs">
                    <button v-for="e in ['😃','🙂','😂','😍','😅','😭','👍','👎','🎉','🔥','🤔','😴','🙌','😎','👏','🤝']" :key="e" @click.prevent="insertEmoji(e)" class="p-1 text-lg">{{ e }}</button>
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <button @click="send" class="px-4 py-2 bg-indigo-600 text-white rounded">Send</button>
              </div>
            </div>
            <!-- attachments preview -->
            <div v-if="attachments.length" class="mt-2 flex gap-2 items-center">
              <div v-for="(f, i) in attachments" :key="i" class="flex items-center gap-2 border px-2 py-1 rounded bg-gray-50">
                <div class="text-sm">{{ f.name }}</div>
                <button @click.prevent="attachments.splice(i,1)" class="text-xs text-rose-500">Remove</button>
              </div>
            </div>
            <input ref="fileInput" type="file" class="hidden" @change="onFileChange" multiple />
        </div>
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
        sendingMessages: {},
      // attachments and emoji picker
      attachments: [],
      showEmojiPicker: false,
      q: '',
      adminId: null,
      typing: false,
      typingTimeout: null,
      page: 1,
      loadingMore: false,
      hasMoreMessages: true,
      messageGroups: [],
        autoScrollOnNew: true,
        // mobile state
        isMobile: false,
        showChatWindowOnMobile: false,
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
            // ensure we scroll to the bottom of the thread after loading
            this.$nextTick(() => { this.scrollToBottom(true) })
          }
      } catch (e) { console.error(e) }
      // If on mobile, switch to single chat view
      try {
        const mobile = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : false
        if (mobile) this.showChatWindowOnMobile = true
      } catch (e) {}
    },
    async send() {
      if ((!this.composer || !this.composer.trim()) && this.attachments.length === 0) return
      if (!this.activeThread) return
      const content = this.composer || ''
      const tempId = 'msg-' + Date.now()
      const tempMsg = {
        id: tempId,
        content,
        sender_id: this.adminId,
        created_at: (new Date()).toISOString(),
        sending: true,
        attachments: this.attachments.map(a => ({ name: a.name }))
      }
      // optimistic append
      this.messages.push(tempMsg)
      this.groupMessages()
      this.composer = ''
      // scroll to bottom so user sees their message
      this.$nextTick(() => { this.scrollToBottom(true) })

      // prepare send: if attachments present, use FormData, else JSON
      try {
        let res
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        const headers = {}
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        // include Echo socket id if available
        try {
          if (window.Echo) {
            if (typeof window.Echo.socketId === 'function') headers['X-Socket-Id'] = window.Echo.socketId()
            else if (window.Echo.connector && typeof window.Echo.connector.socketId === 'function') headers['X-Socket-Id'] = window.Echo.connector.socketId()
          }
        } catch (e) {}

        if (this.attachments && this.attachments.length > 0) {
          const fd = new FormData()
          fd.append('content', content)
          if (this.activeThread.id) fd.append('recipient_id', this.activeThread.id)
          this.attachments.forEach((f, i) => {
            // backend expects file fields as attachments[]
            fd.append('attachments[]', f)
          })
          // send as multipart/form-data (browser sets Content-Type)
          res = await fetch('/api/chat/send', { method: 'POST', credentials: 'include', headers, body: fd })
        } else {
          const payload = { content }
          if (this.activeThread.id) payload.recipient_id = this.activeThread.id
          headers['Content-Type'] = 'application/json'
          res = await fetch('/api/chat/send', { method: 'POST', credentials: 'include', headers, body: JSON.stringify(payload) })
        }

        if (res && res.ok) {
          const j = await res.json()
          const serverMsg = j.message || j
          // replace temp message
          const idx = this.messages.findIndex(m => m.id === tempId)
          if (idx > -1) {
            this.messages.splice(idx, 1, serverMsg)
          } else {
            this.messages.push(serverMsg)
          }
          this.groupMessages()
          this.$nextTick(() => { this.scrollToBottom(true) })
          // clear attachments on success
          this.attachments = []
        } else {
          const idx = this.messages.findIndex(m => m.id === tempId)
          if (idx > -1) {
            this.$set ? this.$set(this.messages[idx], 'failed', true) : (this.messages[idx].failed = true)
            this.$set ? this.$set(this.messages[idx], 'sending', false) : (this.messages[idx].sending = false)
          }
        }
        // Notify typing stopped
        this.notifyTypingStopped()
      } catch (e) {
        console.error(e)
        const idx = this.messages.findIndex(m => m.id === tempId)
        if (idx > -1) {
          this.messages[idx].failed = true
          this.messages[idx].sending = false
        }
      }
    },
    startEdit(message) {
      message.isEditing = true
      message.editText = message.content || message.body || message.message || ''
    },
    async updateMessage(message) {
      if (!message.editText.trim()) return
      try {
        const headers = { 'Content-Type': 'application/json' }
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        const res = await fetch(`/api/chat/messages/${message.id}`, {
          method: 'PUT',
          credentials: 'include',
          headers,
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
        const headers = {}
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        const res = await fetch(`/api/chat/messages/${message.id}`, {
          method: 'DELETE',
          credentials: 'include',
          headers
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
        const headers = { 'Content-Type': 'application/json' }
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        // include Echo socket id if available to allow server-side broadcasting to exclude sender
        try {
          if (window.Echo) {
            if (typeof window.Echo.socketId === 'function') headers['X-Socket-Id'] = window.Echo.socketId()
            else if (window.Echo.connector && typeof window.Echo.connector.socketId === 'function') headers['X-Socket-Id'] = window.Echo.connector.socketId()
          }
        } catch (e) {}
        fetch('/api/chat/typing', {
          method: 'POST',
          credentials: 'include',
          headers,
          body: JSON.stringify({ thread_id: this.activeThread.id })
        })
      } catch (e) { console.error(e) }
    },
    notifyTypingStopped() {
      if (!this.activeThread) return
      try {
        const headers = { 'Content-Type': 'application/json' }
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        try {
          if (window.Echo) {
            if (typeof window.Echo.socketId === 'function') headers['X-Socket-Id'] = window.Echo.socketId()
            else if (window.Echo.connector && typeof window.Echo.connector.socketId === 'function') headers['X-Socket-Id'] = window.Echo.connector.socketId()
          }
        } catch (e) {}
        fetch('/api/chat/typing-stopped', {
          method: 'POST',
          credentials: 'include',
          headers,
          body: JSON.stringify({ thread_id: this.activeThread.id })
        })
      } catch (e) { console.error(e) }
    },
    onFileChange(e) {
      try {
        const files = e.target.files ? Array.from(e.target.files) : []
        for (const f of files) {
          // store File object; keep name for preview
          this.attachments.push(f)
        }
        // reset input so same file can be selected again if removed
        if (this.$refs && this.$refs.fileInput) this.$refs.fileInput.value = null
      } catch (err) { console.error(err) }
    },

    // UI helpers
    toggleEmojiPicker() {
      this.showEmojiPicker = !this.showEmojiPicker
    },
    insertEmoji(emoji) {
      if (!emoji) return
      this.composer = (this.composer || '') + emoji
      this.showEmojiPicker = false
      this.$nextTick(() => { try { if (this.$refs && this.$refs.messageInput) this.$refs.messageInput.focus() } catch (e) {} })
    },
    triggerFileInput() {
      try { if (this.$refs && this.$refs.fileInput) this.$refs.fileInput.click() } catch (e) {}
    },
    getTickState(message) {
      if (!message) return 'none'
      // treat server-persisted messages as delivered/read for UI (show blue ticks)
      if (message.is_read === true) return 'read'
      const id = message.id
      const isOptimistic = typeof id === 'string' && id.startsWith('msg-')
      if (id && !isOptimistic) return 'read'
      return 'single'
    },
    resendFailedMessage(m) {
      if (!m || (!m.content && !m.body)) return
      m.failed = false; m.sending = true
      try {
        const headers = { 'Content-Type': 'application/json' }
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
        fetch('/api/chat/send', { method: 'POST', credentials: 'include', headers, body: JSON.stringify({ content: m.content, recipient_id: this.activeThread ? this.activeThread.id : null }) })
          .then(r => { if (!r.ok) throw new Error('send failed'); return r.json() })
          .then(j => {
            const idx = this.messages.findIndex(x => x.id === m.id)
            if (idx !== -1) this.messages.splice(idx, 1, j.message || j)
            else this.messages.push(j.message || j)
            this.groupMessages()
            this.$nextTick(() => { this.scrollToBottom(true) })
          }).catch(() => { m.sending = false; m.failed = true })
      } catch (e) { m.sending = false; m.failed = true }
    },

    groupMessages() {
      try {
        const msgs = Array.isArray(this.messages) ? this.messages.slice().sort((a, b) => new Date(a.created_at) - new Date(b.created_at)) : []
        const groups = []
        let currentDate = null
        for (const m of msgs) {
          const d = m.created_at ? new Date(m.created_at).toDateString() : (new Date()).toDateString()
          if (d !== currentDate) {
            currentDate = d
            groups.push({ date: currentDate, messages: [] })
          }
          const group = groups[groups.length - 1]
          // ensure message shape for template
          const copy = Object.assign({}, m)
          copy.isEditing = !!copy.isEditing
          copy.editText = copy.editText || ''
          group.messages.push(copy)
        }
        // add index for template checks
        groups.forEach(g => g.messages.forEach((mm, idx) => { mm.index = idx }))
        this.messageGroups = groups
      } catch (e) {
        console.error('groupMessages failed', e)
        this.messageGroups = []
      }
    },
    // Scroll helpers
    scrollToBottom(force = false) {
      try {
        const el = this.$refs.messagesPane
        if (!el) return
        // el may be the actual DOM node or a Vue ref depending on build; normalize
        const node = el instanceof Element ? el : (el.$el || el)
        if (!node) return
        const atBottom = (node.scrollHeight - node.scrollTop - node.clientHeight) <= 200
        if (force || atBottom || this.autoScrollOnNew) {
          node.scrollTop = node.scrollHeight + 200
        }
      } catch (e) {}
    },
    isScrolledNearBottom() {
      try {
        const el = this.$refs.messagesPane
        if (!el) return true
        const node = el instanceof Element ? el : (el.$el || el)
        if (!node) return true
        return (node.scrollHeight - node.scrollTop - node.clientHeight) <= 200
      } catch (e) { return true }
    },
    // Date/time formatting helpers used by the template
    formatDate(d) {
      try {
        if (!d) return ''
        const date = (typeof d === 'string' || typeof d === 'number') ? new Date(d) : d
        if (isNaN(date.getTime())) return String(d)
        const today = new Date()
        if (date.toDateString() === today.toDateString()) return 'Today'
        // show month/day or full year if different year
        if (date.getFullYear() !== today.getFullYear()) return date.toLocaleDateString()
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
      } catch (e) { return String(d) }
    },
    formatTime(ts) {
      try {
        if (!ts) return ''
        const date = (typeof ts === 'string' || typeof ts === 'number') ? new Date(ts) : ts
        if (isNaN(date.getTime())) return String(ts)
        return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
      } catch (e) { return String(ts) }
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
    // mobile detection
    try {
      this.isMobile = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : false
      this._resizeHandler = () => { try { this.isMobile = window.matchMedia('(max-width: 767px)').matches; if (!this.isMobile) this.showChatWindowOnMobile = false } catch (e) {} }
      window.addEventListener('resize', this._resizeHandler)
    } catch (e) {}
  }

  ,
  beforeUnmount() {
    try { if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler) } catch (e) {}
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

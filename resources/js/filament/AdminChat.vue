<template>
  <main class="flex-1 overflow-auto p-3 md:p-4">
    <div class="md:flex h-[calc(100vh-6rem)] overflow-hidden bg-background relative">
      <div class="w-72 duration-300 xl:w-80 border-r flex flex-col max-md:absolute max-md:top-0 max-md:left-0 max-md:h-full max-md:z-10 max-md:bg-background max-md:w-full min-h-0" :class="isMobile && showChatWindowOnMobile ? 'max-md:-translate-x-full' : 'max-md:translate-x-0'">
        <div class="p-4 border-b border-border bg-white text-foreground sticky top-0 z-10 flex-shrink-0">
          <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-semibold">Chats</h1>
          </div>
          <div class="relative">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-primary-foreground/70">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.3-4.3"></path>
            </svg>
            <input 
              class="flex h-10 w-full rounded-md border border-border px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm pl-9 bg-muted/20 text-foreground placeholder:text-muted-foreground" 
              placeholder="Search conversations..."
              v-model="q"
            >
          </div>
        </div>
        <div class="px-4 py-3 border-b border-border bg-white sticky top-[88px] z-10 flex-shrink-0">
          <div dir="ltr" data-orientation="horizontal">
            <div role="tablist" aria-orientation="horizontal" class="h-10 items-center justify-center rounded-md p-1 text-muted-foreground grid w-full grid-cols-3 bg-muted/50">
              <button 
                v-for="tab in tabs" 
                :key="tab.value"
                type="button" 
                role="tab" 
                :aria-selected="activeTab === tab.value"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 font-medium ring-offset-background transition-all data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 text-sm"
                :data-state="activeTab === tab.value ? 'active' : 'inactive'"
                @click="activeTab = tab.value"
              >
                {{ tab.label }}
              </button>
            </div>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto min-h-0" style="-webkit-overflow-scrolling: touch;">
          <transition-group name="list" tag="div">
          <button 
            v-for="thread in filteredThreads" 
            :key="threadKey(thread)" 
            class="w-full p-4 flex items-start gap-3 hover:bg-muted/20 transition-colors border-b border-border/60"
            :class="String(thread.id) === String(activeThread?.id) ? 'bg-muted/10' : ''"
            @click="openThread(thread)"
            type="button"
          >
            <div class="relative flex-shrink-0">
              <span class="relative flex shrink-0 overflow-hidden rounded-full h-12 w-12">
                <img :src="thread._resolvedAvatar" :alt="thread.name" class="h-full w-full object-cover rounded-full" loading="lazy" decoding="async" />
              </span>
              <div v-if="thread.status === 'online'" class="absolute bottom-0 right-0 h-3 w-3 bg-primary rounded-full border-2 border-white"></div>
            </div>
            <div class="flex-1 min-w-0 text-left">
              <div class="flex items-baseline justify-between mb-1">
                <h3 class="font-semibold text-foreground truncate">{{ thread.name || thread.other_name || ('User ' + thread.id) }}</h3>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-muted-foreground flex-shrink-0">{{ formatTime(thread.last_at || thread.updated_at) }}</span>
                  <span v-if="((thread.unread_count ?? thread.unread) || 0) > 0" class="inline-flex items-center justify-center bg-primary text-white text-xs rounded-full px-2 py-0.5">{{ thread.unread_count ?? thread.unread }}</span>
                </div>
              </div>
              <p class="text-sm text-muted-foreground whitespace-normal break-words overflow-hidden">{{ thread.last_preview || '' }}</p>
            </div>
          </button>
          </transition-group>
        </div>
      </div>
      <div v-if="!isMobile || showChatWindowOnMobile" class="flex flex-1 flex-col min-w-0 overflow-hidden bg-gradient-to-b from-muted/30 to-background min-h-0 max-md:absolute max-md:inset-0 max-md:top-0 max-md:z-20">
      <div class="flex items-center gap-3 p-4 bg-white border-b border-border sticky top-0 z-10 flex-shrink-0">
        <button 
          v-if="isMobile && showChatWindowOnMobile" 
          class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-accent hover:text-accent-foreground md:hidden h-9 w-9"
          @click="showChatWindowOnMobile = false"
          aria-label="Back to chats"
          type="button"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-left h-5 w-5">
            <path d="m12 19-7-7 7-7"></path>
            <path d="M19 12H5"></path>
          </svg>
        </button>
        <div class="relative" v-if="activeThread">
          <span class="relative flex shrink-0 overflow-hidden rounded-full h-10 w-10">
            <img :src="activeThread._resolvedAvatar" :alt="activeThread.name" class="h-full w-full object-cover rounded-full" loading="lazy" decoding="async" />
          </span>
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="font-semibold text-foreground truncate">{{ activeThread?.name || activeThread?.other_name || 'Select a conversation' }}</h2>
          <p class="text-xs text-muted-foreground">{{ activeThread?.status || '' }}</p>
        </div>
        <button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-accent hover:text-accent-foreground h-9 w-9" type="button">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ellipsis-vertical h-5 w-5">
            <circle cx="12" cy="12" r="1"></circle>
            <circle cx="12" cy="5" r="1"></circle>
            <circle cx="12" cy="19" r="1"></circle>
          </svg>
        </button>
      </div>
      <div ref="messagesPane" class="flex-1 overflow-y-auto p-4 space-y-4 min-h-0">
        <div v-if="loadingMore" class="text-center text-xs text-muted-foreground">Loading more messages...</div>
        <template v-for="(group, index) in messageGroups" :key="index">
          <div class="space-y-4">
            <div class="text-xs text-muted-foreground text-center">{{ formatDate(group.date) }}</div>
            <div
              v-for="message in group.messages"
              :key="message.id"
              class="flex w-full"
              :class="String(message.sender_id) === String(adminId) ? 'justify-end' : 'justify-start'"
            >
              <div :class="String(message.sender_id) === String(adminId) ? 'chat-bubble sent' : 'chat-bubble received'" class="rounded-lg px-4 py-2">
                <template v-if="message.attachments && message.attachments.length">
                  <div class="mb-2 space-y-2">
                    <div v-for="(a, i) in message.attachments" :key="i" class="rounded overflow-hidden border bg-background">
                      <a :href="a.url || a.path" target="_blank" class="block p-2 text-sm underline text-primary-foreground">{{ a.name || a.filename || a.url }}</a>
                    </div>
                  </div>
                </template>
                <p class="text-sm whitespace-pre-wrap">{{ message.content || message.text || message.body }}</p>
                <div class="flex items-center justify-end gap-2 mt-1">
                  <p class="text-xs" :class="String(message.sender_id) === String(adminId) ? 'text-muted-foreground/80' : 'text-muted-foreground'">{{ formatTime(message.created_at) }}</p>
                  <template v-if="String(message.sender_id) === String(adminId)">
                    <span class="ml-1">
                      <template v-if="message.sending">
                        <svg class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                      </template>
                      <template v-else-if="message.failed">
                        <svg class="tick text-rose-500" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v6"></path><path d="M12 15v.01"></path></svg>
                      </template>
                      <template v-else>
                        <svg v-if="getTickState(message) === 'single'" class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                        <svg v-else-if="getTickState(message) === 'double'" class="tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path><path d="M22 6 11 17l-5-5"></path></svg>
                        <svg v-else-if="getTickState(message) === 'read'" class="tick tick-read" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path><path d="M22 6 11 17l-5-5"></path></svg>
                      </template>
                    </span>
                  </template>
                  <div v-if="message.failed && String(message.sender_id) === String(adminId)" class="flex items-center gap-2">
                    <button @click="resendFailedMessage(message)" class="text-xs text-rose-500 underline">Retry</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
        <div v-if="typing" class="flex items-center gap-2 text-muted-foreground text-sm">
          <div class="flex-shrink-0 w-8 h-8 rounded-full overflow-hidden">
            <img v-if="activeThread && activeThread.avatar" :src="activeThread.avatar" class="w-full h-full object-cover" />
            <div v-else class="w-full h-full bg-primary text-primary-foreground flex items-center justify-center text-xs">
              {{ (activeThread?.name || activeThread?.other_name || 'U').slice(0, 1).toUpperCase() }}
            </div>
          </div>
          <div class="typing-indicator">
            <span></span>
            <span></span>
            <span></span>
          </div>
        </div>
        <div ref="messagesEnd"></div>
      </div>
      <div class="p-4 bg-white border-t border-border sticky bottom-0 z-10 flex-shrink-0">
        <div class="flex items-end gap-2" style="margin-bottom:8px">
          <input ref="fileInput" type="file" class="hidden" @change="onFileChange" multiple>
          <button
            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-accent h-10 w-10 flex-shrink-0 text-muted-foreground hover:text-foreground"
            @click="triggerFileInput()"
            type="button"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-paperclip h-5 w-5">
              <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
            </svg>
          </button>
          <div class="flex-1 relative bg-background border border-input rounded-lg flex items-center pr-2">
            <input
              ref="messageInput"
              v-model="composer"
              class="flex h-10 w-full rounded-md px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm flex-1 border-0 focus-visible:ring-0 focus-visible:ring-offset-0 bg-transparent"
              placeholder="Type a message..."
              @keyup.enter="send"
              @input="notifyTyping"
              @blur="notifyTypingStopped"
            />
            <button @click="toggleEmojiPicker" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 hover:bg-accent h-8 w-8 flex-shrink-0 text-muted-foreground hover:text-foreground" style="margin-bottom:6px" type="button">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-smile h-5 w-5">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                <line x1="9" x2="9.01" y1="9" y2="9"></line>
                <line x1="15" x2="15.01" y1="9" y2="9"></line>
              </svg>
            </button>
            <div v-if="showEmojiPicker" class="absolute bottom-12 left-2 z-30 bg-white border rounded shadow p-2 grid grid-cols-6 gap-2 w-56">
              <button v-for="emoji in emojis" :key="emoji" @click.prevent="insertEmoji(emoji)" class="text-lg" type="button">{{ emoji }}</button>
            </div>
            <button
              class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 text-primary-foreground h-8 w-8 flex-shrink-0 bg-primary hover:bg-primary/90 ml-1"
              @click="send"
              :disabled="!composer || !composer.trim()"
              type="button"
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-send h-4 w-4">
                <path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"></path>
                <path d="m21.854 2.147-10.94 10.939"></path>
              </svg>
            </button>
          </div>
        </div>
        <div v-if="attachments.length" class="flex gap-2 items-center flex-wrap">
          <div v-for="(f, i) in attachments" :key="i" class="flex items-center gap-2 border px-2 py-1 rounded bg-gray-50 text-xs">
            <span>{{ f.name }}</span>
            <button @click="attachments.splice(i, 1)" class="text-rose-500 hover:text-rose-700 font-bold" type="button">×</button>
          </div>
        </div>
      </div>
    </div>
    </div>
  </main>
</template>

<script>
export default {
  name: 'AdminChat',
  data() {
    return {
      tabs: [
        { label: 'Recent', value: 'all' },
        { label: 'Online', value: 'online' },
        { label: 'Unread', value: 'unread' }
      ],
      activeTab: 'all',
      threads: [],
      messages: [],
      activeThread: null,
      composer: '',
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
      isMobile: false,
      showChatWindowOnMobile: false,
      emojis: ['😀','😂','😍','👍','🙏','🎉','😅','🙌','😉','🔥','😢','🤔'],
    }
  },
  computed: {
    filteredThreads() {
      const list = Array.isArray(this.threads) ? this.threads.slice() : []
      const q = (this.q || '').toString().toLowerCase().trim()
      let filtered = list.filter(thread => {
        if (this.activeTab === 'online') return (thread.status || thread.presence || '').toString().toLowerCase() === 'online'
        if (this.activeTab === 'unread') return (thread.unread_count || 0) > 0
        return true
      })
      if (q) {
        filtered = filtered.filter(thread => {
          const name = (thread.name || thread.other_name || '').toString().toLowerCase()
          const preview = (thread.last_preview || thread.last_message || '').toString().toLowerCase()
          return name.includes(q) || preview.includes(q)
        })
      }
      filtered.sort((a, b) => {
        const A = new Date(a.last_at || a.updated_at || 0).getTime()
        const B = new Date(b.last_at || b.updated_at || 0).getTime()
        return B - A
      })
      return filtered.map(t => ({
        ...t,
        _resolvedAvatar: this.resolveAvatar(t)
      }))
    }
  },
  methods: {
    resolveAvatar(thread) {
      const avatarPlaceholder = '/logo/avatar-placeholder.png'
      try {
        if (!thread) return avatarPlaceholder
        
        let val = null
        let name = null
        
        if (typeof thread === 'object') {
          // Priority fields matching frontend resolveUserAvatar
          val = thread.avatar_url || thread.avatar || thread.image || thread.picture || thread.photo || thread.avatarUrl || thread.profile_image || null
          
          // Check nested profile if still null
          if (!val && thread.profile) {
            const p = thread.profile
            val = p.avatar_url || p.avatar || p.image || p.photo || null
          }
          
          name = thread.name || thread.other_name || thread.displayName || null
        } else {
          val = thread
        }
        
        // If we have a string that looks like a path but isn't a full URL, we might need a loader or base URL.
        // But for now, we'll follow the resolveAvatar logic from frontend as closely as possible.
        if (val && typeof val === 'string') {
          if (val.startsWith('http') || val.startsWith('/') || val.startsWith('data:')) {
            return val
          }
          // Handle storage paths (standardizing with frontend)
          if (val.startsWith('storage/')) return '/' + val
          if (val.startsWith('public/')) return '/' + val.replace('public/', 'storage/')
          return '/storage/' + val
        }

        // Fallback to UI Avatars for consistency with frontend letter avatars if possible
        if (name) {
          return `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=random&color=fff`
        }
        
        return avatarPlaceholder
      } catch (e) {
        console.error('Avatar resolution failed', e)
        return avatarPlaceholder
      }
    },
    threadKey(thread) {
      if (!thread) return 'thread-null'
      return thread.id ?? thread.other_id ?? thread.other_user_id ?? thread.thread_id ?? thread.uuid ?? (thread.email ? `email-${thread.email}` : `name-${(thread.name || thread.other_name || 'unknown')}`)
    },
    async loadThreads() {
      try {
        const res = await fetch('/api/chat/threads', { credentials: 'include' })
        if (res.ok) {
          const j = await res.json()
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
            this.$nextTick(() => { this.scrollToBottom(true) })
          }
      } catch (e) { console.error(e) }
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
      this.messages.push(tempMsg)
      this.groupMessages()
      this.composer = ''
      this.$nextTick(() => { this.scrollToBottom(true) })

      try {
        let res
        const tokenMeta = document.querySelector('meta[name="csrf-token"]')
        const headers = {}
        if (tokenMeta) headers['X-CSRF-TOKEN'] = tokenMeta.getAttribute('content')
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
            fd.append('attachments[]', f)
          })
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
          const idx = this.messages.findIndex(m => m.id === tempId)
          if (idx > -1) {
            this.messages.splice(idx, 1, serverMsg)
          } else {
            this.messages.push(serverMsg)
          }
          this.groupMessages()
          this.$nextTick(() => { this.scrollToBottom(true) })
          this.attachments = []
        } else {
          const idx = this.messages.findIndex(m => m.id === tempId)
          if (idx > -1) {
            this.$set ? this.$set(this.messages[idx], 'failed', true) : (this.messages[idx].failed = true)
            this.$set ? this.$set(this.messages[idx], 'sending', false) : (this.messages[idx].sending = false)
          }
        }
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
    notifyTyping() {
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
          this.attachments.push(f)
        }
        if (this.$refs && this.$refs.fileInput) this.$refs.fileInput.value = null
      } catch (err) { console.error(err) }
    },
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
      if (message.is_read) return 'read'
      if (message.id && !String(message.id).startsWith('msg-')) return 'double'
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
          const copy = Object.assign({}, m)
          copy.isEditing = !!copy.isEditing
          copy.editText = copy.editText || ''
          group.messages.push(copy)
        }
        groups.forEach(g => g.messages.forEach((mm, idx) => { mm.index = idx }))
        this.messageGroups = groups
      } catch (e) {
        console.error('groupMessages failed', e)
        this.messageGroups = []
      }
    },
    scrollToBottom(force = false) {
      try {
        const el = this.$refs.messagesPane
        if (!el) return
        const node = el instanceof Element ? el : (el.$el || el)
        if (!node) return
        const atBottom = (node.scrollHeight - node.scrollTop - node.clientHeight) <= 200
        if (force || atBottom || this.autoScrollOnNew) {
          node.scrollTop = node.scrollHeight + 200
        }
      } catch (e) {}
    },
    formatDate(d) {
      try {
        if (!d) return ''
        const date = (typeof d === 'string' || typeof d === 'number') ? new Date(d) : d
        if (isNaN(date.getTime())) return String(d)
        const today = new Date()
        if (date.toDateString() === today.toDateString()) return 'Today'
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
    this.adminId = window.__ADMIN_ID__ || null
    await this.loadThreads()
    const tryAttachEcho = () => {
      if (window.Echo) {
        this.attachEcho()
      } else {
        setTimeout(tryAttachEcho, 100)
      }
    }
    tryAttachEcho()
    try {
      this.isMobile = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : false
      this._resizeHandler = () => { try { this.isMobile = window.matchMedia('(max-width: 767px)').matches; if (!this.isMobile) this.showChatWindowOnMobile = false } catch (e) {} }
      window.addEventListener('resize', this._resizeHandler)
    } catch (e) {}
  },
  beforeUnmount() {
    try { if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler) } catch (e) {}
  }
}
</script>

<style scoped>
::-webkit-scrollbar {
  display: none;
}

* {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.chat-bubble {
  display: inline-block;
  /* keep the window shape stable: limit width by ch (characters) and percentage */
  max-width: min(65%, 36ch);
  min-width: 6ch;
  word-wrap: break-word;
  overflow-wrap: anywhere;
  -webkit-font-smoothing: antialiased;
}

.chat-bubble.sent {
  background: #891f21;
  color: #ffffff;
  border-radius: 18px 18px 4px 18px;
}

.chat-bubble.received {
  background: #F9B82E;
  color: #111827;
  border-radius: 18px 18px 18px 4px;
  box-shadow: 0 1px 0 rgba(0,0,0,0.05);
}

/* make sure message row doesn't overflow */
.flex.w-full > .chat-bubble { max-width: 70%; }

/* tick icon styles */
.tick { display: inline-block; vertical-align: middle; stroke: #8696a0; color: #8696a0; }
.tick-read { stroke: #f7b932; color: #f7b932; }
.chat-bubble.received .tick { stroke: rgba(0,0,0,0.45); color: rgba(0,0,0,0.45); }
.chat-bubble.sent .tick { stroke: #8696a0; color: #8696a0; }
.tick { width: 14px; height: 14px; margin-left: 6px }

.chat-bubble .meta { font-size: 11px; opacity: 0.8 }

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

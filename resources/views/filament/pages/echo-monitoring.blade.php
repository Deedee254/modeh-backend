<x-filament::page>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="filament-page-heading">Echo Monitoring</h2>
                <p class="text-sm text-muted-foreground">Realtime health and recent messages for the Echo/Realtime system.</p>
            </div>
            <div class="flex items-center gap-2">
                {{-- Filament will render page actions (Refresh / Prune) here from the Page class --}}
            </div>
        </div>
    </x-slot>

    <div id="echo-monitoring-root" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <div class="col-span-1 space-y-6">
                <x-filament::card>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium">System Status</h3>
                            <p class="text-sm text-gray-500">Realtime health of Echo</p>
                        </div>
                        <div id="echo-status" class="inline-flex items-center px-3 py-1 rounded-full bg-gray-200 text-sm">Loading...</div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs text-gray-500">Connections</div>
                            <div id="echo-connections" class="text-lg font-semibold">—</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">Last message</div>
                            <div id="echo-last-message" class="text-lg font-semibold">—</div>
                        </div>
                    </div>

                    <!-- Small sparkline for connections trend -->
                    <div class="mt-4">
                        <canvas id="echo-connections-spark" height="40"></canvas>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <h3 class="text-lg font-medium">Retention</h3>
                    <p class="text-sm text-gray-500">Buckets older than configured retention will be removed by prune.</p>
                    <div class="mt-3 flex items-center justify-between">
                        <div id="echo-retention" class="text-lg font-semibold">30d</div>
                        <div class="text-sm text-gray-500">Use the Prune action to run now</div>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <h3 class="text-lg font-medium">Controls</h3>
                    <div class="mt-3 flex items-center gap-3">
                        <label class="text-sm text-gray-600">Auto refresh</label>
                        <button id="echo-auto-toggle" class="px-2 py-1 text-xs rounded bg-gray-100">Off</button>
                        <select id="echo-window" class="text-sm border rounded px-2 py-1">
                            <option value="10">Last 10 min</option>
                            <option value="30">Last 30 min</option>
                            <option value="60">Last 60 min</option>
                        </select>
                    </div>
                </x-filament::card>
            </div>

            <div class="col-span-3 space-y-6">
                <x-filament::card>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium">Messages</h3>
                            <p class="text-sm text-gray-500">Messages per minute</p>
                        </div>
                        <div id="echo-footer" class="text-sm text-gray-500">Total: —</div>
                    </div>
                    <div class="mt-4 h-64">
                        <canvas id="echo-messages-chart" height="180"></canvas>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium">Recent Messages</h3>
                            <p class="text-sm text-gray-500">Latest incoming messages</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="echo-filter" type="text" placeholder="Filter by actor/text" class="text-sm border rounded px-2 py-1" />
                            <select id="echo-limit" class="text-sm border rounded px-2 py-1">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    <div id="echo-recent-messages" class="mt-4 space-y-3 max-h-56 overflow-auto">
                        <div class="text-sm text-gray-500">No messages yet.</div>
                    </div>
                </x-filament::card>
            </div>
        </div>
    </div>

    @push('scripts')
        <!-- Load Chart.js from local vendor (fallback loader) -->
        <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
        <script>
            (() => {
                const statusEl = document.getElementById('echo-status')
                const connectionsEl = document.getElementById('echo-connections')
                const lastMsgEl = document.getElementById('echo-last-message')
                const retentionEl = document.getElementById('echo-retention')
                const footerEl = document.getElementById('echo-footer')
                const recentEl = document.getElementById('echo-recent-messages')
                const chartCanvas = document.getElementById('echo-messages-chart')
                const sparkCanvas = document.getElementById('echo-connections-spark')
                const filterEl = document.getElementById('echo-filter')
                const limitEl = document.getElementById('echo-limit')
                const autoBtn = document.getElementById('echo-auto-toggle')
                const windowEl = document.getElementById('echo-window')

                let chart = null
                let spark = null
                let configuredDays = 30
                let auto = false
                let timer = null
                let windowMinutes = 10

                function setAuto(v) {
                    auto = !!v
                    autoBtn.textContent = auto ? 'On' : 'Off'
                    autoBtn.className = auto ? 'px-2 py-1 text-xs rounded bg-green-100 text-green-700' : 'px-2 py-1 text-xs rounded bg-gray-100'
                    if (auto && !timer) timer = setInterval(fetchStats, 5000)
                    if (!auto && timer) { clearInterval(timer); timer = null }
                }

                autoBtn?.addEventListener('click', () => setAuto(!auto))
                windowEl?.addEventListener('change', (e) => {
                    windowMinutes = parseInt(e.target.value || '10', 10)
                    fetchStats()
                })

                // Listen actions from server-side triggers
                window.addEventListener('echo:refreshed', () => fetchStats())
                window.addEventListener('echo:pruned', () => fetchStats())
                window.addEventListener('echo:auto-toggle', () => setAuto(!auto))

                async function fetchConfiguredDays() {
                    try {
                        const res = await fetch('/api/admin/echo/settings', { credentials: 'same-origin' })
                        if (res.ok) {
                            const j = await res.json()
                            configuredDays = j.retention_days || 30
                            retentionEl.textContent = `${configuredDays}d`
                            const pruneBtn = document.getElementById('echo-prune')
                            if (pruneBtn) pruneBtn.textContent = `Prune (${configuredDays}d)`
                        }
                    } catch (e) { console.error('Failed to fetch configured retention days', e) }
                }

                async function fetchStats() {
                    try {
                        const params = new URLSearchParams({ window: String(windowMinutes) })
                        const [healthRes, statsRes] = await Promise.all([
                            fetch('/api/admin/echo/health', { credentials: 'same-origin' }),
                            fetch(`/api/admin/echo/stats?${params}`, { credentials: 'same-origin' }),
                        ])
                        if (!healthRes.ok) throw new Error('Health fetch failed')
                        if (!statsRes.ok) throw new Error('Stats fetch failed')
                        const health = await healthRes.json()
                        const data = await statsRes.json()
                        updateUI(health, data)
                    } catch (e) {
                        statusEl.textContent = 'Error'
                        statusEl.className = 'inline-flex items-center px-3 py-1 rounded-full bg-yellow-100 text-yellow-800'
                        console.error(e)
                        dispatchEvent(new CustomEvent('notify', { detail: { type: 'danger', message: 'Failed to fetch Echo stats', description: e.message || String(e) } }))
                    }
                }

                function updateUI(health, data) {
                    const alive = health.status === 'ok'
                    statusEl.textContent = alive ? 'OK' : 'DOWN'
                    statusEl.className = alive ? 'inline-flex items-center px-3 py-1 rounded-full bg-green-100 text-green-800' : 'inline-flex items-center px-3 py-1 rounded-full bg-red-100 text-red-800'
                    connectionsEl.textContent = `${health.connections ?? 0}`
                    lastMsgEl.textContent = `${health.last_message_at ? new Date(health.last_message_at * 1000).toLocaleString() : 'n/a'}`

                    const total = data.messages_total ?? 0
                    const labels = data.messages_per_minute_labels || []
                    const series = data.messages_per_minute_series || []
                    const connectionsSeries = data.connections_series || []

                    // main chart
                    if (chartCanvas) {
                        const ctx = chartCanvas.getContext('2d')
                        if (!chart) {
                            chart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: labels,
                                    datasets: [{ label: 'messages/min', data: series, borderColor: '#6366f1', fill: true, backgroundColor: 'rgba(99,102,241,0.08)', tension: 0.3 }]
                                },
                                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
                            })
                        } else {
                            chart.data.labels = labels
                            chart.data.datasets[0].data = series
                            chart.update()
                        }
                    }

                    // sparkline
                    if (sparkCanvas) {
                        const sctx = sparkCanvas.getContext('2d')
                        if (!spark) {
                            spark = new Chart(sctx, {
                                type: 'line',
                                data: { labels: connectionsSeries.map((_, i) => i), datasets: [{ data: connectionsSeries, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)', fill: true, tension: 0.3, pointRadius: 0 }] },
                                options: { responsive: true, maintainAspectRatio: false, scales: { x: { display: false }, y: { display: false } }, plugins: { legend: { display: false } } }
                            })
                        } else {
                            spark.data.labels = connectionsSeries.map((_, i) => i)
                            spark.data.datasets[0].data = connectionsSeries
                            spark.update()
                        }
                    }

                    footerEl.textContent = `Total: ${total}`

                    // Render recent messages list with filtering & limit
                    if (recentEl) {
                        recentEl.innerHTML = ''
                        const rawMsgs = data.recent_messages || []
                        const q = (filterEl?.value || '').toLowerCase()
                        const limit = parseInt(limitEl?.value || '50', 10)
                        const msgs = rawMsgs.filter(m => !q || `${m.actor||''} ${m.text||''}`.toLowerCase().includes(q)).slice(0, limit)

                        if (msgs.length === 0) {
                            recentEl.innerHTML = '<div class="text-sm text-gray-500">No messages yet.</div>'
                        } else {
                            msgs.forEach(m => {
                                const el = document.createElement('div')
                                el.className = 'p-3 bg-white rounded shadow-sm border'
                                el.innerHTML = `<div class="flex items-start justify-between"><div class="text-sm text-gray-700 font-medium">${escapeHtml(m.actor || 'unknown')}</div><div class="text-xs text-gray-400">${m.ts ? new Date(m.ts * 1000).toLocaleTimeString() : ''}</div></div><div class="text-xs text-gray-600 mt-1 whitespace-pre-wrap">${escapeHtml(m.text || '')}</div>`
                                recentEl.appendChild(el)
                            })
                        }
                    }
                }

                function escapeHtml(s) {
                    if (!s) return ''
                    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                }

                // initial
                fetchConfiguredDays()
                fetchStats()
                setAuto(false) // default off
            })()
        </script>
    @endpush
</x-filament::page>

<div
    x-data="{
        toasts: [],
        add(type, message, duration = 5000) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type, message, visible: true });
            if (duration > 0) {
                setTimeout(() => this.remove(id), duration);
            }
        },
        remove(id) {
            const idx = this.toasts.findIndex(t => t.id === id);
            if (idx > -1) {
                this.toasts[idx].visible = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter(t => t.id !== id);
                }, 300);
            }
        },
    }"
    x-on:toast.window="add($event.detail.type, $event.detail.message, $event.detail.duration)"
    aria-live="polite"
    aria-atomic="false"
    class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="toast.visible"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-y-2 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-2 opacity-0"
            :class="{
                'bg-emerald-600 text-white': toast.type === 'success',
                'bg-red-600 text-white': toast.type === 'error',
                'bg-amber-500 text-white': toast.type === 'warning',
                'bg-blue-600 text-white': toast.type === 'info',
            }"
            class="pointer-events-auto rounded-lg px-4 py-3 shadow-lg flex items-center justify-between"
            role="alert"
        >
            <span x-text="toast.message" class="text-sm font-medium"></span>
            <button
                type="button"
                x-on:click="remove(toast.id)"
                class="ml-3 text-white/80 hover:text-white focus:outline-none"
                aria-label="Dismiss"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</div>

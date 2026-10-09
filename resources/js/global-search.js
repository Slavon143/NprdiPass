export default function globalSearch() {
    return {
        query: '',
        items: [],
        loading: false,
        error: false,
        open: false,
        activeIndex: -1,
        timer: null,
        controller: null,

        init() {
            this.query = this.$refs.input.value;
        },

        onInput(event) {
            this.query = event.target.value;
            this.items = [];
            this.activeIndex = -1;
            this.error = false;
            clearTimeout(this.timer);
            this.controller?.abort();
            this.controller = null;

            if (this.query.trim().length < 2) {
                this.loading = false;
                this.open = false;
                return;
            }

            this.open = true;
            this.loading = true;
            this.timer = setTimeout(() => this.search(), 300);
        },

        onFocus() {
            if (this.query.trim().length >= 2) {
                this.open = true;
                if (this.items.length === 0 && !this.loading) this.onInput({ target: this.$refs.input });
            }
        },

        async search() {
            const query = this.query.trim();
            const url = new URL(this.$refs.form.dataset.suggestionsUrl, window.location.origin);
            url.searchParams.set('q', query);
            const controller = new AbortController();
            this.controller = controller;

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Search request failed');
                const payload = await response.json();
                if (this.controller !== controller) return;
                this.items = payload.data;
            } catch (error) {
                if (error.name === 'AbortError' || this.controller !== controller) return;
                this.error = true;
            } finally {
                if (this.controller === controller) this.loading = false;
            }
        },

        move(direction) {
            if (!this.open || this.items.length === 0) return;
            this.activeIndex = this.activeIndex === -1 && direction < 0
                ? this.items.length - 1
                : (this.activeIndex + direction + this.items.length) % this.items.length;
        },

        resultsUrl() {
            const url = new URL(this.$refs.form.action, window.location.origin);
            if (this.query.trim()) url.searchParams.set('q', this.query.trim());
            return url.toString();
        },

        go() {
            window.location.assign(this.activeIndex >= 0 ? this.items[this.activeIndex].url : this.resultsUrl());
        },

        close() {
            this.open = false;
            this.activeIndex = -1;
        },
    };
}

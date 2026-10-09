export function registerUiComponents(Alpine) {
    Alpine.data('positionedMenu', () => ({
        open: false,
        toggle() {
            this.open = !this.open;
            if (!this.open) return;
            const rect = this.$refs.button.getBoundingClientRect();
            this.$refs.menu.style.top = `${rect.bottom + 4}px`;
            this.$refs.menu.style.left = `${Math.max(8, Math.min(rect.right - 224, window.innerWidth - 232))}px`;
        },
    }));

    Alpine.data('savedNotice', () => ({
        show: true,
        init() { setTimeout(() => { this.show = false; }, 2000); },
    }));

    Alpine.data('copyToken', () => ({
        copied: false,
        async copy() {
            await navigator.clipboard.writeText(this.$root.querySelector('#plain-api-token').textContent);
            this.copied = true;
        },
    }));

    Alpine.data('confirmSubmit', () => ({
        submit() {
            if (window.confirm(this.$el.dataset.confirmMessage)) this.$el.submit();
        },
    }));

    Alpine.data('passportSection', () => window.passportSection(window.passportSectionConfig));

    Alpine.data('modal', (show = false, focusable = false) => ({
        show,
        init() {
            this.$watch('show', (value) => {
                document.body.classList.toggle('overflow-y-hidden', value);
                if (value && focusable) setTimeout(() => this.firstFocusable()?.focus(), 100);
            });
        },
        focusables() {
            const selector = "a, button, input:not([type='hidden']), textarea, select, details, [tabindex]:not([tabindex='-1'])";
            return [...this.$el.querySelectorAll(selector)].filter(el => !el.hasAttribute('disabled'));
        },
        firstFocusable() { return this.focusables()[0]; },
        lastFocusable() { return this.focusables().slice(-1)[0]; },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable(); },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable(); },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1); },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1; },
    }));
}

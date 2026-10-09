const preferenceKey = 'nordipass.sidebar.collapsed';

export default function appShell() {
    return {
        sidebarOpen: false,
        sidebarCollapsed: false,
        init() {
            try {
                this.sidebarCollapsed = window.localStorage.getItem(preferenceKey) === 'true';
            } catch {
                // Navigation still works when browser storage is unavailable.
            }
        },
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            try {
                window.localStorage.setItem(preferenceKey, String(this.sidebarCollapsed));
            } catch {
                // Keep the choice for this page even if it cannot be saved.
            }
        },
        closeMobileSidebar() {
            this.sidebarOpen = false;
        },
    };
}

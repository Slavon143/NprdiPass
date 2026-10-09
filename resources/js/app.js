

import Alpine from '@alpinejs/csp';
import globalSearch from './global-search';
import appShell from './app-shell';
import { registerUiComponents } from './ui-components';

window.Alpine = Alpine;

Alpine.data('globalSearch', globalSearch);
Alpine.data('appShell', appShell);
registerUiComponents(Alpine);

Alpine.start();

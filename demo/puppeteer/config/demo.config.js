const APP_URL = process.env.NORDIPASS_APP_URL || 'http://nordipass.test';
const DEMO_EMAIL = process.env.NORDIPASS_DEMO_EMAIL || 'admin@nordipass.local';
const DEMO_PASSWORD = process.env.NORDIPASS_DEMO_PASSWORD || 'password';

const generateRunId = () => {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const Y = now.getFullYear();
  const M = pad(now.getMonth() + 1);
  const D = pad(now.getDate());
  const h = pad(now.getHours());
  const m = pad(now.getMinutes());
  const s = pad(now.getSeconds());
  return `DEMO-${Y}${M}${D}-${h}${m}${s}`;
};

const isCI = process.env.CI === 'true' || process.env.NODE_ENV === 'ci';

const config = {
  appUrl: APP_URL,
  demoEmail: DEMO_EMAIL,
  demoPassword: DEMO_PASSWORD,

  browser: {
    headless: isCI ? 'new' : false,
    slowMo: isCI ? 0 : 60,
    defaultViewport: { width: 1440, height: 900 },
    args: isCI
      ? ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
      : [],
  },

  timeouts: {
    default: 20000,
    navigation: 10000,
    waitAfterAction: 800,
  },

  demoCompanyName: 'NordiPass Demo AB',

  runId: generateRunId(),

  output: {
    screenshots: './output/screenshots',
    downloads: './output/downloads',
    errors: './output/errors',
  },
};

export default config;
export { APP_URL, DEMO_EMAIL, DEMO_PASSWORD };

export const RUN_ID = config.runId;
export const BROWSER_CONFIG = config.browser;
export const TIMEOUTS = config.timeouts;
export const OUTPUT_DIRS = config.output;
export const DEMO_COMPANY_NAME = config.demoCompanyName;
export const CI_MODE = isCI;

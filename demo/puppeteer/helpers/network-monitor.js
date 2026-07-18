import { Page } from 'puppeteer';

export function startNetworkMonitor(page) {
  const failedRequests = [];
  const consoleErrors = [];

  const onRequestFailed = (request) => {
    const url = request.url();
    const status = request.response ? request.response().status() : 'N/A';
    failedRequests.push({ url, status, error: request.failure().errorText });
  };

  const onResponse = (response) => {
    if (response.status() >= 400) {
      failedRequests.push({
        url: response.url(),
        status: response.status(),
        error: response.statusText(),
      });
    }
  };

  const onConsole = (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push({ text: msg.text(), type: msg.type() });
    }
  };

  page.on('requestfailed', onRequestFailed);
  page.on('response', onResponse);
  page.on('console', onConsole);

  return {
    getResults() {
      return { failedRequests: [...failedRequests], consoleErrors: [...consoleErrors] };
    },
    stop() {
      page.off('requestfailed', onRequestFailed);
      page.off('response', onResponse);
      page.off('console', onConsole);
    },
  };
}

export function getFailedRequests(monitor) {
  return monitor.getResults().failedRequests;
}

export function getConsoleErrors(monitor) {
  return monitor.getResults().consoleErrors;
}

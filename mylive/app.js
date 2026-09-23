(function () {
  'use strict';

  var deferredInstallPrompt = null;
  var installButtons = [];

  function all(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function isIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
  }

  function refreshInstallUi() {
    installButtons.forEach(function (button) {
      if (isStandalone()) {
        button.hidden = false;
        button.disabled = true;
        button.textContent = 'APP INSTALLED';
        button.dataset.state = 'installed';
        return;
      }

      if (deferredInstallPrompt) {
        button.hidden = false;
        button.disabled = false;
        button.textContent = 'INSTALL MYLIVE APP';
        button.dataset.state = 'ready';
        return;
      }

      if (isIos()) {
        button.hidden = false;
        button.disabled = false;
        button.textContent = 'INSTALL ON IPHONE';
        button.dataset.state = 'ios';
        return;
      }

      button.hidden = true;
    });
  }

  function showIosInstallHelp() {
    var message = 'Στο iPhone άνοιξε το Share menu του Safari και επίλεξε “Add to Home Screen”. Μετά άνοιγε το MyLive από το εικονίδιο της αρχικής οθόνης.';

    if (window.DeseoDialog) {
      window.DeseoDialog.alert(message, {
        title: 'Install MyLive App',
        confirmLabel: 'OK'
      });
    }
  }

  function initInstallButtons() {
    installButtons = all('[data-mylive-install]');

    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      deferredInstallPrompt = event;
      refreshInstallUi();
    });

    window.addEventListener('appinstalled', function () {
      deferredInstallPrompt = null;
      refreshInstallUi();
    });

    installButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        if (button.dataset.state === 'ios') {
          showIosInstallHelp();
          return;
        }

        if (!deferredInstallPrompt) return;

        deferredInstallPrompt.prompt();
        deferredInstallPrompt.userChoice.finally(function () {
          deferredInstallPrompt = null;
          refreshInstallUi();
        });
      });
    });

    refreshInstallUi();
  }

  function registerMyLiveServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    // Webpushr owns the /mylive/ push scope; its worker also loads the existing MyLive PWA worker.
    navigator.serviceWorker.register('/mylive/webpushr-sw.js', {
      scope: '/mylive/'
    }).catch(function () {});
  }

  function startEmailAutomationTick() {
    function tick() {
      fetch('/mylive/email-tick.php', {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).catch(function () {});
    }

    window.setTimeout(tick, 10000);
    window.setInterval(tick, 60000);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initInstallButtons();
    registerMyLiveServiceWorker();
    startEmailAutomationTick();
  });
}());

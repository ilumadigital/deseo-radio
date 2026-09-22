(function () {
  'use strict';

  var deferredInstallPrompt = null;
  var installButtons = [];
  var notificationButtons = [];
  var notificationStatusNodes = [];

  function all(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function setNotificationStatus(message, state) {
    notificationStatusNodes.forEach(function (node) {
      node.textContent = message;
      node.dataset.state = state || '';
    });
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
    var message = 'Στο iPhone άνοιξε το Share menu του Safari και επίλεξε “Add to Home Screen”. Μετά άνοιξε το MyLive από το εικονίδιο της αρχικής οθόνης.';
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
    if (!('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }

    return navigator.serviceWorker.register('/mylive-sw.js', {
      scope: '/mylive/'
    }).catch(function () {
      return null;
    });
  }

  function loadWebpushr(publicKey, accountId, artistName) {
    if (!publicKey || !accountId || !('Notification' in window)) {
      if (!('Notification' in window)) {
        setNotificationStatus('Οι push notifications δεν υποστηρίζονται σε αυτό το browser.', 'unsupported');
      }
      return;
    }

    window.webpushr = window.webpushr || function () {
      (window.webpushr.q = window.webpushr.q || []).push(arguments);
    };

    function tagCurrentDj() {
      try {
        window.webpushr('attributes', {
          'mylive_account_id': String(accountId),
          'mylive_artist_name': String(artistName || '').slice(0, 100)
        });

        window.webpushr('fetch_id', function (sid) {
          if (sid) {
            setNotificationStatus('Push notifications ενεργές σε αυτή τη συσκευή.', 'enabled');
          }
        });
      } catch (error) {}
    }

    window._webpushrScriptReady = function () {
      tagCurrentDj();
    };

    if (!document.getElementById('webpushr-jssdk-mylive')) {
      var script = document.createElement('script');
      var firstScript = document.getElementsByTagName('script')[0];
      script.id = 'webpushr-jssdk-mylive';
      script.async = true;
      script.src = 'https://cdn.webpushr.com/app.min.js';

      if (firstScript && firstScript.parentNode) {
        firstScript.parentNode.insertBefore(script, firstScript);
      } else {
        document.head.appendChild(script);
      }
    }

    window.webpushr('setup', {
      key: publicKey,
      sw: 'none'
    });

    if (Notification.permission === 'granted') {
      setNotificationStatus('Push notifications ενεργές σε αυτή τη συσκευή.', 'enabled');
      tagCurrentDj();
    } else if (Notification.permission === 'denied') {
      setNotificationStatus('Οι ειδοποιήσεις είναι μπλοκαρισμένες από το browser. Άλλαξέ το από τα site settings.', 'blocked');
    } else {
      setNotificationStatus('Ενεργοποίησε τις ειδοποιήσεις για reminders και “On Air” alerts.', 'ready');
    }

    notificationButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        if (Notification.permission === 'denied') {
          setNotificationStatus('Οι ειδοποιήσεις είναι μπλοκαρισμένες. Άνοιξε τα permissions του deseoradio.com και επίλεξε Allow.', 'blocked');
          return;
        }

        if (Notification.permission === 'granted') {
          tagCurrentDj();
          return;
        }

        Notification.requestPermission().then(function (permission) {
          if (permission === 'granted') {
            setNotificationStatus('Οι ειδοποιήσεις ενεργοποιήθηκαν. Η συσκευή συνδέεται με το MyLive account σου…', 'enabled');
            window.webpushr('setup', {
              key: publicKey,
              sw: 'none'
            });
            window.setTimeout(tagCurrentDj, 900);
          } else if (permission === 'denied') {
            setNotificationStatus('Οι ειδοποιήσεις μπλοκαρίστηκαν από το browser.', 'blocked');
          } else {
            setNotificationStatus('Δεν ενεργοποιήθηκαν ακόμη οι ειδοποιήσεις.', 'ready');
          }
        });
      });
    });
  }

  function initPushUi() {
    notificationButtons = all('[data-mylive-notifications]');
    notificationStatusNodes = all('[data-mylive-notification-status]');

    var bridge = document.querySelector('[data-mylive-app-bridge]');
    if (!bridge) return;

    var publicKey = bridge.getAttribute('data-webpushr-key') || '';
    var accountId = bridge.getAttribute('data-account-id') || '';
    var artistName = bridge.getAttribute('data-artist-name') || '';

    if (!publicKey) {
      notificationButtons.forEach(function (button) {
        button.disabled = true;
        button.textContent = 'PUSH SETUP PENDING';
      });
      setNotificationStatus('Το PWA είναι ενεργό. Για push notifications χρειάζεται Webpushr key στο server.', 'pending');
      return;
    }

    registerMyLiveServiceWorker().then(function () {
      loadWebpushr(publicKey, accountId, artistName);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initInstallButtons();
    notificationStatusNodes = all('[data-mylive-notification-status]');

    registerMyLiveServiceWorker();
    initPushUi();
  });
}());

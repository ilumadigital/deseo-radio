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

  var webpushrSiteKey = 'BBFQF4QEleCIF57CoJnUQRFeh6iQaAxCWSQoVibx6rYFQpavi8Z_2flBoHsn6r6SXV4udR__JwYH7ahY4-m4Lek';
  var webpushrReadyPromise = null;

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function ensureWebpushrSdk() {
    if (window.__myliveWebpushrReady) return Promise.resolve();
    if (webpushrReadyPromise) return webpushrReadyPromise;

    webpushrReadyPromise = new Promise(function (resolve, reject) {
      var settled = false;
      var previousReady = window._webpushrScriptReady;

      window._webpushrScriptReady = function () {
        window.__myliveWebpushrReady = true;

        if (typeof previousReady === 'function') {
          try { previousReady(); } catch (e) {}
        }

        if (!settled) {
          settled = true;
          resolve();
        }
      };

      if (typeof window.webpushr === 'undefined') {
        window.webpushr = function () {
          (window.webpushr.q = window.webpushr.q || []).push(arguments);
        };
      }

      var existing = document.getElementById('webpushr-jssdk');
      if (!existing) {
        var script = document.createElement('script');
        script.id = 'webpushr-jssdk';
        script.async = true;
        script.src = 'https://cdn.webpushr.com/app.min.js';
        script.onerror = function () {
          if (!settled) {
            settled = true;
            webpushrReadyPromise = null;
            reject(new Error('Webpushr SDK could not be loaded.'));
          }
        };
        document.head.appendChild(script);
      }

      window.webpushr('setup', { key: webpushrSiteKey });

      window.setTimeout(function () {
        if (!settled && !window.__myliveWebpushrReady) {
          settled = true;
          webpushrReadyPromise = null;
          reject(new Error('Webpushr SDK did not become ready.'));
        }
      }, 12000);
    });

    return webpushrReadyPromise;
  }

  function fetchWebpushrSubscriberId(attempt) {
    attempt = attempt || 0;

    return new Promise(function (resolve, reject) {
      if (typeof window.webpushr !== 'function') {
        reject(new Error('Webpushr is not ready.'));
        return;
      }

      window.webpushr('fetch_id', function (sid) {
        if (typeof sid === 'string' && sid.trim() !== '') {
          resolve(sid.trim());
          return;
        }

        if (attempt >= 8) {
          reject(new Error('Push subscription is not ready yet.'));
          return;
        }

        window.setTimeout(function () {
          fetchWebpushrSubscriberId(attempt + 1).then(resolve).catch(reject);
        }, 700);
      });
    });
  }

  function createPushPanel() {
    var mount = document.querySelector('[data-mylive-push-mount]');
    var installCard = document.querySelector('.mylive-app-card');
    if ((!mount && !installCard) || document.querySelector('[data-mylive-push-panel]')) return null;

    var panel = document.createElement('div');
    panel.className = 'mylive-push-card';
    panel.setAttribute('data-mylive-push-panel', '');
    panel.innerHTML =
      '<div class="mylive-push-card-copy">' +
        '<span>MYLIVE · NOTIFICATIONS</span>' +
        '<strong>DJ Alerts στο κινητό και στον browser σου.</strong>' +
        '<p>Λάβε ειδοποίηση 3 ημέρες πριν αν λείπει το επόμενο DJ Set σου και μόλις το show σου βγει live στον Deseo Radio.</p>' +
      '</div>' +
      '<div class="mylive-push-card-actions">' +
        '<span class="mylive-push-status" data-mylive-push-status>CHECKING…</span>' +
        '<button type="button" class="mylive-push-button" data-mylive-push-enable disabled>ENABLE PUSH ALERTS</button>' +
        '<button type="button" class="mylive-push-disable" data-mylive-push-disable hidden>TURN OFF ALERTS</button>' +
      '</div>' +
      '<small class="mylive-push-help" data-mylive-push-help>Δεν εμφανίζεται κανένα Webpushr popup. Η άδεια ζητείται μόνο όταν πατήσεις Enable.</small>';

    if (mount) {
      mount.appendChild(panel);
    } else {
      installCard.insertAdjacentElement('afterend', panel);
    }
    return panel;
  }

  function initPushNotifications() {
    var panel = createPushPanel();
    if (!panel) return;

    var status = panel.querySelector('[data-mylive-push-status]');
    var enableButton = panel.querySelector('[data-mylive-push-enable]');
    var disableButton = panel.querySelector('[data-mylive-push-disable]');
    var help = panel.querySelector('[data-mylive-push-help]');
    var quickCard = document.querySelector('[data-mylive-push-quick]');
    var quickButton = quickCard ? quickCard.querySelector('[data-mylive-push-quick-enable]') : null;
    var state = {
      configured: false,
      enabled: false,
      subscriptions: 0,
      accountId: 0
    };

    function permissionState() {
      if (!('Notification' in window)) return 'unsupported';
      return Notification.permission || 'default';
    }

    function render() {
      var permission = permissionState();

      if (quickCard) {
        quickCard.hidden = !!state.enabled;
      }
      if (quickButton) {
        quickButton.disabled = false;
        quickButton.textContent = 'ENABLE PUSH ALERTS';
      }

      if (!state.configured) {
        status.textContent = 'API NOT CONFIGURED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'PUSH UNAVAILABLE';
        disableButton.hidden = true;
        help.textContent = 'Το Webpushr API δεν είναι διαθέσιμο στον server.';
        if (quickButton) {
          quickButton.disabled = true;
          quickButton.textContent = 'PUSH UNAVAILABLE';
        }
        return;
      }

      if (permission === 'unsupported') {
        status.textContent = 'NOT SUPPORTED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'NOT SUPPORTED';
        disableButton.hidden = !state.enabled;
        help.textContent = 'Ο συγκεκριμένος browser δεν υποστηρίζει web push notifications.';
        if (quickButton) {
          quickButton.disabled = true;
          quickButton.textContent = 'NOT SUPPORTED';
        }
        return;
      }

      if (permission === 'denied') {
        status.textContent = state.enabled ? 'ACCOUNT ON · DEVICE BLOCKED' : 'BLOCKED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'BLOCKED IN BROWSER';
        disableButton.hidden = !state.enabled;
        help.textContent = 'Οι ειδοποιήσεις έχουν αποκλειστεί από τον browser. Άλλαξε την άδεια του deseoradio.com σε Allow.';
        if (quickButton) {
          quickButton.disabled = true;
          quickButton.textContent = 'BLOCKED IN BROWSER';
        }
        return;
      }

      if (permission === 'granted') {
        status.textContent = state.enabled
          ? 'ACTIVE · ' + Math.max(1, state.subscriptions) + ' DEVICE' + (state.subscriptions === 1 ? '' : 'S')
          : 'BROWSER READY';
        status.dataset.state = state.enabled ? 'active' : 'ready';
        enableButton.disabled = state.enabled;
        enableButton.textContent = state.enabled ? 'PUSH ALERTS ACTIVE' : 'ENABLE PUSH ALERTS';
        disableButton.hidden = !state.enabled;
        help.textContent = state.enabled
          ? 'Θα λαμβάνεις τα προσωπικά reminders του MyLive ακόμη κι όταν η σελίδα δεν είναι ανοιχτή.'
          : 'Ο browser έχει ήδη άδεια. Πάτησε Enable για να συνδεθεί με το MyLive account σου.';
        return;
      }

      status.textContent = state.enabled ? 'ACCOUNT ON · ENABLE THIS DEVICE' : 'OFF';
      status.dataset.state = state.enabled ? 'ready' : 'off';
      enableButton.disabled = false;
      enableButton.textContent = state.enabled ? 'ENABLE THIS DEVICE' : 'ENABLE PUSH ALERTS';
      disableButton.hidden = !state.enabled;
      help.textContent = 'Η άδεια του browser θα εμφανιστεί μόνο αφού πατήσεις το κουμπί.';
    }

    function postPreference(enabled, sid) {
      var csrf = getCsrfToken();
      if (!csrf) return Promise.reject(new Error('Session token not found.'));

      var data = new FormData();
      data.append('csrf_token', csrf);
      data.append('enabled', enabled ? '1' : '0');
      data.append('subscriber_id', sid || '');

      return fetch('/mylive/push-settings.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: data
      }).then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.ok) {
            throw new Error((payload && payload.message) || 'Push settings update failed.');
          }
          return payload;
        });
      });
    }

    function syncSubscription() {
      enableButton.disabled = true;
      enableButton.textContent = 'CONNECTING…';
      if (quickButton) {
        quickButton.disabled = true;
        quickButton.textContent = 'CONNECTING…';
      }
      status.textContent = 'CONNECTING…';
      status.dataset.state = 'ready';

      return ensureWebpushrSdk()
        .then(function () {
          return fetchWebpushrSubscriberId(0);
        })
        .then(function (sid) {
          window.webpushr('attributes', {
            'mylive_user_id': String(state.accountId),
            'mylive_push_enabled': '1'
          });

          return postPreference(true, sid);
        })
        .then(function (payload) {
          state.enabled = true;
          state.subscriptions = Number(payload.subscriptions || 1);
          render();
        })
        .catch(function (error) {
          enableButton.disabled = false;
          status.textContent = 'TRY AGAIN';
          status.dataset.state = 'error';
          help.textContent = error && error.message
            ? error.message
            : 'Δεν ήταν δυνατή η ενεργοποίηση των push notifications.';
          render();
        });
    }

    if (quickButton) {
      quickButton.addEventListener('click', function () {
        if (!state.enabled) {
          enableButton.click();
        }
      });
    }

    enableButton.addEventListener('click', function () {
      var permission = permissionState();

      if (permission === 'unsupported' || permission === 'denied') {
        render();
        return;
      }

      if (permission === 'granted') {
        syncSubscription();
        return;
      }

      Notification.requestPermission().then(function (result) {
        if (result === 'granted') {
          syncSubscription();
        } else {
          render();
        }
      }).catch(function () {
        render();
      });
    });

    disableButton.addEventListener('click', function () {
      disableButton.disabled = true;

      postPreference(false, '')
        .then(function (payload) {
          state.enabled = false;
          state.subscriptions = Number(payload.subscriptions || 0);

          if (window.__myliveWebpushrReady && typeof window.webpushr === 'function') {
            window.webpushr('attributes', { 'mylive_push_enabled': '0' });
          }

          disableButton.disabled = false;
          render();
        })
        .catch(function (error) {
          disableButton.disabled = false;
          help.textContent = error && error.message
            ? error.message
            : 'Δεν ήταν δυνατή η απενεργοποίηση των push notifications.';
        });
    });

    fetch('/mylive/push-settings.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.ok) {
            throw new Error('Push settings are unavailable.');
          }
          return payload;
        });
      })
      .then(function (payload) {
        state.configured = !!payload.configured;
        state.enabled = !!payload.enabled;
        state.subscriptions = Number(payload.subscriptions || 0);
        state.accountId = Number(payload.account_id || 0);
        render();

        if (state.configured && state.enabled && permissionState() === 'granted') {
          syncSubscription();
        }
      })
      .catch(function () {
        state.configured = false;
        render();
      });
  }

  var assetSharePromises = typeof WeakMap !== 'undefined' ? new WeakMap() : null;

  function showAssetShareMessage(message, title) {
    if (window.DeseoDialog && typeof window.DeseoDialog.alert === 'function') {
      window.DeseoDialog.alert(message, {
        title: title || 'Share artwork',
        confirmLabel: 'OK'
      });
      return;
    }

    window.alert(message);
  }

  function fallbackAssetDownload(button) {
    var card = button.closest ? button.closest('.asset-card') : null;
    var link = card ? card.querySelector('.asset-download') : null;

    if (link) {
      link.click();
    }
  }

  function prepareAssetShareFile(button) {
    if (!button) return Promise.reject(new Error('Share button is unavailable.'));

    if (assetSharePromises && assetSharePromises.has(button)) {
      return assetSharePromises.get(button);
    }

    var url = button.getAttribute('data-share-url') || '';
    var filename = button.getAttribute('data-share-name') || 'deseo-radio-artwork.jpg';
    var mime = button.getAttribute('data-share-mime') || 'image/jpeg';

    var promise = fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'image/*'
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Artwork could not be loaded.');
        }
        return response.blob();
      })
      .then(function (blob) {
        var finalMime = blob.type || mime || 'image/jpeg';
        return new File([blob], filename, {
          type: finalMime,
          lastModified: Date.now()
        });
      })
      .catch(function (error) {
        if (assetSharePromises) assetSharePromises.delete(button);
        throw error;
      });

    if (assetSharePromises) {
      assetSharePromises.set(button, promise);
    }

    return promise;
  }

  function initAssetShareButtons() {
    var buttons = all('[data-mylive-share-asset]');
    if (!buttons.length) return;

    function warm(button) {
      prepareAssetShareFile(button).catch(function () {});
    }

    if ('IntersectionObserver' in window) {
      var preloadObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          warm(entry.target);
          preloadObserver.unobserve(entry.target);
        });
      }, {
        rootMargin: '600px 0px'
      });

      buttons.forEach(function (button) {
        preloadObserver.observe(button);
      });
    }

    buttons.forEach(function (button) {
      ['pointerenter', 'touchstart', 'focus'].forEach(function (eventName) {
        button.addEventListener(eventName, function () {
          warm(button);
        }, { passive: true });
      });

      button.addEventListener('click', function () {
        if (button.disabled) return;

        var defaultLabel = String(button.textContent || 'Share it').replace(/\s+/g, ' ').trim();
        var title = button.getAttribute('data-share-title') || 'Deseo Radio artwork';

        button.disabled = true;
        button.classList.add('is-sharing');
        button.textContent = 'Preparing…';

        prepareAssetShareFile(button)
          .then(function (file) {
            if (typeof navigator.share !== 'function') {
              fallbackAssetDownload(button);
              showAssetShareMessage(
                'Η συσκευή ή ο browser σου δεν υποστηρίζει native file sharing. Το artwork κατεβαίνει ώστε να μπορείς να το ανεβάσεις χειροκίνητα.',
                'Share unavailable'
              );
              return null;
            }

            var shareData = {
              files: [file],
              title: title,
              text: 'Deseo Radio · MyLive'
            };

            if (typeof navigator.canShare === 'function' && !navigator.canShare({ files: [file] })) {
              fallbackAssetDownload(button);
              showAssetShareMessage(
                'Η συσκευή σου ανοίγει share menu, αλλά ο συγκεκριμένος browser δεν επιτρέπει sharing εικόνων ως αρχείο. Το artwork κατεβαίνει για χειροκίνητο upload.',
                'File sharing unavailable'
              );
              return null;
            }

            button.textContent = 'Opening share…';
            return navigator.share(shareData);
          })
          .catch(function (error) {
            if (error && error.name === 'AbortError') {
              return;
            }

            if (error && error.name === 'NotAllowedError') {
              showAssetShareMessage(
                'Το artwork είναι έτοιμο. Πάτησε ξανά “Share it” για να ανοίξει το share menu της συσκευής.',
                'Ready to share'
              );
              return;
            }

            fallbackAssetDownload(button);
            showAssetShareMessage(
              'Δεν μπόρεσε να ανοίξει το native share menu. Το artwork κατεβαίνει ώστε να μπορείς να το κοινοποιήσεις χειροκίνητα.',
              'Share unavailable'
            );
          })
          .finally(function () {
            button.disabled = false;
            button.classList.remove('is-sharing');
            button.textContent = defaultLabel;
          });
      });
    });
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
      fetch('/mylive/notification-tick.php', {
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
    initAssetShareButtons();
    registerMyLiveServiceWorker();
    initPushNotifications();
    startEmailAutomationTick();
  });
}());


(function initNextShowCountdown(){
    const countdown = document.querySelector('[data-mylive-next-countdown]');
    if (!countdown) return;

    const startRaw = countdown.getAttribute('data-start') || '';
    const endRaw = countdown.getAttribute('data-end') || '';
    const start = Date.parse(startRaw);
    const end = Date.parse(endRaw);

    if (!Number.isFinite(start)) return;

    let reloadScheduled = false;

    function updateCountdown(){
        const now = Date.now();

        if (Number.isFinite(end) && now >= start && now < end) {
            countdown.textContent = 'LIVE NOW';
            countdown.classList.add('is-live');
            return;
        }

        if (Number.isFinite(end) && now >= end) {
            countdown.textContent = 'Updating next show…';
            countdown.classList.remove('is-live');

            if (!reloadScheduled) {
                reloadScheduled = true;
                window.setTimeout(() => window.location.reload(), 2500);
            }
            return;
        }

        const diff = Math.max(0, start - now);
        const totalMinutes = Math.floor(diff / 60000);
        const days = Math.floor(totalMinutes / 1440);
        const hours = Math.floor((totalMinutes % 1440) / 60);
        const minutes = totalMinutes % 60;

        countdown.classList.remove('is-live');
        countdown.textContent =
            'ON AIR IN · ' +
            String(days).padStart(2, '0') + 'D · ' +
            String(hours).padStart(2, '0') + 'H · ' +
            String(minutes).padStart(2, '0') + 'M';
    }

    updateCountdown();
    window.setInterval(updateCountdown, 30000);
}());


/* MYLIVE_I18N_V1 */
(function () {
  'use strict';

  var STORAGE_KEY = 'mylive_language';
  var supported = ['el', 'en'];
  var currentLanguage = 'el';
  var applying = false;

  var pairs = [
    ['SEASON 6 · DJ ACCESS', 'SEASON 6 · DJ ACCESS'],
    ['Your sets.', 'Τα sets σου.'],
    ['Your space.', 'Ο χώρος σου.'],
    ['DJ Set delivery, personal artwork και branded imaging. Όλα σε ένα απλό, ιδιωτικό workspace.', 'Παράδοση DJ Set, προσωπικό artwork και branded υλικό. Όλα σε ένα απλό, ιδιωτικό workspace.'],
    ['MYLIVE · SECURITY', 'MYLIVE · ΑΣΦΑΛΕΙΑ'],
    ['Password changed.', 'Ο κωδικός άλλαξε.'],
    ['Ο νέος κωδικός σου αποθηκεύτηκε. Μπορείς τώρα να μπεις κανονικά στο MyLive.', 'Your new password has been saved. You can now sign in to MyLive.'],
    ['BACK TO LOGIN', 'ΠΙΣΩ ΣΤΟ LOGIN'],
    ['MYLIVE · PASSWORD RESET', 'MYLIVE · ΕΠΑΝΑΦΟΡΑ ΚΩΔΙΚΟΥ'],
    ['Νέος κωδικός.', 'New password.'],
    ['Δημιούργησε νέο password για το MyLive account σου. Το reset link χρησιμοποιείται μόνο μία φορά.', 'Create a new password for your MyLive account. The reset link can only be used once.'],
    ['Email', 'Email'],
    ['New password', 'Νέος κωδικός'],
    ['Confirm password', 'Επιβεβαίωση κωδικού'],
    ['Show', 'Εμφάνιση'],
    ['Hide', 'Απόκρυψη'],
    ['Τουλάχιστον 8 χαρακτήρες · μόνο αγγλικοί χαρακτήρες, αριθμοί και σύμβολα. Δεν επιτρέπονται ελληνικά.', 'At least 8 characters · English characters, numbers and symbols only. Greek characters are not allowed.'],
    ['CHANGE PASSWORD', 'ΑΛΛΑΓΗ ΚΩΔΙΚΟΥ'],
    ['Το link έληξε.', 'The link has expired.'],
    ['Ο σύνδεσμος αλλαγής κωδικού δεν είναι πλέον έγκυρος ή έχει ήδη χρησιμοποιηθεί. Ζήτησε νέο link για να συνεχίσεις.', 'This password reset link is no longer valid or has already been used. Request a new link to continue.'],
    ['RESET LINK EXPIRED', 'ΤΟ RESET LINK ΕΛΗΞΕ'],
    ['REQUEST NEW LINK', 'ΝΕΟ RESET LINK'],
    ['Back to login', 'Πίσω στο login'],
    ['Reset password.', 'Επαναφορά κωδικού.'],
    ['Reset password', 'Επαναφορά κωδικού'],
    ['Γράψε το email του MyLive account σου και θα σου στείλουμε ασφαλές link για να ορίσεις νέο κωδικό.', 'Enter the email address of your MyLive account and we will send you a secure link to set a new password.'],
    ['Το Cloudflare security δεν είναι ακόμη ρυθμισμένο για το MyLive.', 'Cloudflare security is not configured for MyLive yet.'],
    ['SEND RESET LINK', 'ΑΠΟΣΤΟΛΗ RESET LINK'],
    ['Καλώς ήρθες.', 'Welcome.'],
    ['Μπες με τα στοιχεία πρόσβασης που έλαβες από το Deseo Radio.', 'Sign in with the access details you received from Deseo Radio.'],
    ['Password', 'Κωδικός'],
    ['Enter MyLive', 'Είσοδος στο MyLive'],
    ['Private DJ workspace · Deseo Radio / ILUMA Digital Agency', 'Ιδιωτικό DJ workspace · Deseo Radio / ILUMA Digital Agency'],
    ['Create your password · MyLive · Deseo Radio', 'Δημιούργησε τον κωδικό σου · MyLive · Deseo Radio'],
    ['FIRST ACCESS ·', 'ΠΡΩΤΗ ΠΡΟΣΒΑΣΗ ·'],
    ['Κάν’ το δικό σου.', 'Make it yours.'],
    ['Το password που έλαβες ήταν προσωρινό. Δημιούργησε τώρα τον προσωπικό σου κωδικό για το MyLive.', 'The password you received was temporary. Create your personal MyLive password now.'],
    ['Τουλάχιστον 8 χαρακτήρες · μόνο αγγλικοί χαρακτήρες, αριθμοί και σύμβολα. Δεν επιτρέπονται ελληνικά. Αποθήκευσε το email και τον νέο κωδικό στον browser / password manager σου.', 'At least 8 characters · English characters, numbers and symbols only. Greek characters are not allowed. Save your email and new password in your browser or password manager.'],
    ['Save & open MyLive', 'Αποθήκευση & είσοδος'],
    ['Logout', 'Αποσύνδεση'],
    ['Overview', 'Επισκόπηση'],
    ['My Assets', 'Τα Assets μου'],
    ['My DJ Sets', 'Τα DJ Sets μου'],
    ['Listen Live', 'Άκου Live'],
    ['My Profile', 'Το Προφίλ μου'],
    ['My Rewards', 'Τα Rewards μου'],
    ['MySettings', 'Ρυθμίσεις'],
    ['DESEO RADIO · MYLIVE', 'DESEO RADIO · MYLIVE'],
    ['Welcome,', 'Καλώς ήρθες,'],
    ['MYLIVE APP · PWA', 'MYLIVE APP · PWA'],
    ['Το MyLive στο κινητό σου.', 'MyLive on your phone.'],
    ['Εγκατάστησέ το σαν app για γρήγορη πρόσβαση στα DJ Sets, τα assets, το πρόγραμμα και το προσωπικό σου dashboard.', 'Install it as an app for quick access to your DJ Sets, assets, schedule and personal dashboard.'],
    ['INSTALL MYLIVE APP', 'ΕΓΚΑΤΑΣΤΑΣΗ MYLIVE APP'],
    ['APP INSTALLED', 'ΤΟ APP ΕΓΚΑΤΑΣΤΑΘΗΚΕ'],
    ['INSTALL ON IPHONE', 'ΕΓΚΑΤΑΣΤΑΣΗ ΣΕ IPHONE'],
    ['PUSH NOTIFICATIONS', 'PUSH ΕΙΔΟΠΟΙΗΣΕΙΣ'],
    ['Μείνε ενημερωμένος για το show σου.', 'Stay updated about your show.'],
    ['ENABLE PUSH ALERTS', 'ΕΝΕΡΓΟΠΟΙΗΣΗ PUSH ALERTS'],
    ['Countdown to broadcast', 'Αντίστροφη μέτρηση για τη μετάδοση'],
    ['SEASON', 'ΣΕΖΟΝ'],
    ['NEXT EPISODE', 'ΕΠΟΜΕΝΟ EPISODE'],
    ['Uploaded', 'Ανέβηκε'],
    ['Checked', 'Ελέγχθηκε'],
    ['Scheduled', 'Προγραμματίστηκε'],
    ['Season 6 complete.', 'Η Season 6 ολοκληρώθηκε.'],
    ['Your set is next.', 'Το set σου είναι το επόμενο.'],
    ['UPLOAD DJ SET', 'ΑΝΕΒΑΣΕ DJ SET'],
    ['Action required.', 'Απαιτείται ενέργεια.'],
    ['VIEW DJ SET', 'ΠΡΟΒΟΛΗ DJ SET'],
    ['Ready for broadcast.', 'Έτοιμο για μετάδοση.'],
    ['VIEW EP', 'ΠΡΟΒΟΛΗ EP'],
    ['Delivery in progress.', 'Η παράδοση είναι σε εξέλιξη.'],
    ['EPISODES', 'EPISODES'],
    ['uploaded στο MyLive', 'ανέβηκαν στο MyLive'],
    ['YOUR ASSETS', 'ΤΑ ASSETS ΣΟΥ'],
    ['διαθέσιμα για download', 'available for download'],
    ['ΣΕ ΑΚΟΥΣΑΝ', 'LISTENERS REACHED'],
    ['Not Available', 'Μη διαθέσιμο'],
    ['FROM DESEO RADIO · ILUMA Digital Agency', 'ΑΠΟ DESEO RADIO · ILUMA Digital Agency'],
    ['Your Assets', 'Τα Assets σου'],
    ['Το επίσημο artwork, το personal imaging και ό,τι δημιουργούμε για το show σου.', 'Your official artwork, personal imaging and everything we create for your show.'],
    ['COMING HERE', 'ΕΡΧΟΝΤΑΙ ΕΔΩ'],
    ['Τα προσωπικά σου assets θα εμφανιστούν εδώ.', 'Your personal assets will appear here.'],
    ['Download', 'Λήψη'],
    ['Download', 'Λήψη'],
    ['Share it', 'Κοινοποίηση'],
    ['DJ SET DELIVERY', 'ΠΑΡΑΔΟΣΗ DJ SET'],
    ['Your DJ Sets', 'Τα DJ Sets σου'],
    ['Upload το επόμενο episode. Το filename και το EP number δημιουργούνται αυτόματα.', 'Upload your next episode. The filename and EP number are generated automatically.'],
    ['NEXT DELIVERY', 'ΕΠΟΜΕΝΗ ΠΑΡΑΔΟΣΗ'],
    ['Broadcast slot', 'Slot μετάδοσης'],
    ['DJ / Artist playing this slot', 'DJ / Artist που παίζει σε αυτό το slot'],
    ['Υποχρεωτικό για αυτό το radioshow πριν από κάθε upload.', 'Required for this radioshow before every upload.'],
    ['MP3 · 192 kbps · Stereo · έως 1 GB', 'MP3 · 192 kbps · Stereo · up to 1 GB'],
    ['Upload DJ Set', 'Ανέβασε DJ Set'],
    ['Πάτησε εδώ ή σύρε το αρχείο σου', 'Click here or drag your file'],
    ['Upload EP', 'Ανέβασμα EP'],
    ['YOUR REPOSITORY', 'ΤΟ ΑΡΧΕΙΟ ΣΟΥ'],
    ['Episodes', 'Episodes'],
    ['Μετά τη μετάδοση, μόλις δημοσιευτεί το DJ Set σου στο HearThis, το προσωπικό του link θα εμφανιστεί εδώ.', 'After broadcast, your personal DJ Set link will appear here as soon as it is published on HearThis.'],
    ['Το link του DJ Set σου', 'Your DJ Set link'],
    ['Το link σου ετοιμάζεται.', 'Your link is on its way.'],
    ['Το DJ Set σου παραμένει στο ιστορικό.', 'Your DJ Set stays in your history.'],
    ['Οι πλατφόρμες του DJ Set σου', 'Your DJ Set platforms'],
    ['ΜΕΤΑ ΤΗ ΜΕΤΑΔΟΣΗ', 'AFTER THE BROADCAST'],
    ['Το DJ Set σου, παντού.', 'Your DJ Set, everywhere.'],
    ['Μετά τη μετάδοση στον Deseo Radio, το set σου θα δημοσιεύεται σταδιακά και στις τρεις πλατφόρμες.', 'After airing on Deseo Radio, your set will gradually be published on all three platforms.'],
    ['Δεν έχεις ανεβάσει ακόμη κάποιο set.', 'You have not uploaded a set yet.'],
    ['Το πρώτο σου upload θα εμφανιστεί εδώ ως EP001.', 'Your first upload will appear here as EP001.'],
    ['FILE REMOVED · episode retained', 'ΤΟ ΑΡΧΕΙΟ ΑΦΑΙΡΕΘΗΚΕ · το episode διατηρήθηκε'],
    ['DESEO RADIO · LIVE', 'DESEO RADIO · LIVE'],
    ['Listen to the station.', 'Άκου τον σταθμό.'],
    ['Άκου live τον Deseo Radio και δες ποιος βρίσκεται αυτή τη στιγμή στον αέρα.', 'Listen to Deseo Radio live and see who is currently on air.'],
    ['LIVE 24/7', 'LIVE 24/7'],
    ['NOW PLAYING', 'ΠΑΙΖΕΙ ΤΩΡΑ'],
    ['NOW ON AIR', 'ΤΩΡΑ ΣΤΟΝ ΑΕΡΑ'],
    ['LIVE BROADCAST', 'LIVE ΜΕΤΑΔΟΣΗ'],
    ['Loading…', 'Φόρτωση…'],
    ['YOUR WEEKLY SLOT', 'ΤΟ ΕΒΔΟΜΑΔΙΑΙΟ SLOT ΣΟΥ'],
    ['YOUR WEEKLY SLOTS', 'ΤΑ ΕΒΔΟΜΑΔΙΑΙΑ SLOTS ΣΟΥ'],
    ['GUEST DJ ACCESS', 'GUEST DJ ΠΡΟΣΒΑΣΗ'],
    ['Είσαι LIVE Τώρα!', 'You are LIVE Now!'],
    ['YOUR PUBLIC DJ PROFILE', 'ΤΟ ΔΗΜΟΣΙΟ DJ ΠΡΟΦΙΛ ΣΟΥ'],
    ['What listeners see.', 'Τι βλέπουν οι ακροατές.'],
    ['Hidden from listeners', 'Κρυφό από τους ακροατές'],
    ['Not public yet', 'Δεν είναι δημόσιο ακόμη'],
    ['PUBLIC BIO · ENGLISH RECOMMENDED', 'ΔΗΜΟΣΙΟ BIO · ΠΡΟΤΕΙΝΟΝΤΑΙ ΑΓΓΛΙΚΑ'],
    ['You have unpublished changes.', 'Έχεις μη δημοσιευμένες αλλαγές.'],
    ['Το site συνεχίζει να δείχνει την προηγούμενη published έκδοση μέχρι να πατήσεις Publish Profile.', 'The site keeps showing the previous published version until you click Publish Profile.'],
    ['BIO', 'BIO'],
    ['Έως 1.600 χαρακτήρες · το bio της αίτησής σου διατηρείται και εμφανίζεται εδώ. Μπορείς προαιρετικά να το βελτιώσεις ή να το μετατρέψεις σε επαγγελματικό αγγλικό bio με το ChatGPT.', 'Up to 1,600 characters · the bio from your application is preserved and shown here. You can optionally improve it or turn it into a professional English bio with ChatGPT.'],
    ['No photo upload here.', 'Δεν γίνεται upload φωτογραφίας εδώ.'],
    ['Το public modal χρησιμοποιεί πάντα τη φωτογραφία που έχει ορίσει το Deseo Radio στο πρόγραμμα.', 'The public modal always uses the photo assigned by Deseo Radio in the schedule.'],
    ['Save Draft', 'Αποθήκευση Draft'],
    ['Publish Profile', 'Δημοσίευση Προφίλ'],
    ['Unpublish', 'Απόκρυψη'],
    ['MY REWARDS · LIVE STATUS', 'ΤΑ REWARDS ΜΟΥ · LIVE STATUS'],
    ['Your referrals.', 'Οι συστάσεις σου.'],
    ['Παρακολούθησε τις επιχειρήσεις που έχεις συστήσει, την πορεία κάθε συνεργασίας και τα Rewards σου.', 'Track the businesses you have referred, the progress of each partnership and your Rewards.'],
    ['REFERRAL', 'ΣΥΣΤΑΣΗ'],
    ['REFERRALS', 'ΣΥΣΤΑΣΕΙΣ'],
    ['συνολικά', 'total'],
    ['CONFIRMED', 'ΕΠΙΒΕΒΑΙΩΜΕΝΑ'],
    ['campaigns', 'campaigns'],
    ['PENDING', 'ΣΕ ΑΝΑΜΟΝΗ'],
    ['reward to be paid', 'reward προς πληρωμή'],
    ['TOTAL PAID', 'ΣΥΝΟΛΟ ΠΛΗΡΩΜΩΝ'],
    ['completed rewards', 'ολοκληρωμένα rewards'],
    ['NO REFERRALS YET', 'ΔΕΝ ΥΠΑΡΧΟΥΝ ΣΥΣΤΑΣΕΙΣ ΑΚΟΜΗ'],
    ['Το πρώτο σου Reward ξεκινά από μια σύσταση.', 'Your first Reward starts with a referral.'],
    ['Χρησιμοποίησε το προσωπικό σου link παρακάτω. Μόλις η ILUMA καταχωρήσει το referral, θα εμφανιστεί εδώ με live status.', 'Use your personal link below. Once ILUMA registers the referral, it will appear here with live status.'],
    ['YOUR REWARD', 'ΤΟ REWARD ΣΟΥ'],
    ['CAMPAIGN VALUE', 'ΑΞΙΑ ΚΑΜΠΑΝΙΑΣ'],
    ['YOUR SHARE', 'ΤΟ ΜΕΡΙΔΙΟ ΣΟΥ'],
    ['REWARD', 'REWARD'],
    ['STATUS', 'ΚΑΤΑΣΤΑΣΗ'],
    ['REFERRAL CONTACT', 'ΕΠΑΦΗ ΣΥΣΤΑΣΗΣ'],
    ['UPDATE FROM ILUMA', 'ΕΝΗΜΕΡΩΣΗ ΑΠΟ ILUMA'],
    ['PAID ·', 'ΠΛΗΡΩΘΗΚΕ ·'],
    ['DJ PARTNER REWARD', 'DJ PARTNER REWARD'],
    ['Φέρε το brand.', 'Bring the brand.'],
    ['Κράτα το 15%.', 'Keep 15%.'],
    ['Ξέρεις μια επιχείρηση που θέλει να ακουστεί στο Deseo Radio; Σύστησέ τη στην ILUMA και κέρδισε', 'Know a business that wants to be heard on Deseo Radio? Refer it to ILUMA and earn'],
    ['από κάθε νέα διαφημιστική καμπάνια που κλείνει μέσω της δικής σου σύστασης.', 'from every new advertising campaign closed through your referral.'],
    ['ΠΡΟΤΕΙΝΕ ΜΙΑ ΕΠΙΧΕΙΡΗΣΗ', 'REFER A BUSINESS'],
    ['COPY LINK', 'ΑΝΤΙΓΡΑΦΗ LINK'],
    ['COPIED ✓', 'ΑΝΤΙΓΡΑΦΗΚΕ ✓'],
    ['MYSETTINGS · COMMUNICATIONS', 'ΡΥΘΜΙΣΕΙΣ · ΕΠΙΚΟΙΝΩΝΙΕΣ'],
    ['Choose how Deseo reaches you.', 'Επίλεξε πώς θα επικοινωνεί μαζί σου το Deseo.'],
    ['Ρύθμισε ποια operational reminders και ανακοινώσεις θέλεις να λαμβάνεις μέσω Email και Push Notifications.', 'Choose which operational reminders and announcements you want to receive via Email and Push Notifications.'],
    ['PERSONAL PREFERENCES', 'ΠΡΟΣΩΠΙΚΕΣ ΡΥΘΜΙΣΕΙΣ'],
    ['EMAIL CHANNEL', 'ΚΑΝΑΛΙ EMAIL'],
    ['Email notifications', 'Ειδοποιήσεις email'],
    ['NOTIFICATION TYPE', 'ΤΥΠΟΣ ΕΙΔΟΠΟΙΗΣΗΣ'],
    ['EMAIL', 'EMAIL'],
    ['PUSH', 'PUSH'],
    ['Guest DJ notifications', 'Ειδοποιήσεις Guest DJ'],
    ['Το Guest access είναι one-off. Δεν δημιουργείται αυτόματα νέο show ή reminder κάθε εβδομάδα.', 'Guest access is one-off. A new show or reminder is not created automatically every week.'],
    ['DJ Set Reminder', 'Υπενθύμιση DJ Set'],
    ['3 ημέρες πριν, μόνο όταν λείπει το επόμενο DJ Set.', '3 days before, only when your next DJ Set is missing.'],
    ['On Air Now', 'Τώρα στον Αέρα'],
    ['Τη στιγμή που το weekly slot σου γίνεται live.', 'At the moment your weekly slot goes live.'],
    ['Deseo Announcements', 'Ανακοινώσεις Deseo'],
    ['Γενικές ενημερώσεις της ομάδας του Deseo Radio προς τους DJs.', 'General updates from the Deseo Radio team to DJs.'],
    ['Τα Push χρειάζονται μία ενεργή συσκευή. Η άδεια του browser εμφανίζεται μόνο όταν πατήσεις Enable Push Alerts.', 'Push notifications require one active device. The browser permission appears only when you click Enable Push Alerts.'],
    ['SAVE MY SETTINGS', 'ΑΠΟΘΗΚΕΥΣΗ ΡΥΘΜΙΣΕΩΝ'],
    ['Powered by ILUMA Digital Agency', 'Powered by ILUMA Digital Agency'],
    ['MYLIVE · NOTIFICATIONS', 'MYLIVE · ΕΙΔΟΠΟΙΗΣΕΙΣ'],
    ['DJ Alerts στο κινητό και στον browser σου.', 'DJ Alerts on your phone and browser.'],
    ['Λάβε ειδοποίηση 3 ημέρες πριν αν λείπει το επόμενο DJ Set σου και μόλις το show σου βγει live στον Deseo Radio.', 'Get an alert 3 days before if your next DJ Set is missing and when your show goes live on Deseo Radio.'],
    ['CHECKING…', 'ΕΛΕΓΧΟΣ…'],
    ['TURN OFF ALERTS', 'ΑΠΕΝΕΡΓΟΠΟΙΗΣΗ ALERTS'],
    ['Δεν εμφανίζεται κανένα Webpushr popup. Η άδεια ζητείται μόνο όταν πατήσεις Enable.', 'No Webpushr popup is shown automatically. Permission is requested only after you click Enable.'],
    ['API NOT CONFIGURED', 'ΤΟ API ΔΕΝ ΕΧΕΙ ΡΥΘΜΙΣΤΕΙ'],
    ['PUSH UNAVAILABLE', 'PUSH ΜΗ ΔΙΑΘΕΣΙΜΟ'],
    ['Το Webpushr API δεν είναι διαθέσιμο στον server.', 'The Webpushr API is not available on the server.'],
    ['NOT SUPPORTED', 'ΔΕΝ ΥΠΟΣΤΗΡΙΖΕΤΑΙ'],
    ['Ο συγκεκριμένος browser δεν υποστηρίζει web push notifications.', 'This browser does not support web push notifications.'],
    ['ACCOUNT ON · DEVICE BLOCKED', 'ACCOUNT ON · Η ΣΥΣΚΕΥΗ ΜΠΛΟΚΑΡΕΙ'],
    ['BLOCKED', 'ΜΠΛΟΚΑΡΙΣΜΕΝΟ'],
    ['BLOCKED IN BROWSER', 'ΜΠΛΟΚΑΡΙΣΜΕΝΟ ΣΤΟΝ BROWSER'],
    ['Οι ειδοποιήσεις έχουν αποκλειστεί από τον browser. Άλλαξε την άδεια του deseoradio.com σε Allow.', 'Notifications are blocked by the browser. Change the deseoradio.com permission to Allow.'],
    ['BROWSER READY', 'Ο BROWSER ΕΙΝΑΙ ΕΤΟΙΜΟΣ'],
    ['PUSH ALERTS ACTIVE', 'ΤΑ PUSH ALERTS ΕΙΝΑΙ ΕΝΕΡΓΑ'],
    ['Θα λαμβάνεις τα προσωπικά reminders του MyLive ακόμη κι όταν η σελίδα δεν είναι ανοιχτή.', 'You will receive your personal MyLive reminders even when the page is not open.'],
    ['Ο browser έχει ήδη άδεια. Πάτησε Enable για να συνδεθεί με το MyLive account σου.', 'The browser already has permission. Click Enable to connect it to your MyLive account.'],
    ['ACCOUNT ON · ENABLE THIS DEVICE', 'ACCOUNT ON · ΕΝΕΡΓΟΠΟΙΗΣΕ ΑΥΤΗ ΤΗ ΣΥΣΚΕΥΗ'],
    ['ENABLE THIS DEVICE', 'ΕΝΕΡΓΟΠΟΙΗΣΗ ΣΥΣΚΕΥΗΣ'],
    ['Η άδεια του browser θα εμφανιστεί μόνο αφού πατήσεις το κουμπί.', 'The browser permission will appear only after you click the button.'],
    ['CONNECTING…', 'ΣΥΝΔΕΣΗ…'],
    ['TRY AGAIN', 'ΔΟΚΙΜΑΣΕ ΞΑΝΑ'],
    ['Preparing…', 'Προετοιμασία…'],
    ['Opening share…', 'Άνοιγμα κοινοποίησης…'],
    ['Share artwork', 'Κοινοποίηση artwork'],
    ['Share unavailable', 'Η κοινοποίηση δεν είναι διαθέσιμη'],
    ['File sharing unavailable', 'Η κοινοποίηση αρχείου δεν είναι διαθέσιμη'],
    ['Ready to share', 'Έτοιμο για κοινοποίηση'],
    ['LIVE NOW', 'LIVE ΤΩΡΑ'],
    ['Updating next show…', 'Ενημέρωση επόμενου show…'],
    ['ON AIR NOW', 'ΤΩΡΑ ΣΤΟΝ ΑΕΡΑ'],
    ['NON-STOP MIX', 'NON-STOP MIX'],
    ['Live status temporarily unavailable', 'Το live status δεν είναι προσωρινά διαθέσιμο'],
    ['Η συνεδρία έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.', 'Your session expired. Refresh the page and try again.'],
    ['Η ασφαλής ανάκτηση κωδικού δεν είναι διαθέσιμη αυτή τη στιγμή.', 'Secure password recovery is not available right now.'],
    ['Το Cloudflare security check απέτυχε. Δοκίμασε ξανά.', 'The Cloudflare security check failed. Try again.'],
    ['Συμπλήρωσε ένα έγκυρο email.', 'Enter a valid email address.'],
    ['Δεν υπάρχει MyLive account με αυτό το email.', 'There is no MyLive account with this email address.'],
    ['Περίμενε λίγα δευτερόλεπτα πριν ζητήσεις νέο reset link.', 'Wait a few seconds before requesting a new reset link.'],
    ['Έχει ήδη σταλεί πρόσφατα reset link σε αυτό το email. Έλεγξε Inbox και Spam / Junk.', 'A reset link was recently sent to this email. Check your Inbox and Spam / Junk folders.'],
    ['Δεν ήταν δυνατή η αποστολή του reset email αυτή τη στιγμή. Δοκίμασε ξανά σε λίγο.', 'The reset email could not be sent right now. Try again shortly.'],
    ['Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.', 'The new password must be at least 8 characters long.'],
    ['Ο κωδικός πρέπει να χρησιμοποιεί μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα. Δεν επιτρέπονται ελληνικοί χαρακτήρες.', 'The password must use English characters, numbers and symbols only. Greek characters are not allowed.'],
    ['Οι δύο κωδικοί δεν είναι ίδιοι.', 'The two passwords do not match.'],
    ['Ο σύνδεσμος αλλαγής κωδικού δεν είναι πλέον έγκυρος. Ζήτησε νέο reset link.', 'The password reset link is no longer valid. Request a new reset link.'],
    ['Η ασφαλής είσοδος δεν είναι διαθέσιμη αυτή τη στιγμή.', 'Secure sign-in is not available right now.'],
    ['Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε λίγα λεπτά.', 'Too many failed attempts. Try again in a few minutes.'],
    ['Συμπλήρωσε σωστά email και password.', 'Enter a valid email and password.'],
    ['Το email ή το password δεν είναι σωστό.', 'The email or password is incorrect.'],
    ['Η συνεδρία σου έχει λήξει.', 'Your session has expired.'],
    ['Το account δεν είναι ενεργό.', 'Your account is not active.'],
    ['Ο προσωπικός σου κωδικός αποθηκεύτηκε.', 'Your personal password has been saved.'],
    ['Η συνεδρία σου έχει λήξει. Κάνε ξανά login.', 'Your session has expired. Sign in again.'],
    ['Οι ρυθμίσεις επικοινωνίας αποθηκεύτηκαν.', 'Your communication settings have been saved.'],
    ['Το Public Profile δεν είναι ενεργό για το account σου.', 'Public Profile is not enabled for your account.'],
    ['Δημιούργησε πρώτα το προσωπικό σου password.', 'Create your personal password first.'],
    ['Το Public Profile έγινε Unpublished. Το show σου παραμένει συνδεδεμένο κανονικά με το Radio Program και μπορείς να το δημοσιεύσεις ξανά οποιαδήποτε στιγμή.', 'Your Public Profile is now unpublished. Your show remains connected to the Radio Program and you can publish it again at any time.'],
    ['Το Public Profile δημοσιεύτηκε. Η σύνδεση με το Radio Program παραμένει κανονικά ενεργή.', 'Your Public Profile has been published. Its Radio Program connection remains active.'],
    ['Το draft του Public Profile αποθηκεύτηκε. Οι αλλαγές δεν είναι ακόμη δημόσιες.', 'Your Public Profile draft has been saved. The changes are not public yet.'],
    ['Το account δεν είναι πλέον ενεργό.', 'This account is no longer active.'],
    ['Επίλεξε το DJ set που θέλεις να ανεβάσεις.', 'Select the DJ set you want to upload.'],
    ['Το αρχείο ξεπερνά το όριο upload του server.', 'The file exceeds the server upload limit.'],
    ['Το αρχείο είναι πολύ μεγάλο.', 'The file is too large.'],
    ['Το upload διακόπηκε πριν ολοκληρωθεί.', 'The upload was interrupted before it completed.'],
    ['Δεν επιλέχθηκε αρχείο.', 'No file was selected.'],
    ['Το upload δεν ολοκληρώθηκε.', 'The upload did not complete.'],
    ['Το αρχείο πρέπει να είναι μικρότερο από 1 GB.', 'The file must be smaller than 1 GB.'],
    ['Το DJ Set πρέπει να είναι MP3 · 192 kbps · Stereo.', 'The DJ Set must be MP3 · 192 kbps · Stereo.'],
    ['Το αρχείο δεν αναγνωρίστηκε ως έγκυρο upload.', 'The file was not recognized as a valid upload.'],
    ['Το account δεν βρέθηκε.', 'The account was not found.'],
    ['Δεν ήταν δυνατή η δημιουργία του προσωπικού φακέλου upload.', 'Your personal upload folder could not be created.'],
    ['Δεν ήταν δυνατή η αποθήκευση του DJ set.', 'The DJ set could not be saved.'],
    ['Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'Something went wrong. Try again.'],
    ['Το reset link στάλθηκε. Έλεγξε και τον φάκελο Spam / Junk.', 'The reset link was sent. Check your Spam / Junk folder too.'],
    ['Το set παραλήφθηκε από το Deseo και περιμένει έλεγχο.', 'The set was received by Deseo and is waiting for review.'],
    ['Το set έχει ελεγχθεί και περιμένει να προγραμματιστεί.', 'The set has been reviewed and is waiting to be scheduled.'],
    ['Όλα έτοιμα. Το επόμενο episode είναι προγραμματισμένο για broadcast.', 'Everything is ready. The next episode is scheduled for broadcast.'],
    ['Το set χρειάζεται αλλαγές πριν μπορέσει να προγραμματιστεί.', 'The set needs changes before it can be scheduled.'],
    ['Η Guest εμφάνισή σου είναι one-off. Η ομάδα του Deseo θα επιβεβαιώσει ξεχωριστά την ημερομηνία μετάδοσης.', 'Your Guest appearance is one-off. The Deseo team will confirm the broadcast date separately.'],
    ['Δεν έχει ανέβει ακόμη set για το επόμενο episode.', 'No set has been uploaded for the next episode yet.'],
    ['Δεν υπάρχει άλλη εβδομαδιαία μετάδοση για αυτό το slot μέσα στη Season 6, η οποία ολοκληρώνεται στις 30.05.2027.', 'There is no further weekly broadcast for this slot in Season 6, which ends on 30.05.2027.'],
    ['Ενεργοποίησε push alerts για DJ Set reminders και το ON AIR NOW του MyLive.', 'Enable push alerts for DJ Set reminders and MyLive ON AIR NOW.'],
    ['εκτιμώμενη απήχηση ·', 'estimated reach ·'],
    ['MB · έτοιμο για upload', 'MB · ready to upload'],
    ['Το set ανέβηκε.', 'The set was uploaded.'],
    ['Η σύνδεση διακόπηκε κατά το upload.', 'The connection was interrupted during the upload.'],
    ['Αντέγραψε το προσωπικό referral link σου από το πεδίο παρακάτω.', 'Copy your personal referral link from the field below.'],
    ['Use only English characters, numbers and symbols.', 'Χρησιμοποίησε μόνο αγγλικούς χαρακτήρες, αριθμούς και σύμβολα.']
  ];

  function normalizeLanguage(value) {
    return supported.indexOf(value) !== -1 ? value : 'el';
  }

  function storedLanguage() {
    try {
      return normalizeLanguage(window.localStorage.getItem(STORAGE_KEY) || 'el');
    } catch (e) {
      return 'el';
    }
  }

  function saveLanguage(value) {
    try {
      window.localStorage.setItem(STORAGE_KEY, value);
    } catch (e) {}
  }

  function directTranslate(value, target) {
    var text = String(value == null ? '' : value);
    var compact = text.replace(/\s+/g, ' ').trim();
    if (!compact) return text;

    for (var i = 0; i < pairs.length; i += 1) {
      var first = pairs[i][0];
      var second = pairs[i][1];
      if (compact === first || compact === second) {
        var firstGreek = (first.match(/[Α-ΩΆ-Ώα-ωά-ώ]/g) || []).length;
        var secondGreek = (second.match(/[Α-ΩΆ-Ώα-ωά-ώ]/g) || []).length;

        if (firstGreek === secondGreek) {
          return first;
        }

        var english = firstGreek < secondGreek ? first : second;
        var greek = firstGreek > secondGreek ? first : second;
        return target === 'en' ? english : greek;
      }
    }

    var match;

    match = compact.match(/^(Welcome,|Καλώς ήρθες,)\s*(.+)$/);
    if (match) return (target === 'en' ? 'Welcome, ' : 'Καλώς ήρθες, ') + match[2];

    match = compact.match(/^(\d+)\s+(uploaded στο MyLive|ανέβηκαν στο MyLive)$/i);
    if (match) return match[1] + (target === 'en' ? ' uploaded to MyLive' : ' ανέβηκαν στο MyLive');

    match = compact.match(/^(\d+)\s+(διαθέσιμα για download|available for download)$/i);
    if (match) return match[1] + (target === 'en' ? ' available for download' : ' διαθέσιμα για download');

    match = compact.match(/^ON AIR IN · (\d{2})D · (\d{2})H · (\d{2})M$/);
    if (match) {
      return target === 'en'
        ? compact
        : 'ΣΤΟΝ ΑΕΡΑ ΣΕ · ' + match[1] + 'ΗΜ · ' + match[2] + 'Ω · ' + match[3] + 'Λ';
    }

    match = compact.match(/^ΣΤΟΝ ΑΕΡΑ ΣΕ · (\d{2})ΗΜ · (\d{2})Ω · (\d{2})Λ$/);
    if (match) {
      return target === 'el'
        ? compact
        : 'ON AIR IN · ' + match[1] + 'D · ' + match[2] + 'H · ' + match[3] + 'M';
    }

    match = compact.match(/^ACTIVE · (\d+) DEVICE(S?)$/);
    if (match) {
      if (target === 'en') return compact;
      return 'ΕΝΕΡΓΟ · ' + match[1] + (match[1] === '1' ? ' ΣΥΣΚΕΥΗ' : ' ΣΥΣΚΕΥΕΣ');
    }

    match = compact.match(/^ΕΝΕΡΓΟ · (\d+) (ΣΥΣΚΕΥΗ|ΣΥΣΚΕΥΕΣ)$/);
    if (match) {
      if (target === 'el') return compact;
      return 'ACTIVE · ' + match[1] + ' DEVICE' + (match[1] === '1' ? '' : 'S');
    }

    match = compact.match(/^(.+?) MB · (έτοιμο για upload|ready to upload)$/i);
    if (match) return match[1] + ' MB · ' + (target === 'en' ? 'ready to upload' : 'έτοιμο για upload');

    match = compact.match(/^Το (EP\d{3}) ανέβηκε επιτυχώς\.$/i);
    if (match) return target === 'en'
      ? match[1] + ' uploaded successfully.'
      : 'Το ' + match[1] + ' ανέβηκε επιτυχώς.';

    match = compact.match(/^(EP\d{3}) uploaded successfully\.$/i);
    if (match) return target === 'el'
      ? 'Το ' + match[1] + ' ανέβηκε επιτυχώς.'
      : match[1] + ' uploaded successfully.';

    match = compact.match(/^Στάλθηκε reset link στο (.+)\. Έλεγξε και τον φάκελο Spam \/ Junk\.$/i);
    if (match) return target === 'en'
      ? 'A reset link was sent to ' + match[1] + '. Check your Spam / Junk folder too.'
      : compact;

    match = compact.match(/^A reset link was sent to (.+)\. Check your Spam \/ Junk folder too\.$/i);
    if (match) return target === 'el'
      ? 'Στάλθηκε reset link στο ' + match[1] + '. Έλεγξε και τον φάκελο Spam / Junk.'
      : compact;

    match = compact.match(/^Uploading (\d+)% · MyLive · Deseo Radio$/);
    if (match) return target === 'en'
      ? compact
      : 'Ανέβασμα ' + match[1] + '% · MyLive · Deseo Radio';

    match = compact.match(/^Ανέβασμα (\d+)% · MyLive · Deseo Radio$/);
    if (match) return target === 'el'
      ? compact
      : 'Uploading ' + match[1] + '% · MyLive · Deseo Radio';

    return text;
  }

  function translateTextNode(node, target) {
    if (!node || !node.parentElement) return;
    var tag = node.parentElement.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return;

    var raw = node.nodeValue || '';
    var match = raw.match(/^(\s*)([\s\S]*?)(\s*)$/);
    if (!match || !match[2].trim()) return;

    var translated = directTranslate(match[2], target);
    if (translated !== match[2]) {
      node.nodeValue = match[1] + translated + match[3];
    }
  }

  function translateAttributes(root, target) {
    var selector = '[title],[placeholder],[aria-label]';
    var nodes = [];
    if (root && root.nodeType === 1 && root.matches && root.matches(selector)) nodes.push(root);
    if (root && root.querySelectorAll) {
      nodes = nodes.concat(Array.prototype.slice.call(root.querySelectorAll(selector)));
    }

    nodes.forEach(function (element) {
      ['title', 'placeholder', 'aria-label'].forEach(function (name) {
        if (!element.hasAttribute(name)) return;
        var value = element.getAttribute(name);
        var translated = directTranslate(value, target);
        if (translated !== value) element.setAttribute(name, translated);
      });
    });
  }

  function translateTree(root, target) {
    if (!root) return;

    if (root.nodeType === 3) {
      translateTextNode(root, target);
      return;
    }

    if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return;

    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var node;
    while ((node = walker.nextNode())) translateTextNode(node, target);
    translateAttributes(root, target);
  }

  function updateSwitcher(target) {
    var switcher = document.querySelector('[data-mylive-language-switcher]');
    if (!switcher) return;

    var buttons = switcher.querySelectorAll('button[data-lang]');
    Array.prototype.forEach.call(buttons, function (button) {
      var active = button.getAttribute('data-lang') === target;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    switcher.setAttribute(
      'aria-label',
      target === 'en' ? 'Language selection' : 'Επιλογή γλώσσας'
    );
  }

  function applyLanguage(target) {
    target = normalizeLanguage(target);
    currentLanguage = target;
    applying = true;

    document.documentElement.lang = target;
    if (document.body) document.body.setAttribute('data-mylive-lang', target);

    translateTree(document.body || document.documentElement, target);
    updateSwitcher(target);

    var title = document.title || '';
    var translatedTitle = directTranslate(title, target);
    if (translatedTitle !== title) document.title = translatedTitle;

    applying = false;

    document.dispatchEvent(new CustomEvent('mylive:languagechange', {
      detail: { language: target }
    }));
  }

  function createSwitcher() {
    if (!document.body || document.querySelector('[data-mylive-language-switcher]')) return;

    var wrap = document.createElement('div');
    wrap.className = 'mylive-language-switcher';
    wrap.setAttribute('data-mylive-language-switcher', '');
    wrap.setAttribute('role', 'group');

    var greek = document.createElement('button');
    greek.type = 'button';
    greek.setAttribute('data-lang', 'el');
    greek.textContent = 'ΕΛ';

    var english = document.createElement('button');
    english.type = 'button';
    english.setAttribute('data-lang', 'en');
    english.textContent = 'EN';

    wrap.appendChild(greek);
    wrap.appendChild(english);

    var portalUser = document.querySelector('.portal-user');
    if (portalUser) {
      wrap.classList.add('is-inline');
      var logoutForm = portalUser.querySelector('form');
      if (logoutForm) {
        portalUser.insertBefore(wrap, logoutForm);
      } else {
        portalUser.appendChild(wrap);
      }
    } else {
      document.body.appendChild(wrap);
    }

    wrap.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('button[data-lang]') : null;
      if (!button) return;
      var next = normalizeLanguage(button.getAttribute('data-lang'));
      saveLanguage(next);
      applyLanguage(next);
    });
  }

  var observer = new MutationObserver(function (mutations) {
    if (applying) return;
    applying = true;

    mutations.forEach(function (mutation) {
      if (mutation.type === 'characterData') {
        translateTextNode(mutation.target, currentLanguage);
        return;
      }

      Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
        translateTree(node, currentLanguage);
      });
    });

    updateSwitcher(currentLanguage);
    applying = false;
  });

  window.MyLiveI18n = {
    getLanguage: function () { return currentLanguage; },
    setLanguage: function (language) {
      var next = normalizeLanguage(language);
      saveLanguage(next);
      applyLanguage(next);
    },
    t: function (value) {
      return directTranslate(value, currentLanguage);
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    currentLanguage = storedLanguage();
    createSwitcher();
    applyLanguage(currentLanguage);

    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true
    });
  });
}());

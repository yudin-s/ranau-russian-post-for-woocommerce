(function (root) {
'use strict';
var factories = {
"./checkout-bridge": function (module, exports, require) {
'use strict';

function clone(value) {
  if (value === undefined) {
    return undefined;
  }

  return JSON.parse(JSON.stringify(value));
}

function resolveBlocksUpdate(environment, configuredUpdate) {
  if (typeof configuredUpdate === 'function') {
    return configuredUpdate;
  }

  if (
    environment &&
    environment.wc &&
    environment.wc.blocksCheckout &&
    typeof environment.wc.blocksCheckout.extensionCartUpdate === 'function'
  ) {
    return environment.wc.blocksCheckout.extensionCartUpdate.bind(
      environment.wc.blocksCheckout
    );
  }

  return null;
}

function resolveClassicUpdate(environment, configuredUpdate) {
  if (typeof configuredUpdate === 'function') {
    return configuredUpdate;
  }

  if (
    environment &&
    typeof environment.jQuery === 'function' &&
    environment.document &&
    environment.document.body
  ) {
    return function triggerClassicUpdate() {
      environment.jQuery(environment.document.body).trigger('update_checkout');
    };
  }

  return null;
}

function createCheckoutBridge(options) {
  const settings = options || {};
  const machine = settings.machine;
  const namespace = String(settings.namespace || '').trim();
  const environment = settings.environment || (
    typeof window !== 'undefined' ? window : null
  );
  const serialize = typeof settings.serialize === 'function'
    ? settings.serialize
    : function defaultSerialize(context) {
      return {
        rate_id: context.state.rateId,
        context_key: context.state.contextKey,
        package_fingerprint: context.packageFingerprint,
        commit_token: context.commitToken,
        revision: context.state.revision,
        request_id: context.token.requestId,
      };
    };

  if (!machine || typeof machine.commit !== 'function' || typeof machine.getState !== 'function') {
    throw new Error('A Ranau delivery state machine instance is required.');
  }

  if (!namespace) {
    throw new Error('A provider-owned Store API namespace is required.');
  }

  async function refreshCheckout(data) {
    const blocksUpdate = resolveBlocksUpdate(environment, settings.blocksUpdate);
    const classicUpdate = resolveClassicUpdate(environment, settings.classicUpdate);

    if (blocksUpdate) {
      await Promise.resolve(blocksUpdate({
        namespace,
        data: clone(data || {}),
      }));

      return 'blocks';
    }

    if (classicUpdate) {
      await Promise.resolve(classicUpdate());
      return 'classic';
    }

    throw new Error('checkout_refresh_unavailable');
  }

  async function commitCalculation(input) {
    const context = input || {};
    const selectedRateId = String(context.selectedRateId || '').trim();

    if (selectedRateId !== machine.rateId) {
      const state = machine.getState();

      if (
        context.token &&
        context.token.requestId &&
        context.token.requestId === state.activeRequestId
      ) {
        machine.invalidate('inactive_provider');
      }

      return {
        accepted: false,
        checkoutUpdated: false,
        transport: 'none',
        reason: 'inactive_provider',
        state: machine.getState(),
      };
    }

    const committed = machine.commit(context.token, context.quote);

    if (!committed.accepted) {
      return {
        accepted: false,
        checkoutUpdated: false,
        transport: 'none',
        reason: 'stale_response',
        state: committed.state,
      };
    }

    try {
      const data = context.data === undefined
        ? serialize({
          quote: clone(context.quote),
          selection: clone(committed.state.selection),
          state: clone(committed.state),
          token: clone(context.token),
          packageFingerprint: String(context.packageFingerprint || ''),
          commitToken: String(context.commitToken || ''),
        })
        : context.data;
      const transport = await refreshCheckout(data);

      return {
        accepted: true,
        checkoutUpdated: true,
        transport,
        reason: '',
        state: machine.getState(),
      };
    } catch (error) {
      machine.invalidate('checkout_update_failed');

      return {
        accepted: false,
        calculationAccepted: true,
        checkoutUpdated: false,
        transport: resolveBlocksUpdate(environment, settings.blocksUpdate)
          ? 'blocks'
          : 'classic',
        reason: 'checkout_update_failed',
        error,
        state: machine.getState(),
      };
    }
  }

  return Object.freeze({
    namespace,
    commitCalculation,
  });
}

module.exports = {
  createCheckoutBridge,
};

},
"./index": function (module, exports, require) {
'use strict';

const PHASES = Object.freeze([
  'inactive',
  'available',
  'draft',
  'calculating',
  'committed',
  'error',
  'stale',
]);

function clone(value) {
  if (value === undefined) {
    return undefined;
  }

  return JSON.parse(JSON.stringify(value));
}

function normalizePart(value) {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof value === 'object') {
    const keys = Object.keys(value).sort();
    const normalized = {};

    keys.forEach((key) => {
      normalized[key] = normalizePart(value[key]);
    });

    return JSON.stringify(normalized);
  }

  return String(value).trim().replace(/\s+/g, ' ').toLowerCase();
}

function contextKey(context) {
  const value = context || {};

  return [
    normalizePart(value.country || 'RU'),
    normalizePart(value.region),
    normalizePart(value.city),
    normalizePart(value.postcode),
    normalizePart(value.packageFingerprint),
  ].join('|');
}

function createDeliveryStateMachine(options) {
  const settings = options || {};
  const rateId = String(settings.rateId || '').trim();
  const listeners = [];
  let requestSequence = 0;
  let state;

  if (!rateId) {
    throw new Error('A full WooCommerce shipping rate id is required.');
  }

  state = {
    version: 1,
    rateId,
    phase: 'inactive',
    revision: 0,
    contextKey: '',
    selection: null,
    quote: null,
    error: null,
    activeRequestId: '',
    reason: '',
  };

  function snapshot() {
    return clone(state);
  }

  function notify(action) {
    const current = snapshot();

    listeners.slice().forEach((listener) => {
      listener(current, clone(action));
    });
  }

  function mutate(action, reducer) {
    const previous = JSON.stringify(state);

    reducer();
    if (JSON.stringify(state) === previous) {
      return snapshot();
    }

    notify(action);
    return snapshot();
  }

  function setContext(context) {
    const nextKey = contextKey(context);

    return mutate({type: 'CONTEXT_CHANGED', contextKey: nextKey}, () => {
      if (nextKey === state.contextKey) {
        return;
      }

      const hadProviderState = state.phase !== 'inactive' && state.phase !== 'available';

      state.contextKey = nextKey;
      state.revision += 1;
      state.selection = null;
      state.quote = null;
      state.error = null;
      state.activeRequestId = '';
      state.reason = hadProviderState ? 'context_changed' : '';
      state.phase = hadProviderState ? 'stale' : 'available';
    });
  }

  function setAvailable(available, context) {
    if (available && context) {
      setContext(context);
    }

    return mutate({type: 'AVAILABILITY_CHANGED', available: Boolean(available)}, () => {
      if (!available) {
        state.phase = 'inactive';
        state.selection = null;
        state.quote = null;
        state.error = null;
        state.activeRequestId = '';
        state.reason = 'unavailable';
        return;
      }

      if (state.phase === 'inactive') {
        state.phase = 'available';
        state.reason = '';
      }
    });
  }

  function beginDraft(selection) {
    return mutate({type: 'DRAFT_STARTED'}, () => {
      if (state.phase === 'inactive') {
        return;
      }

      state.phase = 'draft';
      state.selection = clone(selection || null);
      state.quote = null;
      state.error = null;
      state.activeRequestId = '';
      state.reason = '';
    });
  }

  function beginCalculation(selection) {
    let token = null;

    mutate({type: 'CALCULATION_STARTED'}, () => {
      if (state.phase === 'inactive' || !state.contextKey) {
        return;
      }

      requestSequence += 1;
      state.phase = 'calculating';
      state.selection = clone(selection === undefined ? state.selection : selection);
      state.quote = null;
      state.error = null;
      state.activeRequestId = `${rateId}:${state.revision}:${requestSequence}`;
      state.reason = '';
      token = {
        rateId,
        revision: state.revision,
        contextKey: state.contextKey,
        requestId: state.activeRequestId,
      };
    });

    return clone(token);
  }

  function tokenIsCurrent(token) {
    return Boolean(
      token &&
      token.rateId === rateId &&
      token.revision === state.revision &&
      token.contextKey === state.contextKey &&
      token.requestId === state.activeRequestId
    );
  }

  function commit(token, quote) {
    if (!tokenIsCurrent(token)) {
      return {accepted: false, state: snapshot()};
    }

    return {
      accepted: true,
      state: mutate({type: 'CALCULATION_COMMITTED', requestId: token.requestId}, () => {
        state.phase = 'committed';
        state.quote = clone(quote || null);
        state.error = null;
        state.activeRequestId = '';
        state.reason = '';
      }),
    };
  }

  function fail(token, error) {
    if (!tokenIsCurrent(token)) {
      return {accepted: false, state: snapshot()};
    }

    return {
      accepted: true,
      state: mutate({type: 'CALCULATION_FAILED', requestId: token.requestId}, () => {
        state.phase = 'error';
        state.quote = null;
        state.error = clone(error || {message: 'Delivery calculation failed.'});
        state.activeRequestId = '';
        state.reason = 'calculation_failed';
      }),
    };
  }

  function invalidate(reason) {
    return mutate({type: 'INVALIDATED', reason: String(reason || 'invalidated')}, () => {
      if (state.phase === 'inactive') {
        return;
      }

      state.phase = 'stale';
      state.quote = null;
      state.error = null;
      state.activeRequestId = '';
      state.reason = String(reason || 'invalidated');
    });
  }

  function subscribe(listener) {
    if (typeof listener !== 'function') {
      return function noop() {};
    }

    listeners.push(listener);

    return function unsubscribe() {
      const index = listeners.indexOf(listener);
      if (index !== -1) {
        listeners.splice(index, 1);
      }
    };
  }

  return Object.freeze({
    rateId,
    phases: PHASES,
    setContext,
    setAvailable,
    beginDraft,
    beginCalculation,
    commit,
    fail,
    invalidate,
    isCommitted() {
      return state.phase === 'committed';
    },
    getState: snapshot,
    subscribe,
  });
}

module.exports = {
  PHASES,
  contextKey,
  createDeliveryStateMachine,
  createCheckoutBridge: require('./checkout-bridge').createCheckoutBridge,
};

}
};
var cache = {};
function load(id) {
  if (cache[id]) { return cache[id].exports; }
  if (!factories[id]) { throw new Error('Unknown Ranau runtime module: ' + id); }
  var module = {exports: {}};
  cache[id] = module;
  factories[id](module, module.exports, load);
  return module.exports;
}
if (Object.prototype.hasOwnProperty.call(root, "RanauRussianPostDeliveryRuntimeV1")) {
  throw new Error('Ranau runtime namespace already exists: RanauRussianPostDeliveryRuntimeV1');
}
Object.defineProperty(root, "RanauRussianPostDeliveryRuntimeV1", {
  configurable: false,
  enumerable: false,
  writable: false,
  value: Object.freeze(load('./index'))
});
}(typeof window !== 'undefined' ? window : globalThis));

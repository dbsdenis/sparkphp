(function (global) {
    function SparkRealtime(url, options) {
        this.url = url;
        this.options = options || {};
        this.storageKey = this.options.storageKey || ('spark-realtime:' + url);
        this.listeners = {};
        this.source = null;
        this.retryDelay = this.options.retryDelay || 1000;
        this.maxRetryDelay = this.options.maxRetryDelay || 10000;
    }

    SparkRealtime.prototype.connect = function () {
        var lastEventId = this.readLastEventId();
        var separator = this.url.indexOf('?') === -1 ? '?' : '&';
        var url = lastEventId ? (this.url + separator + 'last_event_id=' + encodeURIComponent(lastEventId)) : this.url;
        var self = this;

        this.source = new EventSource(url, { withCredentials: true });

        this.source.onmessage = function (event) {
            self.handleEvent('message', event);
        };

        this.source.onerror = function () {
            self.emit('error');
            self.scheduleReconnect();
        };

        return this;
    };

    SparkRealtime.prototype.on = function (event, callback) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }

        this.listeners[event].push(callback);
        return this;
    };

    SparkRealtime.prototype.close = function () {
        if (this.source) {
            this.source.close();
            this.source = null;
        }
    };

    SparkRealtime.prototype.handleEvent = function (fallbackName, event) {
        var envelope = null;

        try {
            envelope = JSON.parse(event.data);
        } catch (error) {
            this.emit('parse-error', error);
            return;
        }

        if (event.lastEventId) {
            this.writeLastEventId(event.lastEventId);
        } else if (envelope && envelope.id) {
            this.writeLastEventId(envelope.id);
        }

        this.emit('event', envelope);
        this.emit((envelope && envelope.event) || fallbackName, envelope);
    };

    SparkRealtime.prototype.scheduleReconnect = function () {
        var self = this;
        this.close();

        setTimeout(function () {
            self.connect();
        }, this.retryDelay);

        this.retryDelay = Math.min(this.retryDelay * 2, this.maxRetryDelay);
    };

    SparkRealtime.prototype.emit = function (event, payload) {
        var callbacks = this.listeners[event] || [];

        callbacks.forEach(function (callback) {
            callback(payload);
        });
    };

    SparkRealtime.prototype.readLastEventId = function () {
        try {
            return global.localStorage.getItem(this.storageKey);
        } catch (error) {
            return null;
        }
    };

    SparkRealtime.prototype.writeLastEventId = function (value) {
        try {
            global.localStorage.setItem(this.storageKey, value);
        } catch (error) {
        }
    };

    global.SparkRealtime = function (url, options) {
        return new SparkRealtime(url, options);
    };
})(window);

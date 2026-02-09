/**
 * Dynamic Asset Loader
 * 
 * Monitors the DOM for specific selectors and dynamically loads
 * registered scripts and styles when their corresponding elements are found.
 * 
 * @class EWPDynamicAssetLoader
 * @since 1.0.0
 */
class EWPDynamicAssetLoader {
    /**
     * Constructor
     * 
     * @param {Object} config Configuration object from PHP
     */
    constructor(config) {
        this.config = config || {};
        this.assets = this.config.assets || [];
        this.loadedAssets = new Set();
        this.performance = this.config.performance || {};
        // wp_localize_script converts true to "1", so check for both
        this.debug = this.config.debug === true || this.config.debug === '1' || this.config.debug === 1;

        this.log('Initializing Dynamic Asset Loader', {
            assetsCount: this.assets.length
        });
        
        this.init();
    }

    /**
     * Log debug messages when WP_DEBUG is enabled
     * 
     * @param {string} message Log message
     * @param {*} data Optional data to log
     * @return {void}
     */
    log(message, data) {
        if (!this.debug) {
            return;
        }

        if (data !== undefined) {
            console.log(`[EWP Dynamic Assets] ${message}`, data);
        } else {
            console.log(`[EWP Dynamic Assets] ${message}`);
        }
    }

    /**
     * Static log method for global use by developers
     * Checks if debug mode is enabled via global config
     * 
     * @param {string} message Log message
     * @param {*} data Optional data to log
     * @return {void}
     */
    static log(message, data) {
        const debug = typeof ewpDynamicAssets !== 'undefined' &&
            (ewpDynamicAssets.debug === true ||
                ewpDynamicAssets.debug === '1' ||
                ewpDynamicAssets.debug === 1);

        if (!debug) {
            return;
        }

        if (data !== undefined) {
            console.log(`[EWP Dynamic Assets] ${message}`, data);
        } else {
            console.log(`[EWP Dynamic Assets] ${message}`);
        }
    }

    /**
     * Initialize the loader
     * 
     * @return {void}
     */
    init() {
        if (this.assets.length === 0) {
            return;
        }

        this.loadCriticalAssets();
        this.checkDOMForAssets();
    }

    /**
     * Check DOM for registered selectors and load assets
     * 
     * @return {void}
     */
    checkDOMForAssets() {
        this.log('Checking DOM for assets');

        this.assets.forEach(asset => {
            if (this.loadedAssets.has(asset.handle)) {
                return;
            }

            if (asset.critical) {
                return;
            }

            if (this.elementExists(asset.selector)) {
                this.log(`Selector found: ${asset.selector}`, { handle: asset.handle });
                this.loadAsset(asset);
            }
        });
    }

    /**
     * Check if element exists in DOM
     * 
     * @param {string} selector CSS selector
     * @return {boolean} True if element exists
     */
    elementExists(selector) {
        try {
            return document.querySelector(selector) !== null;
        } catch (error) {
            this.log('Invalid selector', { selector, error });
            return false;
        }
    }

    /**
     * Load asset (script or style)
     * 
     * @param {Object} asset Asset configuration
     * @return {void}
     */
    loadAsset(asset) {
        if (this.loadedAssets.has(asset.handle)) {
            return;
        }

        this.log(`Loading asset: ${asset.handle}`, { type: asset.type, src: asset.src });

        if (asset.type === 'script') {
            this.loadScript(asset);
        } else if (asset.type === 'style') {
            this.loadStyle(asset);
        }

        this.loadedAssets.add(asset.handle);
        
        this.dispatchLoadEvent(asset);
    }

    /**
     * Load script dynamically
     * 
     * @param {Object} asset Script configuration
     * @return {void}
     */
    loadScript(asset) {
        this.loadDependencies(asset.dependencies || []).then(() => {
            const script = document.createElement('script');
            script.id = asset.handle;
            script.src = asset.src;

            if (asset.module === true) {
                script.type = 'module';
            }
            
            if (asset.version) {
                script.src += (asset.src.includes('?') ? '&' : '?') + 'ver=' + asset.version;
            }

            if (asset.async) {
                script.async = true;
            }
            
            if (asset.defer && !asset.async) {
                script.defer = true;
            }

            if (asset.critical) {
                script.setAttribute('data-critical', 'true');
            }

            // Localize BEFORE appending script so data is available when script executes
            if (asset.localize) {
                this.localizeScript(asset.localize);
                this.log('Script localized:', asset.localize);
            }

            script.onload = () => {
                this.log(`Script loaded: ${asset.handle}`);
                this.markPerformance(asset.handle, 'loaded');
                this.dispatchAssetLoadedEvent(asset, true);
            };

            script.onerror = () => {
                this.log(`Script load failed: ${asset.handle}`, { src: asset.src });
                this.loadedAssets.delete(asset.handle);
                this.dispatchAssetLoadedEvent(asset, false);
            };

            const target = asset.in_footer !== false ? document.body : document.head;
            this.markPerformance(asset.handle, 'start');
            target.appendChild(script);
        }).catch(error => {
            this.log('Failed to load script dependencies', { handle: asset.handle, error });
        });
    }

    /**
     * Load style dynamically
     * 
     * @param {Object} asset Style configuration
     * @return {void}
     */
    loadStyle(asset) {
        this.loadDependencies(asset.dependencies || []).then(() => {
            const link = document.createElement('link');
            link.id = asset.handle;
            link.rel = 'stylesheet';
            link.href = asset.src;
            link.media = asset.media || 'all';
            
            if (asset.version) {
                link.href += (asset.src.includes('?') ? '&' : '?') + 'ver=' + asset.version;
            }

            if (asset.critical) {
                link.setAttribute('data-critical', 'true');
            }

            link.onload = () => {
                this.log(`Style loaded: ${asset.handle}`);
                this.markPerformance(asset.handle, 'loaded');
                this.dispatchAssetLoadedEvent(asset, true);
            };

            link.onerror = () => {
                this.log(`Style load failed: ${asset.handle}`, { src: asset.src });
                this.loadedAssets.delete(asset.handle);
                this.dispatchAssetLoadedEvent(asset, false);
            };

            this.markPerformance(asset.handle, 'start');
            document.head.appendChild(link);
        }).catch(error => {
            this.log('Failed to load style dependencies', { handle: asset.handle, error });
        });
    }

    /**
     * Load dependencies recursively
     * 
     * @param {Array} dependencies Array of dependency handles
     * @return {Promise} Promise that resolves when all dependencies are loaded
     */
    loadDependencies(dependencies) {
        if (!dependencies || dependencies.length === 0) {
            return Promise.resolve();
        }

        const promises = dependencies.map(dep => {
            return new Promise((resolve, reject) => {
                if (this.isDependencyLoaded(dep)) {
                    resolve();
                    return;
                }

                const checkInterval = setInterval(() => {
                    if (this.isDependencyLoaded(dep)) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 100);

                setTimeout(() => {
                    clearInterval(checkInterval);
                    if (!this.isDependencyLoaded(dep)) {
                        this.log('Dependency timeout', { dependency: dep });
                        resolve();
                    }
                }, 5000);
            });
        });

        return Promise.all(promises);
    }

    /**
     * Check if dependency is loaded
     * 
     * @param {string} handle Dependency handle
     * @return {boolean} True if loaded
     */
    isDependencyLoaded(handle) {
        const script = document.getElementById(handle);
        if (script) {
            return true;
        }

        if (handle === 'jquery' && typeof jQuery !== 'undefined') {
            return true;
        }

        return this.loadedAssets.has(handle);
    }

    /**
     * Localize script (add global JavaScript object)
     * 
     * @param {Object} localize Localization configuration
     * @return {void}
     */
    localizeScript(localize) {
        if (!localize || !localize.objectName || !localize.data) {
            return;
        }

        try {
            window[localize.objectName] = localize.data;
        } catch (error) {
            this.log('Failed to localize script', { objectName: localize.objectName, error });
        }
    }

    /**
     * Load critical assets immediately
     * 
     * @return {void}
     */
    loadCriticalAssets() {
        const criticalAssets = this.assets.filter(a => a.critical && !this.loadedAssets.has(a.handle));
        this.log('Loading critical assets', { count: criticalAssets.length, handles: criticalAssets.map(a => a.handle) });
        
        this.assets.forEach(asset => {
            if (asset.critical && !this.loadedAssets.has(asset.handle)) {
                this.loadAsset(asset);
            }
        });
    }

    /**
     * Mark performance timing
     * 
     * @param {string} handle Asset handle
     * @param {string} mark Mark type (start or loaded)
     * @return {void}
     */
    markPerformance(handle, mark) {
        if (typeof performance === 'undefined' || !performance.mark) {
            return;
        }

        try {
            const markName = `ewp-asset-${handle}-${mark}`;
            performance.mark(markName);
            
            if (mark === 'loaded') {
                const startMark = `ewp-asset-${handle}-start`;
                const measureName = `ewp-asset-${handle}`;
                
                try {
                    performance.measure(measureName, startMark, markName);
                } catch (e) {
                    // Start mark might not exist
                }
            }
        } catch (error) {
            // Performance API might not be fully supported
        }
    }

    /**
     * Dispatch custom event when asset is loaded
     * 
     * @param {Object} asset Asset configuration
     * @param {boolean} success Whether load was successful
     * @return {void}
     */
    dispatchAssetLoadedEvent(asset, success) {
        const event = new CustomEvent('ewp_dynamic_asset_loaded', {
            detail: {
                handle: asset.handle,
                type: asset.type,
                selector: asset.selector,
                success: success
            }
        });
        
        document.dispatchEvent(event);
    }

    /**
     * Dispatch custom event when loader checks DOM
     * 
     * @param {Object} asset Asset configuration
     * @return {void}
     */
    dispatchLoadEvent(asset) {
        const event = new CustomEvent('ewp_dynamic_asset_loading', {
            detail: {
                handle: asset.handle,
                type: asset.type,
                selector: asset.selector
            }
        });
        
        document.dispatchEvent(event);
    }

    /**
     * Cleanup observers and intervals
     * 
     * @return {void}
     */
    cleanup() {
        // Cleanup method kept for compatibility
    }

    /**
     * Get loaded assets
     * 
     * @return {Set} Set of loaded asset handles
     */
    getLoadedAssets() {
        return new Set(this.loadedAssets);
    }

    /**
     * Check if specific asset is loaded
     * 
     * @param {string} handle Asset handle
     * @return {boolean} True if loaded
     */
    isAssetLoaded(handle) {
        return this.loadedAssets.has(handle);
    }

    /**
     * Manually trigger asset check
     * 
     * @return {void}
     */
    refresh() {
        this.checkDOMForAssets();
    }

    /**
     * Destroy the loader
     * 
     * @return {void}
     */
    destroy() {
        this.cleanup();
        this.assets = [];
        this.loadedAssets.clear();
    }

    /**
     * Get performance metrics for loaded assets
     * 
     * @return {Array} Array of performance entries
     */
    getPerformanceMetrics() {
        if (typeof performance === 'undefined' || !performance.getEntriesByType) {
            return [];
        }

        try {
            return performance.getEntriesByType('measure').filter(entry => 
                entry.name.startsWith('ewp-asset-')
            );
        } catch (error) {
            return [];
        }
    }

    /**
     * Public API: Manually check and load assets
     * Useful after AJAX calls or dynamic content insertion
     * 
     * @return {void}
     */
    checkAssets() {
        this.checkDOMForAssets();
    }

    /**
     * Public API: Check and load a specific asset by handle
     * 
     * @param {string} handle Asset handle to check and load
     * @return {boolean} True if asset was loaded, false otherwise
     */
    loadAssetByHandle(handle) {
        this.log(`Manual load requested: ${handle}`);
        const asset = this.assets.find(a => a.handle === handle);

        if (!asset) {
            this.log('Asset not found', { handle });
            return false;
        }

        if (this.loadedAssets.has(handle)) {
            this.log(`Asset already loaded: ${handle}`);
            return false;
        }

        if (this.elementExists(asset.selector)) {
            this.loadAsset(asset);
            return true;
        }

        this.log('Selector not found for asset', { handle, selector: asset.selector });
        return false;
    }

    /**
     * Public API: Force load an asset regardless of selector presence
     * Use with caution - bypasses DOM checking
     * 
     * @param {string} handle Asset handle to force load
     * @return {boolean} True if asset was loaded, false otherwise
     */
    forceLoadAsset(handle) {
        this.log(`Force load requested: ${handle}`);
        const asset = this.assets.find(a => a.handle === handle);

        if (!asset) {
            this.log('Asset not found', { handle });
            return false;
        }

        if (this.loadedAssets.has(handle)) {
            this.log(`Asset already loaded: ${handle}`);
            return false;
        }

        this.loadAsset(asset);
        return true;
    }

    /**
     * Public API: Get list of loaded assets
     * 
     * @return {Array} Array of loaded asset handles
     */
    getLoadedAssets() {
        return Array.from(this.loadedAssets);
    }

    /**
     * Public API: Get list of registered assets
     * 
     * @return {Array} Array of registered asset configurations
     */
    getRegisteredAssets() {
        return this.assets;
    }

    /**
     * Public API: Check if an asset is loaded
     * 
     * @param {string} handle Asset handle to check
     * @return {boolean} True if loaded, false otherwise
     */
    isAssetLoaded(handle) {
        return this.loadedAssets.has(handle);
    }
}

// Expose the class to window for static method access
window.EWPDynamicAssetLoader = EWPDynamicAssetLoader;

// Initialize the loader instance
if (typeof ewpDynamicAssets !== 'undefined') {
    window.EWPDynamicAssetLoader.instance = new EWPDynamicAssetLoader(ewpDynamicAssets);
}

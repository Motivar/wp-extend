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
        this.observer = null;
        this.intersectionObserver = null;
        this.checkInterval = null;
        this.performance = this.config.performance || {};
        this.lazyLoadEnabled = this.performance.lazyLoad !== false;
        
        this.init();
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
        this.setupMutationObserver();
        this.setupIntersectionObserver();
        this.setupPeriodicCheck();
    }

    /**
     * Check DOM for registered selectors and load assets
     * 
     * @return {void}
     */
    checkDOMForAssets() {
        this.assets.forEach(asset => {
            if (this.loadedAssets.has(asset.handle)) {
                return;
            }

            if (asset.critical) {
                return;
            }

            if (this.elementExists(asset.selector)) {
                if (asset.lazy && this.lazyLoadEnabled) {
                    this.observeElementForLazyLoad(asset);
                } else {
                    this.loadAsset(asset);
                }
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
            console.warn('EWP Dynamic Asset Loader: Invalid selector', selector, error);
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
            script.type = 'module';
            
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

            script.onload = () => {
                if (asset.localize) {
                    this.localizeScript(asset.localize);
                }
                
                this.markPerformance(asset.handle, 'loaded');
                this.dispatchAssetLoadedEvent(asset, true);
            };

            script.onerror = () => {
                console.error('EWP Dynamic Asset Loader: Failed to load script', asset.handle, asset.src);
                this.loadedAssets.delete(asset.handle);
                this.dispatchAssetLoadedEvent(asset, false);
            };

            const target = asset.in_footer !== false ? document.body : document.head;
            this.markPerformance(asset.handle, 'start');
            target.appendChild(script);
        }).catch(error => {
            console.error('EWP Dynamic Asset Loader: Failed to load dependencies for', asset.handle, error);
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
                this.markPerformance(asset.handle, 'loaded');
                this.dispatchAssetLoadedEvent(asset, true);
            };

            link.onerror = () => {
                console.error('EWP Dynamic Asset Loader: Failed to load style', asset.handle, asset.src);
                this.loadedAssets.delete(asset.handle);
                this.dispatchAssetLoadedEvent(asset, false);
            };

            this.markPerformance(asset.handle, 'start');
            document.head.appendChild(link);
        }).catch(error => {
            console.error('EWP Dynamic Asset Loader: Failed to load dependencies for', asset.handle, error);
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
                        console.warn('EWP Dynamic Asset Loader: Dependency timeout', dep);
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
            console.error('EWP Dynamic Asset Loader: Failed to localize script', localize.objectName, error);
        }
    }

    /**
     * Setup MutationObserver to watch for DOM changes
     * 
     * @return {void}
     */
    setupMutationObserver() {
        if (typeof MutationObserver === 'undefined') {
            return;
        }

        this.observer = new MutationObserver(() => {
            this.checkDOMForAssets();
        });

        this.observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    /**
     * Setup Intersection Observer for lazy loading
     * 
     * @return {void}
     */
    setupIntersectionObserver() {
        if (!this.lazyLoadEnabled || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const options = {
            root: null,
            rootMargin: this.performance.rootMargin || '50px',
            threshold: this.performance.intersectionThreshold || 0.1
        };

        this.intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const handle = entry.target.getAttribute('data-ewp-asset');
                    const asset = this.assets.find(a => a.handle === handle);
                    
                    if (asset && !this.loadedAssets.has(handle)) {
                        this.loadAsset(asset);
                        this.intersectionObserver.unobserve(entry.target);
                    }
                }
            });
        }, options);
    }

    /**
     * Observe element for lazy loading
     * 
     * @param {Object} asset Asset configuration
     * @return {void}
     */
    observeElementForLazyLoad(asset) {
        if (!this.intersectionObserver) {
            this.loadAsset(asset);
            return;
        }

        try {
            const element = document.querySelector(asset.selector);
            if (element) {
                element.setAttribute('data-ewp-asset', asset.handle);
                this.intersectionObserver.observe(element);
            }
        } catch (error) {
            console.warn('EWP Dynamic Asset Loader: Failed to observe element', asset.selector, error);
            this.loadAsset(asset);
        }
    }

    /**
     * Load critical assets immediately
     * 
     * @return {void}
     */
    loadCriticalAssets() {
        const criticalAssets = this.assets.filter(asset => asset.critical);
        
        criticalAssets.forEach(asset => {
            if (!this.loadedAssets.has(asset.handle)) {
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
     * Setup periodic check as fallback
     * 
     * @return {void}
     */
    setupPeriodicCheck() {
        this.checkInterval = setInterval(() => {
            this.checkDOMForAssets();
            
            if (this.loadedAssets.size === this.assets.length) {
                this.cleanup();
            }
        }, 1000);
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
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }

        if (this.intersectionObserver) {
            this.intersectionObserver.disconnect();
            this.intersectionObserver = null;
        }

        if (this.checkInterval) {
            clearInterval(this.checkInterval);
            this.checkInterval = null;
        }
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
     * Log performance summary to console
     * 
     * @return {void}
     */
    logPerformanceSummary() {
        const metrics = this.getPerformanceMetrics();
        
        if (metrics.length === 0) {
            console.log('EWP Dynamic Asset Loader: No performance data available');
            return;
        }

        console.group('EWP Dynamic Asset Loader - Performance Summary');
        metrics.forEach(metric => {
            const handle = metric.name.replace('ewp-asset-', '');
            console.log(`${handle}: ${metric.duration.toFixed(2)}ms`);
        });
        console.groupEnd();
    }
}

if (typeof ewpDynamicAssets !== 'undefined') {
    window.EWPDynamicAssetLoader = new EWPDynamicAssetLoader(ewpDynamicAssets);
}

/**
 * Webpack configuration for wp-extend plugin.
 *
 * Extends the default @wordpress/scripts webpack config.
 * Dynamic entries for modules + explicit global script entry.
 * Outputs to build/ with support for npm imports (e.g., slim-select).
 */
const path = require('path');
const fs = require('fs');
const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const webpack = require('webpack');
const { version } = require('./package.json');

/**
 * DataViews' internal filter popover widget, replaced by our own (which renders the original for
 * element filters and inputs for date / number / text filters). Pinned to the bundled
 * @wordpress/dataviews 4.15.4: the build fails if the file moves, so a DataViews upgrade has to
 * revisit assets/js/modules/ewp-dataviews-list/filter-widget.js (see docs/content-list-dataviews.md).
 */
const DATAVIEWS_BUILD = path.resolve(__dirname, 'node_modules/@wordpress/dataviews/build-module');
const DATAVIEWS_FILTER_WIDGET = path.join(DATAVIEWS_BUILD, 'components/dataviews-filters/search-widget.js');
if (!fs.existsSync(DATAVIEWS_FILTER_WIDGET)) {
  throw new Error('DataViews filter widget not found at ' + DATAVIEWS_FILTER_WIDGET + ': check the @wordpress/dataviews version (filter-widget.js replaces it).');
}

/**
 * Webpack plugin that emits build/version.php during every build.
 * Allows PHP to read the asset version from a single source of truth (package.json).
 */
class EmitVersionPhpPlugin {
  apply(compiler) {
    compiler.hooks.emit.tapAsync('EmitVersionPhpPlugin', (compilation, callback) => {
      const content = `<?php return '${version}';`;
      const buffer = Buffer.from(content, 'utf8');

      compilation.assets['version.php'] = {
        source: () => buffer,
        size: () => buffer.length,
      };

      callback();
    });
  }
}

/**
 * Build an entries object by scanning a folder.
 * Adds every .js file as: '<prefix>/<basename>': absolutePath
 * 
 * @param {string} folder - Relative folder path from project root
 * @param {string} prefix - Entry key prefix (e.g., 'modules', 'global')
 * @returns {Object} Entries object for webpack
 */
const buildEntries = (folder, prefix) => {
 const dir = path.resolve(__dirname, folder);
 return fs.readdirSync(dir)
  .filter(file => file.endsWith('.js'))
  .reduce((entries, file) => {
   const name = path.basename(file, '.js');
   entries[`${prefix}/${name}`] = path.join(dir, file);
   return entries;
  }, {});
};

module.exports = {
 ...defaultConfig,
  plugins: [
    ...(defaultConfig.plugins || []),
    new EmitVersionPhpPlugin(),
    // Swap DataViews' filter popover widget for ours (the original is imported back with `?ewp-original`).
    new webpack.NormalModuleReplacementPlugin(
      /[\\/]@wordpress[\\/]dataviews[\\/]build-module[\\/]components[\\/]dataviews-filters[\\/]search-widget\.js$/,
      path.resolve(__dirname, 'assets/js/modules/ewp-dataviews-list/filter-widget.js')
    ),
  ],
 entry: {
  index: path.resolve(__dirname, 'src/index.js'),
  ...buildEntries('assets/js/modules', 'modules'),
  'global/awm-global-script': path.resolve(__dirname, 'assets/js/global/awm-global-script.js'),
   'admin/awm-admin-script': path.resolve(__dirname, 'assets/js/admin/awm-admin-script.js'),
 },
 output: {
  ...defaultConfig.output,
  path: path.resolve(__dirname, 'build'),
  filename: '[name].js',
   // Content hash in chunk names: lazily imported chunks get no ?ver= query
   // string, so a changed chunk needs a new URL to get past browser/CDN caches.
   chunkFilename: '[name].[contenthash:8].chunk.js',
   publicPath: 'auto',
 },
  resolve: {
    ...defaultConfig.resolve,
    alias: {
      ...defaultConfig.resolve?.alias,
      '@modules': path.resolve(__dirname, 'assets/js/modules'),
      // DataViews internals (not in the package's `exports`), for filter-widget.js.
      '@ewp-dataviews-build': DATAVIEWS_BUILD,
    },
  },
  optimization: {
    ...defaultConfig.optimization,
    chunkIds: 'named',
    moduleIds: 'named',
  },
};
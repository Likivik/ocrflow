/**
 * The official Nextcloud Vue build config, as the app-development docs
 * recommend. It replaces the hand-rolled Vite setup, which was legal but
 * unsupported and produced two bugs on its own:
 *
 *  - lib mode does not substitute `process.env.NODE_ENV`, so the shipped IIFE
 *    died in the browser with `ReferenceError: process is not defined` before
 *    mount(), leaving the noscript fallback as the whole panel;
 *  - its output layout did not match what Nextcloud requests, so the
 *    stylesheet was emitted to `js/` while `Util::addStyle` looked in `css/`.
 *
 * The official config emits `js/<entry>.js` and `css/<entry>.css`, which is
 * exactly the contract `Util::addScript('ocrflow', 'ocrflow-panel')` and
 * `Util::addStyle('ocrflow', 'ocrflow-panel')` rely on.
 */
const { merge } = require('webpack-merge')
const path = require('node:path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

const config = merge(webpackConfig, {
	entry: {
		panel: path.join(__dirname, 'src', 'panel.js'),
	},
})

// The shared config brings its own default `main` entry pointing at
// `src/main.js`. Merging entry objects keeps it, and a missing `src/main.js`
// fails the whole build — so drop it: this app ships one bundle that mounts
// whichever panel element it finds, deliberately not the one-entry-per-app
// layout.
delete config.entry.main

module.exports = config

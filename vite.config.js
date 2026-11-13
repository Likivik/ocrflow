import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

/**
 * One entry, bundled into `js/` and committed, so installing the app needs no
 * node and no network. The output name is fixed rather than hashed: the PHP
 * side adds it by name (`Util::addScript('ocrflow', 'ocrflow-panel')`), and a
 * hashed name would break that contract silently.
 */
export default defineConfig({
	plugins: [vue()],
	// Vite replaces `process.env.NODE_ENV` in app builds but NOT in lib mode,
	// and Vue's ESM bundler build references it everywhere, expecting the
	// consumer's bundler to define it. Without this, the shipped IIFE contains
	// a bare `process.env.NODE_ENV` and dies in the browser with
	// `ReferenceError: process is not defined` before either mount() runs,
	// leaving the noscript fallback as the whole panel.
	define: {
		'process.env.NODE_ENV': JSON.stringify('production'),
	},
	resolve: {
		alias: {
			'@': fileURLToPath(new URL('./src', import.meta.url)),
		},
	},
	build: {
		// Output root is the app dir, not `js/`: Nextcloud resolves
		// `Util::addStyle('ocrflow','ocrflow-panel')` to
		// `/apps/ocrflow/css/ocrflow-panel.css`, so the stylesheet must live in
		// `css/`. Building into `js/` shipped an unreachable stylesheet and the
		// panel rendered unstyled, with a 404 on the css.
		outDir: '.',
		emptyOutDir: false,
		cssCodeSplit: false,
		lib: {
			entry: fileURLToPath(new URL('./src/panel.js', import.meta.url)),
			name: 'OcrFlowPanel',
			formats: ['iife'],
			fileName: () => 'js/ocrflow-panel.js',
		},
		rollupOptions: {
			output: {
				assetFileNames: (asset) => (asset.name?.endsWith('.css') ? 'css/ocrflow-panel.css' : 'ocrflow-panel.[ext]'),
			},
		},
	},
})

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
	resolve: {
		alias: {
			'@': fileURLToPath(new URL('./src', import.meta.url)),
		},
	},
	build: {
		outDir: 'js',
		emptyOutDir: false,
		cssCodeSplit: false,
		lib: {
			entry: fileURLToPath(new URL('./src/panel.js', import.meta.url)),
			name: 'OcrFlowPanel',
			formats: ['iife'],
			fileName: () => 'ocrflow-panel.js',
		},
		rollupOptions: {
			output: {
				assetFileNames: (asset) => (asset.name?.endsWith('.css') ? 'ocrflow-panel.css' : 'ocrflow-panel.[ext]'),
			},
		},
	},
})

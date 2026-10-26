/**
 * Entry point. Nextcloud adds this file with `Util::addScript('ocrflow', 'ocrflow-panel')`,
 * so it must mount itself and nothing else.
 */
import { createApp } from 'vue'
import Panel from './Panel.vue'

const el = document.getElementById('ocrflow-panel')
if (el) {
	createApp(Panel).mount(el)
}

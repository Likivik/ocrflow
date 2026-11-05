/**
 * Entry point. Nextcloud adds this file with `Util::addScript('ocrflow', 'ocrflow-panel')`,
 * so it must mount itself and nothing else.
 */
import { createApp } from 'vue'
import Panel from './Panel.vue'
import AdminPanel from './AdminPanel.vue'

// One bundle, two mount points: the personal settings panel and the admin
// policy page. The same script is added on both pages; whichever element is
// present gets mounted.
const panel = document.getElementById('ocrflow-panel')
if (panel) {
	createApp(Panel).mount(panel)
}

const admin = document.getElementById('ocrflow-admin-panel')
if (admin) {
	createApp(AdminPanel).mount(admin)
}

import { createApp } from 'vue'
import CompareTray from './CompareTray.vue'

const outlet = document.getElementById('compare-tray')
if (outlet) createApp(CompareTray).mount(outlet)

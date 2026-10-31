import { createApp } from 'vue'
import WatchOptions from './WatchOptions.vue'
import { parseWatchOptions } from './watchData'

const outlet = document.getElementById('watch-options')
if (outlet) {
  const input = parseWatchOptions(outlet.dataset)
  if (input) createApp(WatchOptions, { ...input }).mount(outlet)
}

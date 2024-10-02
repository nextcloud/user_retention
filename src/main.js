/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import AdminSettings from './views/AdminSettings.vue'

Vue.prototype.t = t
Vue.prototype.OCP = OCP

export default new Vue({
	el: '#user_retention',
	render: h => h(AdminSettings),
})

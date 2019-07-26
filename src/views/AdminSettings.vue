<!--
 - @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 -
 - @author Joas Schilling <coding@schilljs.com>
 -
 - @license GNU AGPL version 3 or any later version
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
	<div id="user_retention" class="section">
		<h2>{{ t('user_retention', 'User retention') }}</h2>

		<p class="settings-hint">
			{{ t('user_retention', 'Users are deleted when they did not log into their account within the given number of days. This will also delete all files of the affected users. Please note that having the sync client running in the background does not refresh your last login time. So only explicit logins on the web interface prevent the users from being deleted.') }}
		</p>

		<div>
			<label>
				<span>{{ t('user_retention', 'User expiration:') }}</span>
				<input id="user_days" v-model="userDays" type="number"
					placeholder="180" @change="saveUserDays"> {{ t('user_retention', 'days') }}
				<em>{{ t('user_retention', '(0 to disable)') }}</em>
			</label>
		</div>

		<div v-if="guestsAppInstalled">
			<label>
				<span>{{ t('user_retention', 'Guest expiration:') }}</span>
				<input id="guest_days" v-model="guestDays" type="number"
					placeholder="180" @change="saveGuestDays"> {{ t('user_retention', 'days') }}
				<em>{{ t('user_retention', '(0 to disable)') }}</em>
			</label>
		</div>

		<div>
			<label>
				<span>{{ t('user_retention', 'Exclude groups:') }}</span>
				<multiselect v-model="excludedGroups"
					class="exclude-groups-select"
					:options="groups"
					:placeholder="t('spreed', 'Limit app usage to groups.')"
					:disabled="loading"
					:multiple="true"
					:searchable="true"
					:tag-width="60"
					:loading="loadingGroups"
					:show-no-options="false"
					:close-on-select="false"
					@search-change="searchGroup"
					@input="saveExcludedGroups" />
			</label>
		</div>
	</div>
</template>

<script>
import axios from 'nextcloud-axios'
import { Multiselect } from 'nextcloud-vue'
import _ from 'lodash'

export default {
	name: 'AllowedGroups',

	components: {
		Multiselect
	},

	data() {
		return {
			loading: false,
			loadingGroups: false,
			guestsAppInstalled: false,
			groups: [],
			excludedGroups: [],
			userDays: 0,
			guestDays: 0
		}
	},

	mounted() {
		this.loading = true

		this.userDays = OCP.InitialState.loadState('user_retention', 'user_days')
		this.guestDays = OCP.InitialState.loadState('user_retention', 'guest_days')
		this.excludedGroups = OCP.InitialState.loadState('user_retention', 'excluded_groups')
		this.guestsAppInstalled = OCP.InitialState.loadState('user_retention', 'guests_app_installed')
		this.groups = this.excludedGroups
		this.loading = false

		this.searchGroup('')
	},

	methods: {
		searchGroup: _.debounce(function(query) {
			this.loadingGroups = true
			axios.get(OC.linkToOCS(`cloud/groups?offset=0&search=${encodeURIComponent(query)}&limit=20`, 2))
				.then(res => res.data.ocs)
				.then(ocs => ocs.data.groups)
				.then(groups => {
					this.groups = _.sortedUniq(_.uniq(this.groups.concat(groups)))
				})
				.catch(err => {
					console.error('could not search groups', err)
				})
				.then(() => {
					this.loadingGroups = false
				})
		}, 500),

		saveUserDays() {
			OCP.AppConfig.setValue('user_retention', 'user_days', this.userDays)
		},

		saveGuestDays() {
			OCP.AppConfig.setValue('user_retention', 'guest_days', this.guestDays)
		},

		saveExcludedGroups() {
			this.loading = true
			this.loadingGroups = true

			OCP.AppConfig.setValue('user_retention', 'excluded_groups', JSON.stringify(this.excludedGroups), {
				success: function() {
					this.loading = false
					this.loadingGroups = false
				}.bind(this)
			})
		}
	}
}
</script>

<style lang="scss" scoped>
	div > label {
		position: relative;
	}

	label span {
		display: inline-block;
		min-width: 175px;
		padding: 8px 0;
		vertical-align: top;
	}

	.excluded-groups-settings-content {
		display: flex;
		align-items: center;

		.excluded-groups-select {
			width: 300px;
		}
		button {
			margin-left: 10px;
		}
	}
</style>

/*!
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'
import pluginCypress from 'eslint-plugin-cypress'
import { defineConfig } from 'eslint/config'

export default defineConfig([
	...recommendedJavascript,
	{
		files: ['cypress/**/*.js'],
		extends: [
			pluginCypress.configs.recommended,
		],
	},
])

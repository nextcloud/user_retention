/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { addCommands } from '@nextcloud/cypress' // eslint-disable-line

const url = Cypress.config('baseUrl').replace(/\/index.php\/?$/g, '')
Cypress.env('baseUrl', url)

addCommands()

Cypress.on('uncaught:exception', (err, runnable) => {
	if (err.message.includes('ResizeObserver loop limit exceeded')
		|| err.message.includes('ResizeObserver loop completed with undelivered notifications')) {
		return false
	}

	// we still want to ensure there are no other unexpected
	// errors, so we let them fail the test
})

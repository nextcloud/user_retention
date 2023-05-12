import { addCommands } from '@nextcloud/cypress' // eslint-disable-line

const url = Cypress.config('baseUrl').replace(/\/index.php\/?$/g, '')
Cypress.env('baseUrl', url)

addCommands()

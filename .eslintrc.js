module.exports = {
	extends: [
		'nextcloud'
	],
	rules: {
		'node/no-extraneous-import': ['error', {
			'allowModules': ['lodash']
		}]
	}
}

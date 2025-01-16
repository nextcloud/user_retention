# SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
app_name=user_retention

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
version+=main

all: dev-setup build-js-production

dev-setup: clean clean-dev npm-init

release: appstore create-tag

create-tag:
	git tag -a v$(version) -m "Tagging the $(version) release."
	git push origin v$(version)

npm-init:
	npm install

npm-update:
	npm update

dependabot: dev-setup npm-update build-js-production

build-js:
	npm run dev

build-js-production:
	npm run build

lint:
	npm run lint

lint-fix:
	npm run lint:fix

watch-js:
	npm run watch

clean:
	rm -f js/user_retention.js
	rm -f js/user_retention.js.map
	rm -rf $(build_dir)

clean-dev:
	rm -rf node_modules

appstore:
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/.git \
	--exclude=/.github \
	--exclude=/.tx \
	--exclude=/build \
	--exclude=/cypress \
	--exclude=/docs \
	--exclude=/node_modules \
	--exclude=/src \
	--exclude=/tests \
	--exclude=/vendor \
	--exclude=/vendor-bin \
	--exclude=/.eslintrc.js \
	--exclude=/.gitattributes \
	--exclude=/.gitignore \
	--exclude=/.l10nignore \
	--exclude=/.php-cs-fixer.cache \
	--exclude=/.php-cs-fixer.dist.php \
	--exclude=/babel.config.js \
	--exclude=/cypress.config.js \
	--exclude=/Makefile \
	--exclude=/npm-debug.log \
	--exclude=/psalm.xml \
	--exclude=/README.md \
	--exclude=/stylelint.config.js \
	--exclude=/webpack.js \
	$(project_dir)/  $(sign_dir)/$(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing app files…"; \
		php ../../occ integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key\
			--certificate=$(cert_dir)/$(app_name).crt\
			--path=$(sign_dir)/$(app_name); \
	fi
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing package…"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64; \
	fi

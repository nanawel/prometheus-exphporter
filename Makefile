MAKE_DIR := $(dir $(realpath $(lastword $(MAKEFILE_LIST))))
ENV_FILE != [ -f $(MAKE_DIR)/env ] && echo $(MAKE_DIR)/env || echo $(MAKE_DIR)/env.dist

include $(ENV_FILE)
export $(shell sed 's/=.*//' $(ENV_FILE))
export EXPHPORTER_DIR=$(MAKE_DIR)

.PHONY: env
env:
	env

.PHONY: checkreq
checkreq:
	php --version

.PHONY: install
install: checkreq
	which composer && composer install \
		|| { wget https://getcomposer.org/composer-stable.phar -O composer.phar && chmod +x composer.phar && ./composer.phar -n install; }
	[ -f ./conf/config.yml ] || cp ./conf/config.yml.sample ./conf/config.yml

.PHONY: install-service
install-service:
	sed "s|{{exphporter_dir}}|$(EXPHPORTER_DIR)|" ./prometheus-exphporter.service > /etc/systemd/system/prometheus-exphporter.service
	systemctl daemon-reload
	systemctl enable prometheus-exphporter.service
	systemctl start prometheus-exphporter.service

.PHONY: uninstall-service
uninstall-service:
	systemctl stop prometheus-exphporter.service || true
	systemctl disable prometheus-exphporter.service || true
	rm -f /etc/systemd/system/prometheus-exphporter.service

.PHONY: update
update:
	git pull

.PHONY: start
start:
	php -S $(LISTEN_ADDR) index.php

.PHONY: startd
startd:
	nohup php -S $(LISTEN_ADDR) index.php >> data/server.log 2>&1 &

.PHONY: kill
kill:
	kill $$(ps a | grep 'php -S $(LISTEN_ADDR)' | grep -v grep | awk '{ print $$1 }')
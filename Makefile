# einmalpost.de - Prüfläufe.
#
# make verify       Vollständiger Lauf: PHPStan, Einheitstests mit Abdeckung,
#                   Integrationstests gegen echte MariaDB, Browsertests.
#                   Bricht beim ersten Fehlschlag ab, Rückgabewert != 0.
# make verify-live  Getrennter Lauf gegen die laufende Produktion.

SHELL := /bin/bash
.NOTPARALLEL:

# --- Testdatenbank (überschreibbar per Umgebung) ---
TEST_DB       ?= einmalpost_test
TEST_SOCKET   ?= /tmp/mysql.sock
TEST_DB_USER  ?= $(USER)
TEST_DB_PASS  ?=
MARIADB       ?= mariadb
E2E_PORT      ?= 8737

## Verbindung wahlweise über Unix-Socket (lokal) oder TCP.
##
## Auf einem Entwicklungsrechner ist der Socket der kürzere Weg. In einer
## fortlaufenden Prüfung läuft die Datenbank als eigener Dienst und ist nur
## über das Netz erreichbar - dann TEST_HOST setzen.
TEST_HOST ?=
TEST_PORT ?= 3306

ifeq ($(strip $(TEST_HOST)),)
    MYSQL_CLIENT := $(MARIADB) --socket=$(TEST_SOCKET) -u $(TEST_DB_USER) $(if $(TEST_DB_PASS),-p$(TEST_DB_PASS),)
    EINMALPOST_TEST_DSN := mysql:unix_socket=$(TEST_SOCKET);dbname=$(TEST_DB);charset=utf8mb4
else
    MYSQL_CLIENT := $(MARIADB) -h $(TEST_HOST) -P $(TEST_PORT) --protocol=TCP -u $(TEST_DB_USER) $(if $(TEST_DB_PASS),-p$(TEST_DB_PASS),)
    EINMALPOST_TEST_DSN := mysql:host=$(TEST_HOST);port=$(TEST_PORT);dbname=$(TEST_DB);charset=utf8mb4
endif
EINMALPOST_TEST_DB_USER     := $(TEST_DB_USER)
EINMALPOST_TEST_DB_PASSWORD := $(TEST_DB_PASS)
EINMALPOST_TEST_RATE_MAX    := 1000
# Fester Pepper für den Testlauf. Kein Geheimnis - er steht hier.
EINMALPOST_TEST_RATE_PEPPER := VFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFRUVFQ=
EINMALPOST_CONFIG           := $(CURDIR)/build/config.test.php

export EINMALPOST_TEST_DSN
export EINMALPOST_TEST_DB_USER
export EINMALPOST_TEST_DB_PASSWORD
export EINMALPOST_TEST_RATE_MAX
export EINMALPOST_TEST_RATE_PEPPER
export EINMALPOST_CONFIG
export E2E_PORT

.PHONY: funktionsprobe verify verify-live deploy check-secrets stan unit integration e2e coverage testdb testconfig clean help

help:
	@echo "verify       - Alles der Reihe nach, Abbruch beim ersten Fehler"
	@echo "verify-live  - Kopfzeilen und Protokollierung gegen die Produktion"
	@echo "stan         - PHPStan Level 9"
	@echo "unit         - Einheitstests mit Abdeckungsmessung"
	@echo "integration  - Integrationstests gegen echte MariaDB"
	@echo "e2e          - Browsertests (Chromium, Firefox, WebKit)"
	@echo "coverage     - Abdeckung über beide PHP-Ebenen zusammen"
	@echo "deploy       - Hochspielen (läuft vorher verify und check-secrets)"
	@echo "check-secrets- Gesamte Git-Historie auf Zugangsdaten prüfen"
	@echo "testdb       - Testdatenbank frisch anlegen"

## Reihenfolge ist Absicht: das Billigste und Schnellste zuerst.
verify:
	@$(MAKE) --no-print-directory stan
	@$(MAKE) --no-print-directory unit
	@$(MAKE) --no-print-directory integration
	@$(MAKE) --no-print-directory e2e
	@echo ""
	@echo "verify: vollständig durchgelaufen."

stan:
	@echo "==> PHPStan Level 9"
	@vendor/bin/phpstan analyse --no-progress --memory-limit=512M

unit:
	@echo "==> Einheitstests mit Abdeckungsmessung"
	@vendor/bin/phpunit --testsuite unit --coverage-text --do-not-cache-result

## Die Testdatenbank wird vor jedem Lauf frisch angelegt. Keine Reste,
## keine Reihenfolgeabhängigkeit zwischen Läufen.
testdb:
	@echo "==> Testdatenbank $(TEST_DB) frisch anlegen"
	@$(MYSQL_CLIENT) -e "DROP DATABASE IF EXISTS \`$(TEST_DB)\`; CREATE DATABASE \`$(TEST_DB)\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;"
	@if [ -f db/schema.sql ]; then $(MYSQL_CLIENT) $(TEST_DB) < db/schema.sql; echo "    Schema eingespielt."; else echo "    (db/schema.sql gibt es noch nicht)"; fi
	@if [ -f db/event.sql ]; then $(MYSQL_CLIENT) $(TEST_DB) < db/event.sql; echo "    Event eingespielt."; fi
	@echo "    Event-Scheduler: $$($(MYSQL_CLIENT) -Ne 'SELECT @@event_scheduler;')" 

integration: testdb
	@echo "==> Integrationstests gegen echte MariaDB"
	@vendor/bin/phpunit --testsuite integration --do-not-cache-result

testconfig:
	@php tools/write-test-config.php build/config.test.php > /dev/null

e2e: testdb testconfig
	@echo "==> Browsertests (Chromium, Firefox, WebKit)"
	@npx playwright test --pass-with-no-tests

## Abdeckung über beide PHP-Ebenen zusammen. Nicht Teil von verify - dort
## wird, wie festgelegt, die Abdeckung der Einheitstests ausgewiesen.
coverage: testdb
	@echo "==> Abdeckung über Einheits- und Integrationstests"
	@vendor/bin/phpunit --coverage-text --do-not-cache-result

## Was NICHT auf den Server gehört. .well-known/ ist dabei kein Versehen:
## Dort legt Let's Encrypt seine Prüfdateien ab. Ausgeschlossene Pfade sind
## bei rsync zugleich vor dem Löschen geschützt - das Verzeichnis überlebt
## also auch einen Abgleich mit --delete während eines Erneuerungsfensters.
DEPLOY_AUSSCHLUSS := \
	--exclude '.git' --exclude '.well-known/' \
	--exclude 'vendor' --exclude 'node_modules' \
	--exclude 'tests' --exclude 'build' \
	--exclude 'tools/verify-live.php' --exclude 'tools/check-history.sh' \
	--exclude 'tools/write-test-config.php' --exclude 'tools/verbotene-muster.sh' \
	--exclude '.phpunit.cache' --exclude '.phpstan-cache' \
	--exclude 'test-results' --exclude 'playwright-report' \
	--exclude 'config/config.php' --exclude '.env' --exclude '.env.*' \
	--exclude 'Makefile' --exclude 'composer.*' --exclude 'package*.json' \
	--exclude 'playwright.config.js' --exclude 'phpunit.xml' --exclude 'phpstan.neon' \
	--exclude '.DS_Store'

## Wohin hochgespielt wird, steht in deploy.local.mk - nicht hier.
##
## Der vhost-Benutzer ist zugleich der FTP- und SSH-Login. In einem
## öffentlichen Repository wäre er die halbe Zugangsangabe, deshalb liegt er
## außerhalb. Vorlage: deploy.local.mk.example
-include deploy.local.mk

DEPLOY_ZIEL := $(DEPLOY_HOST):$(DEPLOY_WURZEL)/

## Gruppe der Webserver-Prozesse auf dem Ziel. Bei Plesk heißt sie psacln.
DEPLOY_GRUPPE ?= psacln

## PHP auf dem Zielserver. Bei Plesk liegt es nicht im PATH.
DEPLOY_PHP ?= /opt/plesk/php/8.3/bin/php

## Spielt den aktuellen Stand auf den Server - mit --delete.
##
## Ohne --delete bliebe jede Datei aus einer früheren Fassung im Docroot
## liegen und würde weiter ausgeliefert. VerboteneMusterTest prüft das
## Repository, nicht den Server: Ein verwaistes altes Skript mit einem
## verbotenen Muster läge dann unentdeckt in der Produktion.
##
## Vorher zeigen, was verschwinden würde. Ein Trockenlauf kostet Sekunden,
## eine versehentlich gelöschte Datei kostet mehr.
deploy: verify check-secrets
	@if [ -z "$(DEPLOY_HOST)" ]; then \
		echo "FEHLER: deploy.local.mk fehlt oder ist unvollständig."; \
		echo "        cp deploy.local.mk.example deploy.local.mk  und ausfüllen."; \
		exit 1; \
	fi
	@echo "==> Trockenlauf: was würde entfernt?"
	@rsync -az --delete --dry-run --itemize-changes $(DEPLOY_AUSSCHLUSS) ./ $(DEPLOY_ZIEL) \
		| grep '^\*deleting' || echo "    (nichts)"
	@echo "==> Hochspielen nach $(DEPLOY_ZIEL)"
	@rsync -az --delete $(DEPLOY_AUSSCHLUSS) ./ $(DEPLOY_ZIEL)
	@ssh $(DEPLOY_HOST) 'chown -R $(DEPLOY_BENUTZER):$(DEPLOY_GRUPPE) $(DEPLOY_WURZEL); \
		chmod 700 $(DEPLOY_WURZEL)/config; \
		chmod 600 $(DEPLOY_WURZEL)/config/config.php'
	@echo "==> Steht das Schema auf dem Stand des Repositorys?"
	@ssh $(DEPLOY_HOST) 'cd $(DEPLOY_WURZEL) && EINMALPOST_CONFIG=$$PWD/config/config.php \
		$(DEPLOY_PHP) tools/schema-pruefen.php' || { \
		echo ""; \
		echo "Der Deploy hat Dateien hochgespielt, aber das Schema passt nicht dazu."; \
		echo "Die Anwendung läuft damit auf einer Datenbank von gestern."; \
		exit 1; \
	}
	@echo "    Hochgespielt. Prüfen mit: make verify-live LIVE_URL=https://einmalpost.de"

## Durchsucht die GESAMTE Historie nach Zugangsdaten, nicht nur den aktuellen
## Stand. Ein "git rm" entfernt eine Datei nicht aus der Vergangenheit.
check-secrets:
	@bash tools/check-history.sh

verify-live:
	@echo "==> Prüfung gegen die Produktion"
	@if [ -z "$(LIVE_URL)" ]; then echo "FEHLER: LIVE_URL fehlt. Aufruf: make verify-live LIVE_URL=https://einmalpost.de"; exit 1; fi
	@php tools/verify-live.php "$(LIVE_URL)"
	@echo ""
	@$(MAKE) --no-print-directory funktionsprobe LIVE_URL="$(LIVE_URL)"

## Was der Server tut, nicht was er sagt: ein vollständiger Durchlauf durch
## einen echten Browser gegen die laufende Installation.
funktionsprobe:
	@echo "==> Funktionsprobe im Browser"
	@if [ -z "$(LIVE_URL)" ]; then echo "FEHLER: LIVE_URL fehlt. Aufruf: make funktionsprobe LIVE_URL=https://einmalpost.de"; exit 1; fi
	@node tools/live-funktionsprobe.mjs "$(LIVE_URL)"

clean:
	@rm -rf .phpunit.cache .phpstan-cache build coverage test-results playwright-report
	@echo "Aufgeräumt."

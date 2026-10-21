DOCKER_COMPOSE 		?= docker compose
DOCKER_COMPOSE_EXEC	?= $(DOCKER_COMPOSE) exec --user=www-data:www-data

PHP					?= $(DOCKER_COMPOSE_EXEC) app php
# As the host user: vendor/ belongs to the developer, not to www-data.
COMPOSER			?= $(DOCKER_COMPOSE) exec --user=$(shell id -u):$(shell id -g) --env COMPOSER_HOME=/tmp/composer app composer

define main_title
	@{ \
	set -e ;\
	msg="Make $@";\
	echo "\n\033[34m$$msg" ;\
	for i in $$(seq 1 $${#msg}) ; do printf '=' ; done ;\
	echo "\033[0m\n" ;\
	}
endef

##@ Project

install: ## Build, start, install dependencies and load the demo data
	make build up vendor db-update fixtures

build: ## Build the image
	$(call main_title,)
	$(DOCKER_COMPOSE) build

up: ## Start the project (http://localhost:8090)
	$(call main_title,)
	$(DOCKER_COMPOSE) up -d

down: ## Down the project
	$(call main_title,)
	$(DOCKER_COMPOSE) down --remove-orphans

stop: ## Stop the project
	$(call main_title,)
	$(DOCKER_COMPOSE) stop

terminal: ## Launch terminal
	$(call main_title,)
	$(DOCKER_COMPOSE_EXEC) app /bin/bash

##@ Dependencies

.PHONY: vendor
vendor: composer.json ## Composer install
	$(call main_title,)
	$(COMPOSER) install

##@ Database

db-update: ## Run the pending migrations
	$(call main_title,)
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

fixtures: ## Load the demo data (wipes the database)
	$(call main_title,)
	$(PHP) bin/console doctrine:fixtures:load --no-interaction --no-debug

##@ Utility

help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
.DEFAULT_GOAL := help

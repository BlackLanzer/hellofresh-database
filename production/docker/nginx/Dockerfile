# Serves the same public/ directory that was built into the app image, so the
# assets nginx serves can never drift from the PHP code that references them.
# Requires hellofresh-app:latest to exist first — see ../../README.md.
ARG APP_IMAGE=hellofresh-app:latest

FROM ${APP_IMAGE} AS app

FROM nginx:1.27-alpine

COPY --from=app /var/www/html/public /var/www/html/public

# Rendered by the official image's envsubst entrypoint, which substitutes only
# names present in the environment — nginx's own $uri and $query_string survive.
COPY production/docker/nginx/default.conf /etc/nginx/templates/default.conf.template

# Minimal nginx image. Same code tree is mounted from the app service via a
# read-only named volume so nginx can serve static files in /public directly.
FROM nginx:1.27-alpine

# Replace the default config with our FastCGI-aware one.
COPY nginx.conf /etc/nginx/conf.d/default.conf

# TLS certs come from a host bind-mount at run time (docker/certs/ on the host
# → /etc/nginx/certs in the container) so we never bake them into the image.

EXPOSE 80 443

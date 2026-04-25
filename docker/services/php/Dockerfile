FROM ghcr.io/ophelios-studio/docker-pulsar-php84:latest

# The Pulsar image ships with both 000-default.conf (DocumentRoot /var/www/html)
# and default.conf (DocumentRoot /var/www/html/public). Disable the conflicting
# default so Apache serves from /var/www/html/public as expected.
RUN a2dissite 000-default.conf 2>/dev/null || true

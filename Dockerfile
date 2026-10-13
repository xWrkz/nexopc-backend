FROM wordpress:php8.2-apache

COPY wp-content/mu-plugins /opt/nexopc-mu-plugins
COPY docker-entrypoint-nexopc.sh /usr/local/bin/docker-entrypoint-nexopc.sh

RUN chmod 755 /usr/local/bin/docker-entrypoint-nexopc.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-nexopc.sh"]
CMD ["apache2-foreground"]

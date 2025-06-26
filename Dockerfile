FROM phpswoole/swoole:php8.2

# Set timezone to GMT+8
ENV TZ=Asia/Hong_Kong
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install PHP extensions
RUN docker-php-ext-install mysqli

WORKDIR /app

COPY src/ChatServer.php /app/src/
COPY docker_config.php /app/docker_config.php

EXPOSE 9501

CMD ["php", "src/ChatServer.php"]
FROM php:8.3-fpm

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    zip \
    unzip \
    git \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Копируем composer.json и ставим зависимости (кэш слой)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>/dev/null \
    || composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Копируем всё приложение
COPY . .

# Создаём директории для кэша и логов
RUN mkdir -p /var/www/var/cache /var/www/var/log \
    && chown -R www-data:www-data /var/www

# Скрипт инициализации
COPY docker/init.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Скрипт миграций
COPY docker/migrate.sh /usr/local/bin/migrate.sh
RUN chmod +x /usr/local/bin/migrate.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

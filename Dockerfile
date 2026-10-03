# CourseShop 运行镜像
# 基础镜像：PHP 8.1 + Apache（站点根目录为 public/）
FROM php:8.1-apache

# ---------------- PHP 扩展 ----------------
# 安装向导要求：pdo_mysql / mbstring / gd / fileinfo / openssl
# 其中 fileinfo、openssl、curl 基础镜像已内置，这里补齐其余扩展
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring gd \
    && rm -rf /var/lib/apt/lists/*

# ---------------- 上传限制（视频 / 附件） ----------------
# 与 .env 的 UPLOAD_MAX_VIDEO_MB / UPLOAD_MAX_ATTACHMENT_MB 对应，如需调整请同步修改
RUN { \
        echo 'upload_max_filesize = 2048M'; \
        echo 'post_max_size = 2100M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/zz-courseshop.ini

# ---------------- Apache ----------------
# 启用重写模块，并把站点根目录指向 public/（唯一对外暴露的目录）
# AllowOverride All 用于让 public/.htaccess 中的伪静态规则生效
RUN a2enmod rewrite \
    && printf '%s\n' \
        '<VirtualHost *:80>' \
        '    DocumentRoot /var/www/html/public' \
        '' \
        '    <Directory /var/www/html/public>' \
        '        Options -Indexes' \
        '        AllowOverride All' \
        '        Require all granted' \
        '    </Directory>' \
        '' \
        '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
        '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
        '</VirtualHost>' \
        > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# ---------------- 应用代码 ----------------
COPY . /var/www/html

# ---------------- 运行目录权限 ----------------
# 安装向导需要写入 .env、storage/ 与 public/uploads/，其余代码保持只读
RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/uploads \
        storage/private/videos \
        storage/backups \
        public/uploads \
    && touch .env \
    && chown -R www-data:www-data .env storage public/uploads

EXPOSE 80

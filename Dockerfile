# Use official PHP CLI image
FROM php:8.2-cli

# Set working directory
WORKDIR /app

# Copy all files from repo
COPY . /app

# Install mysqli extension and curl
RUN docker-php-ext-install mysqli && apt-get update && apt-get install -y curl

# Command to run your test script by default
CMD ["php", "server.php"]

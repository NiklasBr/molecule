# molecule
A collection of atoms

## Deployment

The easiest way to deploy this application is using **DigitalOcean App Platform**.

### Quick Deploy to DigitalOcean

1.  **Push your code** to a GitHub repository.
2.  Go to the **DigitalOcean Control Panel**.
3.  Click **Apps** -> **Create** -> **Apps**.
4.  Connect your GitHub account and select this repository.
5.  DigitalOcean will detect the `.do/app.yaml` file and configure the app automatically.
6.  Set the required environment variables:
    *   `APP_SECRET`: A random string for Symfony security.
    *   `DATABASE_URL`: Your database connection string (if applicable).
7.  Click **Create Resources** to deploy.

### Local Development

If you are using Homebrew on macOS, you can execute PHP and Composer from their default paths:

```bash
/opt/homebrew/bin/php -v
/opt/homebrew/bin/php /opt/homebrew/bin/composer --version
```

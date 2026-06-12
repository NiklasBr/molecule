# molecule
A collection of atoms

## Deployment

This application is deployed to **GitHub Pages** using GitHub Actions.

### Automatic Updates

A GitHub workflow is configured to fetch and update feeds thrice daily. The combined feed is then deployed and served via GitHub Pages.

### Setup

1.  **Fork or clone** this repository.
2.  Enable **GitHub Pages** in your repository settings:
    *   Go to **Settings** -> **Pages**.
    *   Select **GitHub Actions** as the **Source**.
3.  The workflow will automatically run on push to `main` or according to the schedule.

### Local Development

If you are using Homebrew on macOS, you can execute PHP and Composer from their default paths:
```bash
php -v
composer --version
```

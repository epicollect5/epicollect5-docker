# Epicollect5-docker
Epicollect5 Server on Docker containers

## Installation
- Clone repo
- Epicollect5 settings can be found in `.env.example`. Copy it to `.env` and edit accordingly.
- Run `docker-compose up -d && docker-compose logs -f`
- Wait for the build to complete. By default app will be running on port 8080
- If error `deploy:unlock`, run `docker-compose exec app dep deploy:unlock` and try again

## Report Issues

- Please report any issues you encounter to the [Epicollect5 Community](https://community.epicollect.net/)

## Forking

We provide this software as is, under MIT license, for the benefit and use of the community, however we are unable to provide support for its use or modification.

You are not granted rights or licenses to the trademarks of the CGPS or any party, including without limitation the Epicollect5 name or logo. If you fork the project and publish it, please choose another name.

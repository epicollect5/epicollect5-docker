# epicollect5-docker
Epicollect5 Server via Docker

- Clone repo and run `docker-compose up -d && docker-compose logs -f`
- Wait for the build to complete
- If error `deploy:unlock`, run `docker-compose exec app dep deploy:unlock` and try again
- Epicollect5 settings can be found in `.env`

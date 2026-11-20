# System Requirements and Setup
The KLMS environment consists of two applications:
The [IDM Server](https://github.com/KRRUg/IDM) to store your user-data in a central location
and one KLMS instance for each of your events.

:point_right: For production setup, we will provide a docker setup shortly. If you need support right now, please contact us.

## System requirements
Recommended and tested are a Linux Server running Apache and the following versions:
 * PHP 8.3 or newer (8.x)
 * PostgreSQL 9 or 12
 * [Composer 2](https://getcomposer.org/download/)

For the build process the following tools are required (they are not required to run on the server):
 * Node.js 19
 * [Yarn v1](https://classic.yarnpkg.com/lang/en/docs/install/)

For development, no Apache webserver is required (see below).

## Production Setup
Assuming you have a running and configured Apache webserver with PHP 8.3 or newer (8.x) and PostgreSQL database.
For instructions on how to configure apache to serve a symfony application, please consult the
[Symfony documentation](https://symfony.com/doc/5.4/setup/web_server_configuration.html).
Make sure that all required PHP extensions (`ctype`, `dom` `gd`, `iconv`,`json`,`pgsql`,`libxml`, and `intl`) are installed and enabled.

We also assume that there is a running [IDM Server](https://github.com/KRRUg/IDM) instance
(usually listening on localhost on the local server).

### Database setup
Login as the PostgreSQL admin user (usually `postgres`) and create a user
with an according password and create a database for the KLMS instance.

Running Linux and logged on as root, the following commands perform this actions:
```
sudo -u postgres -i
createuser -l -P <db_user>
createdb -O <db_user> <db_name>
exit
``` 

### KLMS setup
This guide follows loosely the [Symfony deployment instructions](https://symfony.com/doc/5.4/deployment.html).

First, clone the KLMS source code in the web folder (e.g. `/var/www/klms`).

Then create the local env file to tell the framework the database and IDM connections.
Create a file `.env.local` in the project's main directory with the following content:
```
APP_ENV=prod
APP_SECRET=<some random string>

DATABASE_URL=postgresql://<db_user>:<db_pw>@<db_ip>:<db_port>/<db_name>?serverVersion=12&charset=utf8
KLMS_IDM_URL=https://<idm_host>:<idm_port>
KLMS_IDM_APIKEY=<idm_key>

SITE_BASE_SCHEME=https
SITE_BASE_HOST=<your_url>:<your_port>
```

There are two optional config steps to enable email sending and/or Recaptcha protection for your registration page. 
```
MAILER_DSN=smtp://<user>:<passwd>@<mailserver>:587
MAILER_DEFAULT_SENDER_NAME='<name of your event>'
MAILER_DEFAULT_SENDER_EMAIL=noreply@<yourdomain.com>

RECAPTCHA3_SECRET=<your-recaptcha-v3-secret>
RECAPTCHA3_KEY=<your-recaptcha-v3-site-key>
RECAPTCHA3_ENABLED=1
```

To set up the required third party libraries go to the project directory and run
```shell
composer install --no-dev
composer dump-autoload --no-dev --classmap-authoritative
``` 

Next, build the front-end. This is the only step that requires node and can be done on a different machine.
(In case you ran this on a different machine, copy the generated `public/build` folder to same location on the server)
```shell
yarn install
yarn encore prod
```

To create the database schema and finalize the installation, run in the following commands in your KLMS source dir (`bin/console` is a PHP script provided by KLMS):
```
bin/console doctrine:schema:create
bin/console cache:clear
```

### SumUp payments
To enable SumUp payments, configure `lan.signup.payment_sumupapikey` with a SumUp API key and `lan.signup.payment_sumupmerchantid` with the merchant code in the administration settings, then enable `lan.signup.payment_sumupenabled`. The merchant code is not the merchant's email address. Existing client ID and client secret settings are no longer used and are not converted automatically.

The API key is stored in the settings database and is visible to users who can manage settings. Restrict access accordingly. Verify checkout creation, payment verification and cancellation with a SumUp test account before enabling payments in production.

If creating a checkout succeeds but saving its ID fails, the checkout may still exist at SumUp. Check and reconcile it before retrying the order.

### MCP server
The MCP HTTP endpoint is available at `/mcp`. The project pins the experimental Symfony MCP Bundle to `1.0.0-BETA1`. Its DNS-rebinding protection permits localhost and the hostname configured in `SITE_BASE_HOST` (without its optional port). If the endpoint is accessed through another hostname, add it to `http.allowed_hosts` in `config/packages/mcp.yaml`.

The server provides `content_list`, `content_get`, `content_create`, `content_update`, `news_list`, `news_get`, `news_create` and `news_update`, as well as the spike tool `ping`. The content and news tools require `ADMIN_CONTENT` and `ADMIN_NEWS`, respectively. Updates leave omitted fields unchanged; `null` clears optional fields or the HTML body, but not the required title. News dates require ISO 8601 with a timezone and are converted to the app timezone and stored to the minute. News images are read-only; image upload and deletion are not available.

Content HTML allows scripts: a token with content permission grants control over page HTML. Use short-lived tokens and protect them accordingly.

#### Deployment on an existing installation

Back up the database and review the proposed schema changes before applying them. The schema update affects the entire application schema, not just MCP. In the production environment, from the KLMS project directory:

```shell
composer install --no-dev --no-interaction --optimize-autoloader
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:schema:update --dump-sql
# After reviewing the SQL:
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:schema:update --force
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
```

For a new installation, `doctrine:schema:create` above includes the token table. The upgrade uses schema update, not migrations.

Expose the endpoint over HTTPS, and ensure the web server and any reverse proxy forward the `Authorization` header to PHP. With Apache and PHP-FPM/FastCGI, check whether an explicit `SetEnvIfNoCase` rule is needed; see the [Symfony Apache configuration](https://symfony.com/doc/7.4/setup/web_server_configuration.html#apache). Configure trusted proxies correctly when TLS is terminated upstream. Do not log authorization headers or token values.

The bundle's default MCP session store uses files under `var/cache/prod/mcp-sessions/default`; PHP must be able to write there. Clearing the cache can invalidate active MCP sessions, so clients may need to reconnect. For multiple application hosts, configure a session store shared by all hosts or ensure requests for a session reach the same host.

#### API bearer tokens

Tokens are linked to an IDM user; only a hash of the secret is stored in the KLMS database. Create a separate, short-lived token for each client. The value is printed only once; store it securely and never commit it:

```shell
php bin/console app:api-token:create user@example.org --name="VS Code" --expires=30
php bin/console app:api-token:list user@example.org
php bin/console app:api-token:revoke <id>
```

Run these commands in the intended environment, e.g. with `APP_ENV=prod APP_DEBUG=0` for production. The list command shows the ID needed for revocation, but not the secret. `--expires` is the lifetime in days. The MCP firewall authenticates every request and loads the user's current permissions; token revocation or permission changes therefore take effect on subsequent requests.

#### VS Code

For the Copilot harness, add the following to the personal `~/.copilot/mcp-config.json` (or `$COPILOT_HOME/mcp-config.json`), or merge it into the workspace-root `.mcp.json`:

```json
{
  "mcpServers": {
    "klms": {
      "type": "http",
      "url": "https://example.org/mcp",
      "headers": {
        "Authorization": "Bearer ${KLMS_MCP_TOKEN}"
      },
      "tools": ["*"]
    }
  }
}
```

Replace the URL and provide `KLMS_MCP_TOKEN` in the environment where the Copilot harness runs, including on a remote host or in a container. Do not put the token itself in the JSON file. Restart the relevant process after changing its environment. Environment variables are not a dedicated secret store; restrict access to the execution environment.

For the older editor-managed Local harness, an alternative is `.vscode/mcp.json` with an input prompt:

```json
{
  "inputs": [
    {
      "type": "promptString",
      "id": "klms-token",
      "description": "KLMS MCP bearer token",
      "password": true
    }
  ],
  "servers": {
    "klms": {
      "type": "http",
      "url": "https://example.org/mcp",
      "headers": {
        "Authorization": "Bearer ${input:klms-token}"
      }
    }
  }
}
```

VS Code prompts for the token when starting the server. This editor-managed format is deprecated and input prompts are not forwarded to the Copilot harness; use the native configuration above for Copilot. See the [VS Code MCP configuration reference](https://code.visualstudio.com/docs/agents/reference/mcp-configuration).

#### Claude Desktop

Install Node.js 18 or newer, with `npx` available to Claude Desktop. In Claude Desktop, open **Settings > Developer > Edit Config** and merge this entry into `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "klms": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote@0.16.7",
        "https://example.org/mcp",
        "--transport",
        "http-only",
        "--header-file",
        "C:\\Users\\your-user\\klms-mcp-headers.txt"
      ]
    }
  }
}
```

Replace the URL and use an absolute header-file path appropriate to your system. Create the header file outside the repository, restrict it to your user account (for example `chmod 600` on Unix), and add exactly this line with your token substituted:

```text
Authorization: Bearer <YOUR_TOKEN>
```

The file contains a secret: do not commit or share it. The header-file option keeps the token out of command-line arguments. Fully quit and restart Claude Desktop after editing the configuration. See the [mcp-remote documentation](https://github.com/geelen/mcp-remote#custom-headers) and [Claude Desktop setup guide](https://modelcontextprotocol.io/docs/develop/connect-local-servers).

#### Connection check

Start the configured server in the client and call `ping`; it should return `pong`. If authentication returns 401, check the token, its expiry and revocation, and header forwarding. A 403 can indicate missing admin rights or a disallowed host or origin. A tool-level permission error means the user lacks that tool's specific role. Check the client and server logs without exposing credentials.

Open your site's URL in your browser. You should see an empty KLMS instance.
To start configuring your site, log in with a super-admin account of your IDM instance to get all permissions on the KLMS instance at once.

To send emails and perform maintenance tasks, KLMS requires the PHP script `bin/console messenger:consume` to run on the server.
We recommend to use supervisor to ensure the process is restarted whenever it ends.

## Development Setup
Follow the [Symfony setup guidelines](https://symfony.com/doc/5.4/setup.html) and install the symfony binary.

When running `symfony check:requirements` ensure that no errors nor warnings are displayed.
In addition to the basic Symfony setup, install: PostgreSQL server, Node.js, and Yarn.

Follow the setup steps for a production setup (as shown above) without the `--no-dev` arguments.
No apache setup is required, as Symfony provides a development server. 

To fill the database, with test-data, run
```
bin/console doctrine:fixtures:load
```

Once all setup steps are done start the Symfony development server using
```
symfony server:start --port=8000 --no-interaction --no-tls
```
Open the printed URL in your browser and log in with a super-admin credential
(in case have been following the IDM development setup, those are `admin@admin.local` with password `admin`).

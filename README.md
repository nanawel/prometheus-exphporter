Prometheus Exphporter
===

## Installation

1. Clone project:
```shell
git clone https://nanawel-gitlab.dyndns.org/anael/prometheus-exphporter.git
# or git clone git@nanawel-gitlab.dyndns.org:anael/prometheus-exphporter.git
cd prometheus-exphporter
```

2. Initialize configuration file

This command will install dependencies and create a default `config.yml` in
`./conf/`:
```shell
make install
```

## Configuration

You may copy `env.dist` to `env` in order to tune server settings.

## Usage

### With embedded PHP server, as root (RECOMMENDED)

```
make startd
```

> `root` user is needed for some collectors. Running with an unprivileged user
> might prevent those collectors from working correctly.  
> As this application does not process any user input nor write in critical
> directories, it can be considered as safe even though running a PHP script
> as `root` usually is not.

You can then stop the server with `make kill`.

### (Alternative) With Apache 2.x**

Create a dedicated VirtualHost on the port of your choice and use this folder
as `DocumentRoot`.

> Note: `mod_rewrite` must be enabled and usable.
> 
> Note bis: As the script won't be run as `root`, some collectors might not
> work correctly (or work at all).
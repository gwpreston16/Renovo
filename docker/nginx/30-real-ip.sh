#!/bin/sh
# Writes nginx's real-IP settings from WEB_TRUSTED_PROXIES, before nginx starts
# (the official image runs every executable *.sh in /docker-entrypoint.d/).
#
# WEB_TRUSTED_PROXIES is a comma- or space-separated list of the addresses or
# CIDR ranges of the reverse proxies in front of this container. nginx then
# believes X-Forwarded-For only from those, and hands PHP the client address in
# REMOTE_ADDR. Empty (the default) trusts no header: REMOTE_ADDR stays the
# address that connected, which is the proxy's.
set -eu

out="${REAL_IP_CONF:-/etc/nginx/conf.d/real-ip.conf}"
list=$(printf '%s' "${WEB_TRUSTED_PROXIES:-}" | tr ',' ' ')

if [ -z "$(printf '%s' "$list" | tr -d ' \t\n')" ]; then
    printf '# WEB_TRUSTED_PROXIES is empty: no forwarded address is trusted.\n' > "$out"
    exit 0
fi

conf=""
for proxy in $list; do
    # An address or a CIDR range, nothing else: the value lands in a config file.
    case "$proxy" in
        *[!0-9A-Fa-f:./]*|'')
            printf 'WEB_TRUSTED_PROXIES: "%s" is not an IP address or CIDR range.\n' "$proxy" >&2
            exit 1
            ;;
    esac
    conf="${conf}set_real_ip_from ${proxy};
"
done

printf '%sreal_ip_header X-Forwarded-For;\nreal_ip_recursive on;\n' "$conf" > "$out"

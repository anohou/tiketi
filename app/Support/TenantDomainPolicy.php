<?php

namespace App\Support;

class TenantDomainPolicy
{
    public const RESERVED_MESSAGE = "Ce sous-domaine n'est pas disponible.";

    private const PLATFORM_DOMAIN = 'tiketi.ci';

    private const RESERVED_LABELS = [
        'account',
        'accounts',
        'admin',
        'administrator',
        'agency',
        'agencies',
        'android',
        'api',
        'app',
        'apps',
        'apex',
        'asset',
        'assets',
        'auth',
        'beta',
        'billing',
        'blog',
        'booking',
        'bookings',
        'bus',
        'cache',
        'cdn',
        'cert',
        'certificate',
        'certificates',
        'certs',
        'checkout',
        'company',
        'companies',
        'console',
        'contact',
        'control',
        'dashboard',
        'database',
        'db',
        'demo',
        'dev',
        'development',
        'docs',
        'documentation',
        'download',
        'downloads',
        'driver',
        'drivers',
        'email',
        'faq',
        'feedback',
        'file',
        'files',
        'fleet',
        'ftp',
        'gateway',
        'grafana',
        'gw',
        'health',
        'healthcheck',
        'help',
        'helpdesk',
        'home',
        'iam',
        'id',
        'identity',
        'imap',
        'images',
        'img',
        'internal',
        'invoice',
        'invoices',
        'ios',
        'jobs',
        'kb',
        'kibana',
        'knowledgebase',
        'landing',
        'live',
        'local',
        'localhost',
        'log',
        'logs',
        'login',
        'logout',
        'mail',
        'main',
        'manage',
        'manager',
        'marketplace',
        'media',
        'metrics',
        'minio',
        'mobile',
        'monitor',
        'monitoring',
        'mx',
        'mysql',
        'news',
        'newsletter',
        'node',
        'nodes',
        'notifications',
        'notify',
        'oauth',
        'operator',
        'operators',
        'org',
        'orgs',
        'origin',
        'payment',
        'payments',
        'pgadmin',
        'phpmyadmin',
        'ping',
        'plans',
        'portal',
        'portainer',
        'pop',
        'pop3',
        'postgres',
        'postgresql',
        'preview',
        'pricing',
        'private',
        'prod',
        'production',
        'prometheus',
        'proxy',
        'pwa',
        'qa',
        'queue',
        'redis',
        'register',
        'root',
        'route',
        'router',
        'routes',
        'sandbox',
        'scheduler',
        'secure',
        'security',
        'sentry',
        'server',
        'shop',
        'signin',
        'signup',
        'smtp',
        'sso',
        'ssl',
        'stage',
        'staging',
        'static',
        'status',
        'storage',
        'subscription',
        'subscriptions',
        'support',
        'system',
        't',
        'tenant',
        'tenants',
        'test',
        'testing',
        'ticket',
        'tickets',
        'tls',
        'trace',
        'traces',
        'traefik',
        'transport',
        'trip',
        'trips',
        'uat',
        'undefined',
        'unknown',
        'upload',
        'uploads',
        'uptime',
        'vehicle',
        'vehicles',
        'vpn',
        'web',
        'webmail',
        'workspace',
        'workspaces',
        'worker',
        'workers',
        'www',
    ];

    private const RESERVED_PREFIXES = [
        'admin-',
        'api-',
        'auth-',
        'internal-',
        'dev-',
        'stg-',
        'stage-',
        'test-',
        'demo-',
        'preview-',
        'prod-',
        'www-',
        'mail-',
        'smtp-',
    ];

    public static function normalize(mixed $value): string
    {
        $domain = strtolower(trim((string) $value));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = preg_replace('~[/?#].*$~', '', $domain) ?? $domain;

        return trim($domain, " \t\n\r\0\x0B.");
    }

    public static function isReservedTiketiDomain(mixed $value): bool
    {
        $domain = self::normalize($value);

        if ($domain === self::PLATFORM_DOMAIN) {
            return true;
        }

        $suffix = '.'.self::PLATFORM_DOMAIN;

        if (! str_ends_with($domain, $suffix)) {
            return false;
        }

        $label = substr($domain, 0, -strlen($suffix));
        $label = explode('.', $label)[0] ?? '';

        if (in_array($label, self::RESERVED_LABELS, true)) {
            return true;
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function toFrontendArray(): array
    {
        return [
            'platformDomain' => self::PLATFORM_DOMAIN,
            'reservedLabels' => self::RESERVED_LABELS,
            'reservedPrefixes' => self::RESERVED_PREFIXES,
            'reservedMessage' => self::RESERVED_MESSAGE,
        ];
    }
}

<?php

namespace Tests\Unit;

use App\Rules\AllowedTenantDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllowedTenantDomainTest extends TestCase
{
    #[DataProvider('reservedTiketiDomains')]
    public function test_reserved_tiketi_domains_are_rejected(string $domain): void
    {
        $this->assertFalse($this->passes($domain));
    }

    #[DataProvider('allowedDomains')]
    public function test_allowed_domains_pass(string $domain): void
    {
        $this->assertTrue($this->passes($domain));
    }

    public function test_normalizes_domain_before_storage(): void
    {
        $this->assertSame('admin.tiketi.ci', AllowedTenantDomain::normalize(' https://Admin.Tiketi.CI/path?x=1 '));
    }

    public static function reservedTiketiDomains(): array
    {
        return [
            'reserved label' => ['admin.tiketi.ci'],
            'mixed case label' => ['Admin.Tiketi.CI'],
            'protocol input' => ['https://admin.tiketi.ci'],
            'reserved prefix' => ['admin-demo.tiketi.ci'],
            'platform apex' => ['tiketi.ci'],
        ];
    }

    public static function allowedDomains(): array
    {
        return [
            'tenant subdomain' => ['alpha.tiketi.ci'],
            'custom domain with reserved first label' => ['admin.company.com'],
        ];
    }

    private function passes(string $domain): bool
    {
        $passes = true;

        (new AllowedTenantDomain)->validate('domain', $domain, function () use (&$passes) {
            $passes = false;
        });

        return $passes;
    }
}
